<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SettingsController extends AdminController
{
    public function index()
    {
        return view('admin.settings');
    }

    public function update(Request $request)
    {
        $data = $request->validate(['platform_name' => ['required', 'string', 'max:100'], 'support_email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['required', 'timezone'], 'theme' => ['required', 'in:light,dark'], 'density' => ['required', 'in:comfortable,compact']]);
        DB::transaction(function () use ($data) {
            $record = PlatformSetting::updateOrCreate(['key' => 'dashboard'], ['value' => [...$data, 'locale' => 'ar']]);
            $this->audit('settings.updated', $record);
        });

        return back()->with('success', 'تم حفظ إعدادات اللوحة.');
    }

    public function profile(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore(auth()->id())]]);
        DB::transaction(function () use ($request, $data) {
            $request->user()->update($data);
            $this->audit('administrator.profile_updated', $request->user());
        });

        return back()->with('success', 'تم حفظ ملف المدير.');
    }

    public function auditLog(Request $request)
    {
        $this->filters($request);
        $query = AuditLog::with('user');
        if ($request->filled('search')) {
            $query->where('action', 'like', '%'.$request->string('search').'%');
        }
        $items = $query->orderByDesc('id')->paginate(20)->withQueryString();

        return view('admin.audit', compact('items'));
    }
}
