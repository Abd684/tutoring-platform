<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends AdminController
{
    public function form()
    {
        if (auth()->check() && auth()->user()->role === 'company_admin' && auth()->user()->status === 'active') {
            return redirect()->route('admin.overview');
        }

        return view('admin.login');
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'string', 'max:255'], 'password' => ['required', 'string']]);
        $user = User::where(fn ($q) => $q->where('email', trim($data['email']))->orWhere('phone', trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password_hash) || $user->role !== 'company_admin' || $user->status !== 'active') {
            AuditLog::create(['user_id' => null, 'action' => 'admin.login_failed', 'entity_type' => User::class, 'entity_id' => $user?->id ?? 0, 'metadata_json' => ['identifier_hash' => hash('sha256', strtolower(trim($data['email'])))], 'ip' => $request->ip(), 'created_at' => now()]);
            throw ValidationException::withMessages(['email' => ['بيانات الدخول غير صحيحة أو الحساب غير مخوّل للإدارة.']]);
        }
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $this->audit('login_succeeded', $user);

        return redirect()->intended(route('admin.overview'));
    }

    public function logout(Request $request)
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
