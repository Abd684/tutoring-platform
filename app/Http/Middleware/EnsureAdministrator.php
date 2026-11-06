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
        view()->share('paymentAlerts', \App\Models\Notification::where('user_id', $user->id)
            ->where('type', 'payment_submitted')->whereHas('user')
            ->whereIn('related_payment_id', Payment::where('status', 'pending')->select('id'))
            ->latest('id')->limit(5)->get());
        view()->share('navCounts', ['payments' => Payment::where('status', 'pending')->count(),
            'refunds' => RefundRequest::where('status', 'PENDING_REVIEW')->count()]);

        return $next($request);
    }
}
