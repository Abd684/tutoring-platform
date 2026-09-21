<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Wallet::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $wallet = Wallet::create($request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'currency' => ['required', 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($wallet, 'Wallet created successfully.', 201);
    }

    public function show(Wallet $wallet): JsonResponse
    {
        return $this->success($wallet);
    }

    public function update(Request $request, Wallet $wallet): JsonResponse
    {
        $wallet->update($request->validate([
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'status' => ['sometimes', 'string', 'max:50'],
        ]));

        return $this->success($wallet->fresh(), 'Wallet updated successfully.');
    }

    public function destroy(Wallet $wallet): JsonResponse
    {
        $wallet->delete();

        return $this->success(message: 'Wallet deleted successfully.');
    }
}

