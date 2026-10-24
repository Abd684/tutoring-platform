<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

abstract class AdminController extends Controller
{
    protected function audit(string $action, $model, array $metadata = []): void
    {
        AuditLog::create(['user_id' => auth()->id(), 'action' => 'admin.'.$action, 'entity_type' => get_class($model), 'entity_id' => $model->id,
            'metadata_json' => $metadata, 'ip' => request()->ip(), 'created_at' => now()]);
    }

    /** Reuse the protected API's state machine; never write a financial status in a web adapter. */
    protected function apiResult($response, string $message)
    {
        $body = $response->getData(true);
        if ($response->getStatusCode() >= 400) {
            throw ValidationException::withMessages(['operation' => [$body['message'] ?? 'تعذر تنفيذ العملية.']]);
        }

        return back()->with('success', $message);
    }

    protected function filters(Request $request): array
    {
        return $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:50'],
            'teacher_id' => ['nullable', 'integer', 'min:1'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', ...($request->filled('from') ? ['after_or_equal:from'] : [])],
            'page' => ['nullable', 'integer', 'min:1']]);
    }

    protected function csv($query, array $columns, string $name)
    {
        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_keys($columns));
            foreach ($query->lazy(500)->take(10000) as $row) {
                $values = [];
                foreach ($columns as $value) {
                    $cell = (string) (is_callable($value) ? $value($row) : data_get($row, $value));
                    $values[] = preg_match('/^[=+@\-\t\r]/u', $cell) ? "'".$cell : $cell;
                }
                fputcsv($out, $values);
            }
            fclose($out);
        }, $name.'-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
