<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PaymentController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Payment::query()->with(['payer:id,name,email,role,status']);

        $query->when($request->filled('payer_id'), fn ($q) => $q->where('payer_id', $request->integer('payer_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
            ->when($request->filled('provider'), fn ($q) => $q->where('provider', $request->string('provider')))
            ->when($request->filled('provider_reference'), fn ($q) => $q->where('provider_reference', $request->string('provider_reference')));

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $payment = Payment::create($request->validate([
            'payer_id' => ['required', 'integer', 'exists:users,id'],
            'payable_type' => ['required', 'string', 'max:255'],
            'payable_id' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['required', 'string', 'size:3'],
            'provider' => ['required', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
        ]));

        return $this->success($payment->load('payer:id,name,email,role,status'), 'Payment created successfully.', 201);
    }

    public function show(Payment $payment): JsonResponse
    {
        return $this->success($payment->load('payer:id,name,email,role,status'));
    }

    public function update(Request $request, Payment $payment): JsonResponse
    {
        $payment->update($request->validate([
            'payer_id' => ['sometimes', 'integer', 'exists:users,id'],
            'payable_type' => ['sometimes', 'required', 'string', 'max:255'],
            'payable_id' => ['sometimes', 'integer', 'min:1'],
            'amount' => ['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'provider' => ['sometimes', 'string', 'max:100'],
            'provider_reference' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])],
            'paid_at' => ['nullable', 'date'],
        ]));

        return $this->success($payment->fresh(), 'Payment updated successfully.');
    }

    public function destroy(Payment $payment): JsonResponse
    {
        if ($payment->status === 'succeeded') {
            return $this->businessError('Succeeded payments must not be deleted. Use refund/reversal business logic instead.', 409);
        }

        $payment->delete();

        return $this->success(message: 'Payment deleted successfully.');
    }
}
