<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use App\Models\PaymentSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ShamCashSettingController extends ApiController
{
    public function show(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);

        return $this->success($this->payload(PaymentSetting::findOrFail(1)));
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        $validated = $request->validate([
            'account_id' => ['required', 'string', 'max:255', 'regex:/\S/u'],
            'qr_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=4096,max_height=4096'],
        ]);
        $path = $request->hasFile('qr_image') ? $request->file('qr_image')->store('payment-qr', 'local') : null;
        abort_if($request->hasFile('qr_image') && ! $path, 500, 'Unable to store QR image.');
        try {
            $settings = DB::transaction(function () use ($request, $validated, $path) {
                $settings = PaymentSetting::lockForUpdate()->findOrFail(1);
                $oldPath = $settings->qr_path;
                $settings->update(['account_id' => trim($validated['account_id']), 'qr_path' => $path ?? $oldPath]);
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'payment_settings.updated',
                    'entity_type' => PaymentSetting::class, 'entity_id' => 1,
                    'metadata_json' => ['qr_changed' => (bool) $path], 'ip' => $request->ip(), 'created_at' => now()]);

                return $settings;
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('local')->delete($path);
            }
            throw $error;
        }
        // Keep previous QR versions: existing checkouts retain their original destination.

        return $this->success($this->payload($settings));
    }

    public function qr(Request $request)
    {
        abort_unless($request->user()->status === 'active', 403);
        $settings = PaymentSetting::findOrFail(1);
        abort_unless($settings->qr_path && Storage::disk('local')->exists($settings->qr_path), 404);

        return Storage::disk('local')->response($settings->qr_path, null, ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'no-store']);
    }

    private function payload(PaymentSetting $settings): array
    {
        return ['provider' => 'sham_cash', 'account_id' => $settings->account_id,
            'qr_url' => $settings->qr_path ? route('payment-settings.qr') : null,
            'configured' => (bool) ($settings->account_id && $settings->qr_path),
            'currency' => 'SYP', 'proof_max_kb' => config('payments.proof_max_kb'),
            'proof_mime_types' => ['application/pdf', 'image/jpeg', 'image/png'],
            'checkout_minutes' => config('payments.checkout_minutes')];
    }
}
