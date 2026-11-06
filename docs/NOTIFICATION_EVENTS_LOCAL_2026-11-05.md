# إشعارات أحداث الدفع والجلسات

التعديل محلي في `C:\xampp\htdocs\tutoring-platform`، دون نشر للسيرفر.

- تقديم دفع شام كاش أو تحويل يدوي من طالب: `payment_submitted` لكل مدير شركة نشط، مع `related_payment_id` و`data.payment_id`. جرس لوحة الإدارة يعرض آخر خمسة طلبات معلقة مع رابط المراجعة. تختفي من القائمة عند اتخاذ القرار.
- قبول أو رفض الدفع: `payment_approved` أو `payment_rejected` للطالب، بما يشمل مسار الحجز الفردي القديم والتحويل اليدوي. القبول الذي يحتاج موعداً بديلاً يوضح ذلك في الرسالة.
- طلب الانضمام وقراره موجودة مسبقاً: `group_join_requested` للأستاذ، ثم `group_join_approved` أو `group_join_rejected` للطالب. أضيف تحقق للاختبارات من عدم تكرارها.
- `session_reminder` للطلاب المشاركين والأستاذ خلال الساعة السابقة للجلسة المجدولة. المجدول يفحص كل دقيقة؛ التشغيل المتأخر يعوض التذكيرات خلال الساعة، ولا يرسل تذكيراً بعد بدء الجلسة أو عند إلغائها.
- إلغاء الجلسة موجود مسبقاً في مسارات الأستاذ والأدمن والطالب. مراقب تحديث الجلسة ينشئ `session_cancelled` للمشاركين الحاليين والأستاذ، ويغطي الجلسة الفردية والمجموعة والحجز المدفوع.
- `data` لإشعارات الجلسات: `session_id`, `starts_at` (ISO 8601), `session_type`.
- جدول `session_notification_events` يمنع تكرار إشعار الموعد نفسه حتى بعد حذف الإشعارات القديمة. تغيير موعد البداية يسمح بتذكير جديد. عضوية المجموعة الفعالة هي معيار المشاركين.

تستخدم الأحداث جدول الإشعارات الحالي وواجهات قراءة الطالب/الأستاذ الحالية. إرسال FCM يستخدم `NotificationObserver` الحالي والصف بعد تثبيت المعاملة؛ يحتاج تفعيل الإعدادات وتسجيل جهاز صالح وتشغيل عامل الصف. لم يتم اختبار وصول Push إلى جهاز حقيقي.

## التشغيل

تم تنفيذ migration الجديدة على قاعدة البيانات المحلية فقط. عند النشر لاحقاً:

```sh
php artisan migrate --force
```

يجب تشغيل Laravel scheduler كل دقيقة بواسطة cron الموجود على الاستضافة أو `php artisan schedule:work` أثناء التطوير. الأمر المسجل هو `notifications:session-reminders`، مع `withoutOverlapping`. لا يكفي وجود التعريف في الكود دون تشغيل scheduler.

## التحقق

اختبارات SQLite منفصلة عن قاعدة البيانات المحلية تشمل الدفع، الانضمام، الإلغاء، التذكير، التراجع عن المعاملة ومنع التكرار بعد حذف الإشعارات. تشغيل اختبارات الصور يحتاج GD:

```sh
php -d extension=gd vendor/phpunit/phpunit/phpunit --filter 'ShamCashPaymentTest|StudentSessionFlowTest|DirectIndividualBookingTest|PaidBookingRefundTest|TeacherGroupListingTest|NotificationDeliveryTest|AdminDashboardTest|TeacherFrontendWorkflowTest'
```
