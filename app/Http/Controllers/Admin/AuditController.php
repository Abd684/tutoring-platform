<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class AuditController extends AdminController
{
    public static function metadata($value)
    {
        if (! is_array($value)) {
            return $value;
        }
        $safe = [];
        foreach ($value as $key => $item) {
            $safe[$key] = preg_match('/password|token|secret|authorization|cookie|api[_-]?key/i', (string) $key) ? '[محجوب]' : self::metadata($item);
        }

        return $safe;
    }

    public static function maskedIp(?string $ip): string
    {
        if (! $ip) {
            return '—';
        }if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.[^.]+$/', '.*', $ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', $ip), 0, 3)).':…';
        }

        return '—';
    }

    public function index(Request $request)
    {
        $data = $this->filters($request);
        $request->validate(['actor' => ['nullable', 'regex:/^(system|[1-9][0-9]*)$/'], 'action' => ['nullable', 'string', 'max:100'], 'entity' => ['nullable', 'string', 'max:255'], 'entry' => ['nullable', 'integer', 'exists:audit_logs,id']]);
        $query = AuditLog::with('user');
        if (! empty($data['search'])) {
            $term = $data['search'];
            $query->where(fn ($q) => $q->where('action', 'like', "%{$term}%")->orWhere('entity_type', 'like', "%{$term}%")->orWhere('entity_id', ctype_digit($term) ? (int) $term : 0)->orWhere('id', ctype_digit($term) ? (int) $term : 0));
        }
        if ($request->filled('actor')) {
            $request->input('actor') === 'system' ? $query->whereNull('user_id') : $query->where('user_id', $request->integer('actor'));
        }
        if ($request->filled('action')) {
            $query->where('action', $request->input('action'));
        }
        if ($request->filled('entity')) {
            $query->where('entity_type', $request->input('entity'));
        }
        if (! empty($data['from'])) {
            $query->whereDate('created_at', '>=', $data['from']);
        }if (! empty($data['to'])) {
            $query->whereDate('created_at', '<=', $data['to']);
        }
        if ($request->boolean('export')) {
            return $this->csv($query->orderBy('id'), ['المعرف' => 'id', 'التاريخ' => 'created_at', 'المنفذ' => 'user.name', 'العملية' => 'action', 'الكيان' => 'entity_type', 'مرجع الكيان' => 'entity_id', 'عنوان الشبكة' => fn ($row) => self::maskedIp($row->ip)], 'audit-log');
        }
        $items = $query->orderByDesc('id')->paginate(20)->withQueryString();
        $selected = $request->filled('entry') ? AuditLog::with('user')->findOrFail($request->integer('entry')) : null;
        $metadata = $selected ? self::metadata($selected->metadata_json) : null;
        $actors = User::whereIn('id', AuditLog::select('user_id')->whereNotNull('user_id'))->orderBy('name')->get(['id', 'name']);
        $actions = AuditLog::distinct()->orderBy('action')->pluck('action');
        $stats = ['total' => AuditLog::count(), 'financial' => AuditLog::where(fn ($q) => $q->where('action', 'like', '%payment%')->orWhere('action', 'like', '%refund%'))->count(), 'system' => AuditLog::whereNull('user_id')->count()];

        return view('admin.audit',compact('items','selected','metadata','actors','actions','stats'));
    }
}
