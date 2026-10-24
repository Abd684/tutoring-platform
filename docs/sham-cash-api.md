# شام كاش — عقد المرحلة الأولى

تاريخ التنفيذ: 2026-10-24. الدفع تحويل يدوي إلى حساب المنصة؛ الاعتماد يتم بقرار الإدارة بعد التحقق الفعلي. لا اتصال ببوابة شام كاش ولا تحقق آلي من وصول المال.

## النطاق والتشغيل

المنفذ: إعدادات حساب واحد وQR، checkout مؤقت، إثبات خاص، مراجعة مع سبب رفض وتدقيق، تفعيل المادة/الوحدة/المحتوى، سجل الطالب، إشعارات داخل التطبيق مرتبطة بالدفع. لم تُعدّل الواجهات.

تحديث: نُفذت لاحقاً حجوزات الدروس المدفوعة بمهلة 24 ساعة وإعادة الجدولة والاسترداد اليدوي؛ عقدها في [الدروس والاسترداد](paid-bookings-and-refunds-api.md). الإشعارات الجديدة وPush مؤجلة بطلب المستخدم؛ لا يوجد مزود Push أو تسجيل توكناته في المشروع حالياً.

لتجهيز قاعدة البيئة المستهدفة، راجع ثم طبّق migration الجديدة `2026_10_24_000001_add_sham_cash_payments.php` عبر آلية نشر المشروع. طُبقت migration الدفع تحديداً على قاعدة العمل المحلية بتاريخ 2026-10-24، دون تشغيل بقية migrations المعلقة. الاختبارات تشغّل migrations على SQLite مؤقتة. يجب ضبط حد رفع PHP/خادم الويب ليسمح بملف 5 MiB إضافة إلى بقية الطلب؛ خطأ 413 من الخادم قد يسبق تحقق Laravel.

تبدأ الإعدادات فارغة عمداً: الإدارة ترفع QR وتحدد الحساب الحقيقي عبر API. لا يوجد رقم حساب افتراضي أو بيانات مالية مزروعة في البيئة الفعلية. الملفات على قرص `local` تحت `storage/app/private`، ولا تحتاج رابط `storage:link`.

للتجربة المحلية فقط، أُضيف وشُغّل `php artisan db:seed --class=ShamCashDemoSeeder` بطلب المستخدم. يجهز حساب `DEMO-NOT-FOR-PAYMENT` وQR يحمل نصاً تجريبياً واضحاً غير صالح للتحويل. لا يُشغّل تلقائياً، ويمنع التشغيل خارج local/testing ولا يستبدل حساباً حقيقياً موجوداً. لاستبدال الحساب والصورة لاحقاً استخدم API إعدادات الإدارة؛ لا يلزم تغيير Flutter.

## المصادقة والمسارات

البادئة `/api/v1`. جميع المسارات تتطلب Bearer token ويفضل `Accept: application/json`. الطالب يحتاج جلسة جهاز فعالة من نظام تسجيل الدخول الحالي وملف طالب فعالاً؛ الإدارة تحتاج `company_admin` فعالاً. روابط QR والإثبات تحتاج المصادقة أيضاً؛ Flutter يحملها بعميل HTTP يرسل التوكن، ولا يفتحها كرابط عام.

| Method | Path | الدور | الطلب / النتيجة |
|---|---|---|---|
| GET | `/payment-settings` | طالب/إدارة | إعدادات شام كاش الحالية وحدود الرفع |
| PUT | `/admin/payment-settings` | إدارة | `account_id` إلزامي، `qr_image` اختياري؛ يسجل التعديل في audit_logs |
| GET | `/payment-settings/qr` | طالب/إدارة | صورة QR الحالية |
| POST | `/payments/checkout` | طالب | JSON: `service_type`, `service_id`؛ 201 جلسة مؤقتة |
| GET | `/payments/checkout/{checkout}/qr` | صاحب الجلسة | QR المثبت عند إنشاء الجلسة |
| POST | `/student/payments` | طالب | multipart: `checkout_id`, `provider_reference`, `proof`؛ 201 طلب مراجعة |
| GET | `/student/payments` | طالب | سجل شام كاش الخاص به فقط |
| GET | `/student/payments/{payment}` | صاحب الدفع | التفاصيل؛ يبقى عرض الدفعات التاريخية متاحاً |
| GET | `/payment-proofs/{payment}` | صاحب الدفع/إدارة | بث ملف الإثبات مع منع التخزين المؤقت وnosniff |
| GET | `/admin/payments` | إدارة | طلبات شام كاش مع اسم الطالب وsnapshot الخدمة/الأستاذ |
| POST | `/admin/payments/{payment}/review` | إدارة | `decision`: confirm/reject، و`rejection_reason` إلزامي عند reject |
| GET | `/student/notifications` | طالب | الإشعارات الحالية، ويضاف `related_payment_id` لإشعار الدفع |

تنبيه توافق: POST القديم `/student/enrollments/{enrollment}/payments` لم يعد ينشئ دفعات بلا إثبات؛ يعيد 410 مع `code: CHECKOUT_REQUIRED` ويوجه للدورة الجديدة. مراجعة دفعات `manual_transfer` القديمة مستمرة بسلوكها السابق. CRUD الإداري `/payments` لا يسمح بإنشاء sham_cash أو تعديلها، ولا بحذف محاولات دفع الاشتراك.

## 1. إعدادات الحساب

مثال GET `/payment-settings`:

```json
{
  "status": true,
  "data": {
    "provider": "sham_cash",
    "account_id": "PLATFORM-ACCOUNT",
    "qr_url": "https://example.test/api/v1/payment-settings/qr",
    "configured": true,
    "currency": "SYP",
    "proof_max_kb": 5120,
    "proof_mime_types": ["application/pdf", "image/jpeg", "image/png"],
    "checkout_minutes": 30
  }
}
```

قبل الإعداد، account_id وqr_url يكونان null وconfigured=false. لإنشاء أول إعداد يلزم إرسال الحساب والصورة؛ إذا أرسلت الحساب وحده يبقى configured=false حتى إضافة QR. QR يقبل JPG/JPEG/PNG، بحد 5120 KiB وأبعاد 4096×4096 كحد أقصى. account_id نص غير فارغ حتى 255 حرفاً.

عند رفع QR استخدم POST مع `_method=PUT` في multipart لأن PHP لا يحلل ملفات PUT multipart بالطريقة نفسها:

```bash
curl -X POST "$BASE/api/v1/admin/payment-settings" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Accept: application/json" \
  -F '_method=PUT' -F 'account_id=REAL-ACCOUNT-ID' -F 'qr_image=@qr.png'
```

يمكن تحديث الحساب وحده باستخدام PUT JSON. QR القديم يحتفظ به التخزين حتى لا تتغير وجهة الدفع للجلسات السابقة.

## 2. بدء checkout

```json
{"service_type":"teacher_subject","service_id":7}
```

`service_type` للمواد يقبل teacher_subject أو unit أو content؛ service_id عدد موجب لخدمة منشورة ومتاحة بسعر موجب. يرفض امتلاك الخدمة أو تغطيتها باشتراك فعال، ووجود دفع pending للخدمة نفسها. لا يستقبل student_id أو amount من العميل. أضيف أيضاً lesson_booking مع وقتي النافذة وفق عقد الدروس أعلاه.

مثال 201:

```json
{
  "status": true,
  "data": {
    "checkout_id": "a3c2fa6b-5644-4afe-9f39-3063f581a0ac",
    "internal_reference": "a3c2fa6b-5644-4afe-9f39-3063f581a0ac",
    "amount": "100.00",
    "currency": "SYP",
    "service": {
      "type": "teacher_subject", "id": 7, "title": "رياضيات", "subject_name": "رياضيات",
      "teacher": {"id": 4, "name": "اسم الأستاذ"}
    },
    "account_id": "PLATFORM-ACCOUNT",
    "qr_url": "https://example.test/api/v1/payments/checkout/a3c2fa6b-5644-4afe-9f39-3063f581a0ac/qr",
    "expires_at": "2026-10-24T12:30:00+00:00",
    "settings_url": "https://example.test/api/v1/payment-settings"
  }
}
```

تعرض واجهة الدفع account_id وqr_url من **استجابة checkout** كي تبقى وجهة الجلسة ثابتة إذا عدلت الإدارة الإعدادات. settings_url للتعريف بالإعدادات الحالية وحدود الملفات. الأرقام والعناوين في الأمثلة توضيحية.

الرقم الداخلي UUID مستقل عن رقم التحويل الحقيقي. صلاحية الجلسة 30 دقيقة من إنشائها. الخروج منها لا ينشئ Payment أو Enrollment، ولا يظهر شيئاً بقائمة الإدارة. تُحفظ الجلسة ورقمها ولا يعاد استخدامه لمحاولة جديدة. الجلسة المنتهية لا تحتاج scheduler: يُفحص وقت الانتهاء على السيرفر عند الإرسال.

## 3. رفع الإثبات وإرسال الطلب

```bash
curl -X POST "$BASE/api/v1/student/payments" \
  -H "Authorization: Bearer $STUDENT_TOKEN" -H "Accept: application/json" \
  -F 'checkout_id=a3c2fa6b-5644-4afe-9f39-3063f581a0ac' \
  -F 'provider_reference=ACTUAL-SHAM-TRANSFER' -F 'proof=@receipt.pdf'
```

لا تضبط Content-Type يدوياً؛ عميل multipart يضيف boundary. proof ملف إلزامي: PDF/JPG/JPEG/PNG، يتحقق السيرفر من MIME والمحتوى عبر قواعد Laravel، والحد 5120 KiB. provider_reference نص غير فارغ حتى 255 حرفاً، تُزال المسافات الطرفية، ويجب ألا يكون مستخدماً في محاولة سابقة حتى المرفوضة؛ عند الحاجة إلى استثناء يلزم إجراء إداري منفصل، ولا يوجد تجاوز تلقائي هنا.

مثال 201 (حقول القرار التي لم تُكتب بعد قد تغيب في استجابة الإنشاء وتظهر null بعد القراءة):

```json
{
  "status": true,
  "message": "Payment submitted for administrator review.",
  "data": {
    "id": 81, "payer_id": 12,
    "payable_type": "App\\Models\\Enrollment", "payable_id": 42,
    "amount": "100.00", "currency": "SYP", "provider": "sham_cash",
    "provider_reference": "ACTUAL-SHAM-TRANSFER",
    "internal_reference": "a3c2fa6b-5644-4afe-9f39-3063f581a0ac",
    "proof_mime": "application/pdf",
    "status": "pending", "payment_status": "PENDING_REVIEW",
    "service_snapshot": {
      "type": "teacher_subject", "id": 7, "title": "رياضيات", "subject_name": "رياضيات",
      "teacher": {"id": 4, "name": "اسم الأستاذ"}, "account_id": "PLATFORM-ACCOUNT"
    },
    "submitted_at": "2026-10-24T12:05:00.000000Z",
    "proof_url": "https://example.test/api/v1/payment-proofs/81"
  }
}
```

يُنشأ الاشتراك pending عند الإرسال فقط، أو يعاد استخدام اشتراك pending قديم لا يملك دفعاً نشطاً. لا يفتح المحتوى قبل الاعتماد. المبلغ وقت الإرسال يطابق عرض checkout؛ إذا تغير سعر الكتالوج قبل الإرسال يرجع PRICE_CHANGED ويجب إعادة checkout. بعد الإرسال يحتفظ الدفع والاشتراك بالمبلغ ولا يتأثران بسعر لاحق.

إعادة نفس checkout ونفس رقم التحويل تعيد 200 مع السجل نفسه، ولا ترفع ملفاً ثانياً أو تنشئ محاولة جديدة. يلزم إرسال ملف صالح أيضاً في إعادة الطلب. تغيير رقم التحويل على جلسة مستهلكة يرجع CHECKOUT_CONSUMED. بعد الرفض تبدأ جلسة جديدة وإثباتاً ورقم تحويل جديدين؛ لا تستخدم الجلسة القديمة لإعادة التقديم.

## 4. المراجعة والتفعيل

```json
{"decision":"confirm"}
```

أو:

```json
{"decision":"reject","rejection_reason":"لم يصل التحويل إلى حساب المنصة"}
```

سبب الرفض نص غير فارغ حتى 2000 حرف. التأكيد لا يعني تنفيذ تحويل إلكتروني؛ المسؤول يتحقق يدوياً من الحساب والمبلغ أولاً. استجابة 200:

```json
{
  "status": true,
  "message": "Payment review completed.",
  "data": {
    "payment": {
      "id": 81, "status": "succeeded", "payment_status": "APPROVED",
      "reviewed_by": 3, "reviewed_at": "2026-10-24T13:00:00.000000Z",
      "paid_at": "2026-10-24T13:00:00.000000Z", "rejection_reason": null
    },
    "enrollment": {"id": 42, "status": "active", "expires_at": null}
  }
}
```

المثال يختصر بقية حقول السجل؛ التنفيذ يعيد بيانات الدفع الكاملة نفسها وبيانات Enrollment الخام. عند الرفض status=failed وpayment_status=REJECTED، وrejection_reason محفوظ، والاشتراك يبقى pending. عند التأكيد يسجل paid_at ويبدأ الاشتراك active دون تاريخ انتهاء وفق سلوك شراء المواد الحالي. الخدمة غير المتاحة أو الطالب غير الفعال يمنعان التفعيل؛ لا يغيّر ذلك السعر التاريخي.

قرار المراجعة وتفعيل الاشتراك وسجل التدقيق والإشعار داخل معاملة واحدة مع أقفال قاعدة البيانات. فشل أي منها يرجع التغييرات. إعادة اتخاذ قرار لدفعة شام كاش محسومة ترجع 409، ولا تكرر التفعيل أو الإشعار. حالة الدفع مستقلة عن الاشتراك؛ لم يُضف الاسترداد إلى دورة القرار الجديدة.

لتوافق المشروع بقي `status` بقيم pending/succeeded/failed، وأضافت APIs شام كاش `payment_status` بالقيم PENDING_REVIEW/APPROVED/REJECTED. يربط Flutter على payment_status؛ لا يرسل أي حقل حالة لتأكيد الدفع بنفسه.

## 5. القوائم والإشعارات

قائمتا `/student/payments` و`/admin/payments` خاصتان بشام كاش. تصفية اختيارية: `payment_status=PENDING_REVIEW|APPROVED|REJECTED`، مع `page` و`per_page` (20 افتراضياً، من 1 إلى 100). سجلات التحويل القديمة تبقى في `/payments` الإداري والتفاصيل القديمة؛ هذه المرحلة لا توحّد كل مزودي الدفع في سجل الطالب الجديد.

```json
{"status":true,"data":[],"meta":{"current_page":1,"per_page":20,"total":0,"last_page":1}}
```

عناصر القائمة هي payload الدفع أعلاه، وتضيف قائمة الإدارة `payer: {id,name}`. لا يعاد مسار التخزين الخاص. مصدر اسم الخدمة والأستاذ snapshot عند الإرسال، وليس الاسم المتغير في الكتالوج.

إشعارات المواد المحفوظة سابقاً: payment_approved وpayment_rejected، مع related_payment_id. Flutter يستطيع فتح `/student/payments/{related_payment_id}` عند الضغط. إشعارات الحجوزات والاسترداد الجديدة وPush مؤجلة.

## 6. الأخطاء

أخطاء الأعمال المغلفة:

```json
{"status":false,"code":"PAYMENT_PENDING","message":"A payment for this service is already under review."}
```

| HTTP | code / الحالة |
|---|---|
| 503 | PAYMENT_NOT_CONFIGURED عند غياب الحساب أو QR |
| 409 | PURCHASE_UNAVAILABLE، PRICE_NOT_SET، ALREADY_OWNED، PAYMENT_PENDING |
| 409 | CHECKOUT_EXPIRED، CHECKOUT_CONSUMED، PRICE_CHANGED، TRANSFER_ALREADY_USED |
| 409 | قرار سابق أو اشتراك غير pending، أو اختلاف بيانات التفعيل؛ هذه أخطاء مراجعة `status:false,message` دون code حالياً |
| 410 | CHECKOUT_REQUIRED لمسار الإرسال القديم بلا إثبات |
| 422 | أخطاء حقول/ملف أو نوع خدمة/قرار غير صالح |
| 401/403 | مصادقة/جلسة جهاز أو صلاحيات غير صالحة |
| 404 | كيان غير موجود، جلسة لا يملكها الطالب، أو ملف غير موجود |

أخطاء التحقق ببنية Laravel `message` و`errors`، ولا تضمن `status`:

```json
{"message":"The proof field is required.","errors":{"proof":["The proof field is required."]}}
```

## التحقق

نتيجة `php artisan test --compact`: **157 اختباراً ناجحاً، 2651 assertion** على قاعدة الاختبار المؤقتة. التنسيق عبر Laravel Pint وفحص `git diff --check` ناجحان. مجموعة الطلبات الجاهزة للاستيراد: [ShamCash_API.postman_collection.json](../postman/ShamCash_API.postman_collection.json)؛ اختر ملفات QR والإثبات، واملأ التوكنات، ثم انسخ checkout_id وpayment_id من استجابات الإنشاء إلى متغيرات المجموعة. نفّذ التأكيد أو الرفض لكل طلب، وليس كليهما.

اختبارات `ShamCashPaymentTest` تشمل عدم إنشاء طلب عند checkout، المبلغ والهوية من السيرفر، رفع PDF وPNG، رفض MIME مزور/حجم زائد/ملف ناقص، انتهاء الجلسة وتغير السعر، منع التكرار، العزل بين الطلاب، الرفض وإعادة التقديم، ثبات حساب وQR الجلسة، وصلاحيات الإدارة. تختبر أيضاً التراجع عند فشل حفظ الدفع/الإشعار، ووصول الطالب الفعلي للمحتوى قبل الاعتماد وبعده. الاختبارات المحلية ليست إثباتاً لتحويل حقيقي أو لإرسال Push، ولا اختبار ضغط متزامن على قاعدة الإنتاج.
