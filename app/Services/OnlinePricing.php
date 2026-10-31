<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Lesson;
use App\Models\Payment;
use App\Models\PaymentLessonAllocation;
use App\Models\TeacherSubject;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Model;

class OnlinePricing
{
    public static function cents($amount): int
    {
        return (int) round((float) $amount * 100);
    }

    public static function decimal(int $cents): string
    {
        return number_format($cents / 100, 2, '.', '');
    }

    public function snapshot(Model $item, $amount): array
    {
        $lessons = match (true) {
            $item instanceof TeacherSubject => Lesson::whereHas('unit', fn ($q) => $q->where('teacher_subject_id', $item->id)->where('status', 'published')),$item instanceof Unit => Lesson::where('unit_id', $item->id),$item instanceof Lesson => Lesson::whereKey($item->id),$item instanceof Content => Lesson::whereKey($item->lesson_id),default => null
        };
        if (! $lessons) {
            app(ShamCashPayments::class)->fail('ONLINE_SERVICE_REQUIRED', 'هذه الخدمة ليست محتوى إلكترونياً.', 422);
        }
        $lessons = $lessons->where('status', 'published')->orderBy('id')->get();
        if ($lessons->isEmpty()) {
            app(ShamCashPayments::class)->fail('LESSONS_NOT_CONFIGURED', 'لا توجد دروس منشورة لهذه الخدمة.', 409);
        }
        $total = self::cents($amount);
        $weights = [];
        $rows = [];
        foreach ($lessons as $l) {
            $price = $l->price;
            if ($item instanceof Content) {
                $price = $item->price;
            }
            if ($price === null && $lessons->count() === 1) {
                $price = $amount;
            }
            if ($price === null) {
                app(ShamCashPayments::class)->fail('LESSON_PRICES_REQUIRED', 'يجب تحديد أسعار دروس الحزمة قبل طرحها للبيع.', 409);
            }
            $ids = $item instanceof Content ? [$item->id] : $l->contents()->where('status', 'published')->where('type', '!=', 'quiz')->orderBy('id')->pluck('id')->all();
            $weights[] = self::cents($price);
            $rows[] = ['lesson_id' => $l->id, 'lesson_title' => $l->title, 'lesson_price_snapshot' => self::decimal(self::cents($price)), 'content_ids_snapshot' => $ids];
        }
        $sum = array_sum($weights);
        if ($sum <= 0) {
            app(ShamCashPayments::class)->fail('LESSON_PRICES_REQUIRED', 'يجب تحديد أسعار الدروس.', 409);
        }
        $allocated = [];
        $remainders = [];
        $used = 0;
        foreach ($weights as $i => $w) {
            $exact = $total * ($w / $sum);
            $allocated[$i] = (int) floor($exact);
            $remainders[$i] = $exact - $allocated[$i];
            $used += $allocated[$i];
        }
        arsort($remainders, SORT_NUMERIC);
        $order = array_keys($remainders);
        for ($i = 0; $used < $total; $i++,$used++) {
            $allocated[$order[$i % count($order)]]++;
        }
        for ($i = count($order) - 1; $used > $total; $i--,$used--) {
            $allocated[$order[($i + count($order)) % count($order)]]--;
        }
        foreach ($rows as $i => &$row) {
            $row['allocated_amount'] = self::decimal($allocated[$i]);
        }unset($row);

        return ['version' => 1, 'amount' => self::decimal($total), 'allocation_method' => 'proportional_lesson_prices', 'lessons' => $rows];
    }

    public function persist(Payment $payment, array $snapshot): void
    {
        if (array_sum(array_map(fn ($x) => self::cents($x['allocated_amount']), $snapshot['lessons'] ?? [])) !== self::cents($payment->amount)) {
            app(ShamCashPayments::class)->fail('INVALID_PRICE_SNAPSHOT', 'تعذر اعتماد توزيع السعر.', 409);
        }
        foreach ($snapshot['lessons'] as $row) {
            PaymentLessonAllocation::firstOrCreate(['payment_id' => $payment->id, 'lesson_id' => $row['lesson_id']], $row);
        }
    }
}
