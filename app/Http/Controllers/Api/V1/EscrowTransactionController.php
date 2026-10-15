<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\EscrowTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EscrowTransactionController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', EscrowTransaction::class);

        $query = EscrowTransaction::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', EscrowTransaction::class);

        $escrowTransaction = EscrowTransaction::create($request->validate([
            'payment_id' => ['required', 'integer', 'exists:payments,id'],
            'enrollment_id' => ['required', 'integer', 'exists:enrollments,id'],
            'teacher_id' => ['required', 'integer', 'exists:teachers,id'],
            'gross_amount' => ['required', 'numeric', 'min:0'],
            'commission' => ['required', 'numeric', 'min:0'],
            'teacher_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
            'release_at' => ['nullable', 'date'],
            'released_at' => ['nullable', 'date'],
        ]));

        return $this->success($escrowTransaction, 'Escrow Transaction created successfully.', 201);
    }

    public function show(EscrowTransaction $escrowTransaction): JsonResponse
    {
        $this->authorize('view', $escrowTransaction);

        return $this->success($escrowTransaction);
    }

    public function update(Request $request, EscrowTransaction $escrowTransaction): JsonResponse
    {
        $this->authorize('update', $escrowTransaction);

        $escrowTransaction->update($request->validate([
            'payment_id' => ['sometimes', 'integer', 'exists:payments,id'],
            'enrollment_id' => ['sometimes', 'integer', 'exists:enrollments,id'],
            'teacher_id' => ['sometimes', 'integer', 'exists:teachers,id'],
            'gross_amount' => ['sometimes', 'numeric', 'min:0'],
            'commission' => ['sometimes', 'numeric', 'min:0'],
            'teacher_amount' => ['sometimes', 'numeric', 'min:0'],
            'status' => ['sometimes', 'string', 'max:50'],
            'release_at' => ['sometimes', 'nullable', 'date'],
            'released_at' => ['sometimes', 'nullable', 'date'],
        ]));

        return $this->success($escrowTransaction->fresh(), 'Escrow Transaction updated successfully.');
    }

    public function destroy(EscrowTransaction $escrowTransaction): JsonResponse
    {
        $this->authorize('delete', $escrowTransaction);

        $escrowTransaction->delete();

        return $this->success(message: 'Escrow Transaction deleted successfully.');
    }
}

