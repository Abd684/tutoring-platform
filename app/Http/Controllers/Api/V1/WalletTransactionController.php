<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletTransactionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = WalletTransaction::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $walletTransaction = WalletTransaction::create($request->validate([
            'wallet_id' => ['required', 'integer', 'exists:wallets,id'],
            'type' => ['required', 'string', 'max:50'],
            'amount' => ['required', 'numeric'],
            'reference_type' => ['nullable', 'string', 'max:255'],
            'reference_id' => ['nullable', 'integer'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($walletTransaction, 'Wallet Transaction created successfully.', 201);
    }

    public function show(WalletTransaction $walletTransaction): JsonResponse
    {
        return $this->success($walletTransaction);
    }

    public function update(Request $request, WalletTransaction $walletTransaction): JsonResponse
    {
        $walletTransaction->update($request->validate([
            'wallet_id' => ['sometimes', 'integer', 'exists:wallets,id'],
            'type' => ['sometimes', 'string', 'max:50'],
            'amount' => ['sometimes', 'numeric'],
            'reference_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'reference_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($walletTransaction->fresh(), 'Wallet Transaction updated successfully.');
    }

    public function destroy(WalletTransaction $walletTransaction): JsonResponse
    {
        $walletTransaction->delete();

        return $this->success(message: 'Wallet Transaction deleted successfully.');
    }
}

