<?php

//deaa

namespace App\Http\Controllers\Api\V1;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = AuditLog::query();

        return $this->paginated($query->orderByDesc('id')->paginate($this->perPage($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $auditLog = AuditLog::create($request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'action' => ['required', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_id' => ['nullable', 'integer'],
            'metadata_json' => ['nullable', 'array'],
            'ip' => ['nullable', 'ip'],
            'created_at' => ['nullable', 'date'],
        ]));

        return $this->success($auditLog, 'Audit Log created successfully.', 201);
    }

    public function show(AuditLog $auditLog): JsonResponse
    {
        return $this->success($auditLog);
    }

    public function update(Request $request, AuditLog $auditLog): JsonResponse
    {
        $auditLog->update($request->validate([
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'action' => ['sometimes', 'string', 'max:100'],
            'entity_type' => ['sometimes', 'nullable', 'string', 'max:255'],
            'entity_id' => ['sometimes', 'nullable', 'integer'],
            'metadata_json' => ['sometimes', 'nullable', 'array'],
            'ip' => ['sometimes', 'nullable', 'ip'],
            'created_at' => ['sometimes', 'nullable', 'date'],
        ]));

        return $this->success($auditLog->fresh(), 'Audit Log updated successfully.');
    }

    public function destroy(AuditLog $auditLog): JsonResponse
    {
        $auditLog->delete();

        return $this->success(message: 'Audit Log deleted successfully.');
    }
}

