# الجزء الأول: الجلسات والحصة المباشرة والدفع

Base URL: `{BASE_URL}/api/v1`. استبدل `{BASE_URL}` بعنوان السيرفر الفعلي، بدون شرطة مائلة في نهايته. جميع المسارات أدناه تُضاف إلى هذا العنوان.

كل المسارات أدناه تتطلب `Accept: application/json` و`Authorization: Bearer {access_token}`. الطلبات ذات JSON تتطلب `Content-Type: application/json`. أمثلة الأرقام والتواريخ توضيحية. المصادقة تحدد الطالب أو المدرس أو مسؤول الشركة؛ لا ترسل student_id لتحديد صاحب الطلب.

## 1. الجلسات القادمة

`GET /student/bookings` — توكن طالب، بلا body.

يرجع جلسات scheduled التي لم تبدأ، الأقرب أولاً، ويستبعد الملغاة والمنتهية وغير المرتبطة بالطالب. يشمل جلسات المجموعة إذا كانت عضوية اشتراك الطالب في المجموعة active. يحافظ على بنية الاستجابة السابقة ويضيف معرّفات المدرس والاشتراك.

200:

```json
{
  "data": [
    {
      "id": 14,
      "type": "individual",
      "starts_at": "2026-10-11T14:00:00.000000Z",
      "ends_at": "2026-10-11T15:00:00.000000Z",
      "status": "scheduled",
      "teacher": {"id": 1, "name": "أحمد الشامي"},
      "enrollment": {
        "id": 26,
        "enrollable_id": 1,
        "status": "active",
        "type": "teacher_subject",
        "subject": {"name": "الرياضيات"},
        "item": null
      }
    }
  ]
}
```

نوع الجلسة individual أو group. نوع الاشتراك teacher_subject أو unit أو content أو unknown. للوحدة والمحتوى يرجع item.title.  

## 2. المواعيد المتاحة

`GET /teachers/{teacher}/available-sessions?from=2026-10-11T00:00:00Z&to=2026-10-20T23:59:59Z&per_page=20&page=1`

توكن طالب. from وto اختياريان كتاريخ؛ to لا يسبق from عند إرسالهما. per_page افتراضياً 20 ومحصور بين 1 و100. بلا body.

يرجع جلسات المدرس الفردية المستقبلية scheduled وغير المحجوزة. يشترط حساب مدرس فعال واشتراك طالب فعال عند المدرس؛ لا يوجد ربط بدرس أو تحقق تغطية درس محدد. يجب أن يكون الاشتراك قد بدأ ويغطي نهاية الجلسة. يستبعد تعارضات الطالب عبر الاشتراكات والجلسات الفردية وعضويات المجموعات.

200:

```json
{
  "status": true,
  "data": [
    {
      "id": 30,
      "teacher_id": 1,
      "type": "individual",
      "starts_at": "2026-10-15T14:00:00.000000Z",
      "ends_at": "2026-10-15T15:00:00.000000Z",
      "status": "scheduled",
      "eligible_enrollment_ids": [26]
    }
  ],
  "meta": {"current_page": 1, "per_page": 20, "total": 1, "last_page": 1}
}
```

لا تُنشأ جلسات تلقائياً من جدول availability؛ المدرس ينشئ الجلسات أولاً. عدم أهلية الاشتراك أو وجود تعارض يرجع قائمة فارغة. مدرس غير فعال يرجع 404.

### إنشاء جلسة من المدرس

`POST /teacher/sessions` — توكن المدرس.

```json
{
  "type": "INDIVIDUAL",
  "starts_at": "2026-10-15T14:00:00Z",
  "ends_at": "2026-10-15T15:00:00Z",
  "capacity": 1,
  "status": "scheduled"
}
```

teacher_id يؤخذ من التوكن؛ إرساله اختياري ويجب أن يطابق المدرس. لا يوجد lesson_id في الطلب أو القاعدة. group_id وavailability_id وenrollment_id إذا أُرسلت يجب أن تتبع المدرس. يرجع 201 مع status:true وmessage:"Session created successfully." وdata يحتوي نموذج الجلسة.

### الحجز والإلغاء

`POST /sessions/30/book` — توكن طالب:

```json
{"enrollment_id": 26}
```

enrollment_id اختياري عند وجود اشتراك مؤهل واحد. يعاد التحقق من الأهلية والتعارض عند الحجز؛ لا تعتمد على القائمة وحدها. عند تعدد الاشتراكات أرسل أحد eligible_enrollment_ids. نجاح 200: status:true، message:"Session booked successfully." وdata نموذج الجلسة مع teacher وenrollment.

`DELETE /sessions/30/booking` — توكن الطالب صاحب الحجز، بلا body. نجاح 200: status:true، message:"Session booking cancelled successfully." وdata نموذج الجلسة مع enrollment_id:null وبقاء status:scheduled. لا يحذف الاشتراك أو الجلسة.

أخطاء الحجز المحتملة: INVALID_ENROLLMENT وNO_ACTIVE_ENROLLMENT وENROLLMENT_SELECTION_REQUIRED (422)، SESSION_TIME_CONFLICT وSESSION_ALREADY_BOOKED وALREADY_BOOKED_BY_STUDENT وSESSION_ALREADY_STARTED وSESSION_NOT_AVAILABLE وTEACHER_NOT_AVAILABLE (409).

## 3. دخول الحصة المباشرة — مؤجل

حُذف جدول غرف الحصص ومسارا room وjoin بناءً على الطلب. لا يوجد حالياً API لدخول الحصة المباشرة ضمن هذا الجزء.

## 4. التحويل اليدوي وتفعيل الاشتراك

إنشاء الاشتراك عبر مسارات enroll الحالية يبقيه pending. لا يعلن العميل نجاح الدفع حتى يؤكد المسؤول التحويل.

### تقديم مرجع التحويل

`POST /student/enrollments/{enrollment}/payments` — توكن صاحب الاشتراك:

```json
{"provider_reference": "BANK-TRANSFER-12345"}
```

مرجع التحويل مطلوب، نص حتى 255 حرفاً. المبلغ من enrollment.price وهو موجب، وpayer_id من التوكن، والحالة دائماً pending، والمزود manual_transfer. العملة من config/payments.php، الافتراضية SYP ويمكن تغييرها عبر PAYMENT_CURRENCY. لا يرسل الطالب مبلغاً أو حالة نجاح.

201:

```json
{
  "status": true,
  "message": "Transfer submitted for administrator verification.",
  "data": {
    "id": 10,
    "payer_id": 1,
    "payable_type": "App\\Models\\Enrollment",
    "payable_id": 26,
    "amount": "100.00",
    "currency": "SYP",
    "provider": "manual_transfer",
    "provider_reference": "BANK-TRANSFER-12345",
    "status": "pending"
  }
}
```

تكرار التقديم إذا هناك دفع pending لنفس الاشتراك يعيد نفس الدفع مع 200؛ لا ينشئ دفعاً جديداً أو يعدل مرجعه. اشتراك غير pending أو سعر غير موجب: 409. اشتراك طالب آخر: 403. التقديم لا يفعّل الاشتراك ولا يثبت التحويل؛ المسؤول يتحقق خارج النظام من المرجع والمبلغ وعدم استخدام التحويل مرتين.

### متابعة الدفع

`GET /student/payments/{payment}` — توكن صاحب الدفع، بلا body. 200: status:true وdata نموذج الدفع، يتضمن paid_at (null قبل التأكيد). طالب آخر: 403.

### تأكيد أو رفض من المسؤول

`POST /admin/payments/{payment}/review` — توكن company_admin فعال:

```json
{"decision": "confirm"}
```

أو:

```json
{"decision": "reject"}
```

التأكيد يحول الدفع إلى succeeded مع paid_at، والاشتراك إلى active مع starts_at وقت التأكيد وexpires_at:null (بدون انتهاء)، داخل transaction واحدة. الرفض يحول الدفع إلى failed ويبقي الاشتراك pending، ويسمح بتقديم طلب جديد. تأكيد الدفع المؤكد سابقاً يعيد 200 دون تغيير التواريخ. قرار غير صالح:422، محاولة مراجعة حالة غير pending:409، طالب يحاول التأكيد:403.

200:

```json
{
  "status": true,
  "message": "Payment review completed.",
  "data": {
    "payment": {"id": 10, "status": "succeeded", "paid_at": "2026-10-10T14:00:00.000000Z"},
    "enrollment": {"id": 26, "status": "active", "starts_at": "2026-10-10T14:00:00.000000Z", "expires_at": null}
  }
}
```

مثال المراجعة مختصر: payment وenrollment الفعليان يرجعان كل حقول النموذج. مسارات /payments العامة أصبحت محمية للمسؤول؛ إنشاء/تعديل دفع اشتراك من CRUD ممنوع، ويجب استخدام مسارات التقديم والمراجعة.

## أخطاء وتحديث القاعدة

بدون توكن:401؛ الدور الخاطئ:403؛ معرّف غير موجود:404؛ مدخلات غير صحيحة:422 مع message وerrors. أخطاء الأعمال المخصصة تشمل status:false وmessage وقد تشمل code.

لا توجد migrations جديدة لهذا الجزء بعد حذف ربط الدرس وجدول الغرف. اختبارات StudentSessionFlowTest تعمل على SQLite in-memory، ولا تمس قاعدة بيانات السيرفر. التواريخ في الاستجابات ISO 8601 UTC؛ يحولها العميل لتوقيت العرض.
