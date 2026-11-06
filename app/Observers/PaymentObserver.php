<?php

namespace App\Observers;

use App\Models\Notification;
use App\Models\Payment;
use App\Models\User;

class PaymentObserver
{
    public function created(Payment $payment): void
    {
        if ($payment->status !== 'pending' || ! in_array($payment->provider, ['sham_cash', 'manual_transfer'], true) || $payment->payer?->role !== 'student') {
            return;
        }
        foreach (User::where('role', 'company_admin')->where('status', 'active')->pluck('id') as $userId) {
            Notification::create(['user_id' => $userId, 'type' => 'payment_submitted', 'title' => 'طلب دفع جديد',
                'body' => 'قدّم الطالب '.$payment->payer->name.' إثبات دفع بانتظار المراجعة.',
                'related_payment_id' => $payment->id, 'data' => ['payment_id' => $payment->id]]);
        }
    }
}
