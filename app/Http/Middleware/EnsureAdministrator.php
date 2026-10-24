<?php

namespace App\Http\Middleware;

use App\Models\Payment;
use App\Models\PlatformSetting;
use App\Models\RefundRequest;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureAdministrator
{
    public function handle(Request $request, Closure $next)
    {
        $user = Auth::guard('web')->user();
        if (! $user) {
            return redirect()->guest(route('admin.login'));
        }
        abort_unless($user->role === 'company_admin' && $user->status === 'active', 403);
        view()->share('dashboardSettings', PlatformSetting::dashboard());
        view()->share('navCounts', ['payments' => Payment::where('status', 'pending')->count(),
            'refunds' => RefundRequest::where('status', 'PENDING_REVIEW')->count()]);

        return $next($request);
    }
}
