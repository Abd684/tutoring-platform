<?php

namespace Database\Seeders;

use App\Models\PaymentSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Explicit local demo setup; never included in the production DatabaseSeeder. */
class ShamCashDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new \RuntimeException('Sham Cash demo settings are restricted to local/testing environments.');
        }

        DB::transaction(function (): void {
            $settings = PaymentSetting::lockForUpdate()->findOrFail(1);
            if ($settings->account_id && $settings->account_id !== 'DEMO-NOT-FOR-PAYMENT') {
                $this->command?->warn('Existing payment account preserved; demo settings were not applied.');

                return;
            }
            $path = 'payment-qr/sham-cash-demo.png';
            $stored = Storage::disk('local')->put($path, file_get_contents(database_path('seeders/data/sham-cash-demo-qr.png')));
            if (! $stored) {
                throw new \RuntimeException('Unable to store the demo QR image.');
            }
            $settings->update(['account_id' => 'DEMO-NOT-FOR-PAYMENT', 'qr_path' => $path]);
            $this->command?->info('Demo Sham Cash account and QR configured. No real payments can be sent to this QR.');
        });
    }
}
