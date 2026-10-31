> تحديث 2026-10-31: هذا وصف مرحلة سابقة. العقد النهائي الحالي هو [FRONTEND_CONTRACT_FINAL_AR.md](FRONTEND_CONTRACT_FINAL_AR.md)، والفهرس الكامل [APIS_FULL_AR.md](APIS_FULL_AR.md).

# تسليم الفرونت — لقاء — 2026-10-31

هذه مواصفات الكود المحلي بعد تعديل الدخول والجلسات. لم تُطبّق Migrations على القاعدة الفعلية ضمن هذه المحادثة. يجب تنسيق نشر الباك مع تحديث الفرونت.

ملفات المرجع:

- `DATABASE_REFERENCE_AR.md`: جميع الجداول والحقول والعلاقات من الصفر، 56 جدولاً و412 عموداً.
- `DATABASE_SCHEMA_CURRENT.json`: نفس البنية بشكل آلي، مع nullability وdefaults وFK/indexes وcasts والحقول المخفية والعلاقات و135 مسار API مسجلاً.
- `API_ROUTES_CURRENT.md`: قائمة مسارات API الموجودة بالكود حالياً.

## 1. ما تغيّر وما انحذف

| الجزء | القديم | الحالي | تعديل الفرونت |
|---|---|---|---|
| تسجيل الدخول | email + password | phone + password | غيّر حقل الطلب إلى phone، ويكون String |
| التسجيل | البريد مطلوب | البريد اختياري nullable، الهاتف فريد | أزل إلزامية البريد وأبقِ الاسم وكلمة المرور وباقي متطلبات الملف |
| مجموعة الطلاب | GroupEnrollment / group_enrollments | GroupStudent / group_students | استبدل الموديل؛ العضو يرتبط بالطالب مباشرة |
| العضوية | group_id + enrollment_id | group_id + student_id | لا تعتمد على شراء المحتوى للانضمام |
| التدريس الفردي | تمثله علاقة الجلسة بالتسجيل | Individual / individuals | موديل جديد لطالب ومادة مدرس، وله عدة جلسات |
| Session | enrollment_id وgroup_id | type + type_id فقط | احذف الحقلين من Session ومن طلب إنشاء الجلسة |
| Session.type | GROUP / INDIVIDUAL | group / individual | استخدم lowercase حرفياً |
| تقدم التدريس | غير موجود | progress_notes وprogress_updated_at داخل Session | ملاحظة يكتبها الأستاذ، لا علاقة لها بالدروس الإلكترونية |
| Attendance | enrollment_id | student_id | عدّل الحقل في الموديل والطلب |
| Enrollment ORM | sessions وattendance وgroupEnrollments | أزيلت هذه العلاقات | لا تطلب أو تستخرج الجلسات منها |
| PaidBooking | لا يحدد الفردي/المادة | teacher_subject_id وindividual_id إضافيان | قابلان لـnull بسبب السجلات القديمة والحجز غير المنفذ |
| PaymentCheckout | الحجز المدفوع يحدد وقت المدرّس فقط | teacher_subject_id إضافي | مطلوب في طلب checkout من نوع lesson_booking |
| عدد أعضاء Group | active_enrollments_count | active_members_count | بدّل مفتاح JSON |
| فترات الحجز | eligible_enrollment_ids | eligible_individual_ids | القيم تشير إلى individuals.id |
| Calendar meta | has_eligible_enrollment_in_period | has_eligible_individual_in_period | بدّل مفتاح JSON |
| قائمة جلسات الطالب | enrollment object | type_id وteacher_subject_id وsubject_name والتقدم | استخدم DTO الجلسة المختصر أدناه |
| ContentAsset | موجود | لم يتغير | لا تضف له تعديلات بسبب هذا العمل |

لم يُحذف جدول enrollments نفسه. يبقى للمحتوى الإلكتروني والسجل المالي للحجز المدفوع. لا تحذف كل ظهور لـenrollment_id في المشروع: ما زال موجوداً مثلاً في escrow_transactions ومسارات دفع الاشتراكات. المحذوف هو ارتباطه المباشر بالجلسات والحضور وعضوية المجموعات.

لم نضف جدول teaching_progress، ولا lesson_id أو content_id لتسجيل تقدم الأستاذ.

## 2. الموديلات الأساسية بعد التعديل

### Individual

| الحقل | النوع المنطقي | null | ملاحظة |
|---|---|---|---|
| id | int | لا | من السيرفر |
| student_id | int | لا | students.id |
| teacher_subject_id | int | لا | teacher_subjects.id، ومنها يُعرف المدرّس والمادة |
| status | String | لا | active / completed / cancelled |
| starts_at | DateTime | نعم | بداية فترة التدريس إن حُددت |
| ends_at | DateTime | نعم | النهاية إن حُددت |
| notes | String | نعم | ملاحظات على التدريس الفردي ككل |
| created_at | DateTime | نعم في DB | يولده السيرفر |
| updated_at | DateTime | نعم في DB | يولده السيرفر |

العلاقات عند تحميلها: student، teacher_subject، sessions، paid_bookings. سجل الفردي ليس حصة واحدة؛ يمكن أن يتبعه عدة Sessions. لا ترسل teacher_id أو enrollment_id عند إنشائه.

### GroupStudent

`id: int`, `group_id: int`, `student_id: int`, `status: active|left|removed`, `joined_at: DateTime?`, `left_at: DateTime?`.

الزوج group_id + student_id فريد. إعادة إضافة طالب غادر تعيد تفعيل سجل عضويته بدلاً من إنشاء تكرار. user_id ليس student_id.

### Session

`id: int`, `teacher_id: int`, `availability_id: int?`, `type: group|individual`, `type_id: int`, `starts_at: DateTime`, `ends_at: DateTime`, `capacity: int`, `status: scheduled|ongoing|completed|cancelled`, `progress_notes: String?`, `progress_updated_at: DateTime?`.

- type=group: type_id هو groups.id.
- type=individual: type_id هو individuals.id.
- capacity للفردي دائماً 1؛ لا يحوّله إرسال سعة أكبر إلى جماعي.
- teacher_id موجود بالاستجابة/القاعدة لكن السيرفر يستخرجه من حساب المدرّس عند الإنشاء؛ لا ترسله في طلب المدرّس.
- progress_updated_at يولده السيرفر ولا يرسله الفرونت للتعديل.
- لا حقول group_id أو enrollment_id داخل Session الجديدة.
- علاقات ORM عند تحميلها: target، teacher، availability، attendance، paid_booking. target يختلف حسب type وقد لا يُحمّل أصلاً.

### Attendance

`id: int`, `session_id: int`, `student_id: int`, `status: present|absent|late|excused`, `check_in_at: DateTime?`, `check_out_at: DateTime?`, `note: String?`.

الزوج session_id + student_id فريد. تسجيل الحضور مرة ثانية يعدّل السجل نفسه. فقط طالب الفردي أو عضو المجموعة الفعال يمكن تسجيل حضوره من مسار الأستاذ.

### Enrollment

يبقى: `id`, `student_id`, `teacher_id`, `enrollable_type`, `enrollable_id`, `price`, `status`, `starts_at?`, `expires_at?`.

أنواع enrollable_type: teacher_subject / unit / content / paid_booking. هذه ليست أنواع Session. ORM لم يعد يحتوي sessions أو attendance أو groupEnrollments. لا تفترض أن إلغاء اشتراك محتوى يزيل الطالب من مجموعته أو يلغي جلساته المستقلة.

## 3. الدخول والتسجيل

### دخول الطالب

`POST /api/v1/auth/login`

```json
{
  "phone": "0991234567",
  "password": "Password123!",
  "device_uuid": "device-uuid",
  "platform": "android"
}
```

platform: android أو ios. حقول الجهاز الإضافية الحالية manufacturer/model/os_version/app_version/public_key ما زالت اختيارية. refresh/logout وربط الجهاز لم يُحذفا. نقل الجهاز `POST /api/v1/auth/device/transfer` يستخدم phone بدل email مع كلمة المرور وحقول الجهاز الهدف.

### دخول المدرّس

`POST /api/v1/teacher/login`

```json
{"phone":"0991234567","password":"Password123!"}
```

### تسجيل الطالب

`POST /api/v1/auth/register`

```json
{
  "name": "طالب تجريبي",
  "phone": "0991234567",
  "password": "Password123!",
  "password_confirmation": "Password123!",
  "region_id": 1,
  "school_id": 1,
  "grade": "12"
}
```

email يمكن حذفه أو إرساله null؛ إذا أُرسل يجب أن يكون بريداً صحيحاً وغير مستخدم. school_id يجب أن يتبع region_id. governorate_id ليس جزءاً من طلب التسجيل. الهاتف نص بحد أقصى 30 في تسجيل الطالب، و20 في تسجيل المدرّس؛ أبقِ الصفر الأول. لا توجد خطوة OTP جديدة.

### تسجيل المدرّس

`POST /api/v1/teacher/register`؛ المطلوب name وphone وpassword وpassword_confirmation وregion_id، والاختياري email وspecialization وbio. المدرّس الجديد يبدأ pending_review. التسجيل لا يصدر access/refresh tokens تلقائياً؛ أبقِ خطوة الدخول الحالية.

## 4. مسارات التدريس الفردي

تحتاج Bearer token لمدرّس فعال، وتُحصر بسجلاته.

| الطريقة | المسار | الوظيفة |
|---|---|---|
| GET | /api/v1/teacher/individuals | قائمة الفردي؛ pagination |
| POST | /api/v1/teacher/individuals | إنشاء ارتباط تدريس فردي |
| GET | /api/v1/teacher/individuals/{individual} | التفاصيل والجلسات |
| PATCH أو PUT | /api/v1/teacher/individuals/{individual} | تعديل الحالة/الفترة/الملاحظات |

إنشاء:

```json
{
  "student_id": 12,
  "teacher_subject_id": 7,
  "starts_at": null,
  "ends_at": null,
  "notes": "مراجعة أسبوعية"
}
```

لا تغيّر student_id أو teacher_subject_id بطلب التعديل. حالات الإكمال والإلغاء وتغيير الفترة تُرفض بـ409 إن كانت هناك جلسات scheduled/ongoing ستصبح غير صالحة؛ ألغِ أو أعد جدولة الجلسات أولاً. لا يوجد DELETE للفردي بهذا الـAPI.

## 5. أعضاء المجموعة

المسار القديم `/api/v1/teacher/groups/{id}/enrollments` أُزيل.

إضافة: `POST /api/v1/teacher/groups/{id}/students`

```json
{"student_id":12}
```

إزالة: `DELETE /api/v1/teacher/groups/{id}/students/{student}`. قيمة student هي students.id وليست membership.id. الإزالة تحفظ السجل بحالة removed. لا ترسل enrollment_id. يُفحص نشاط الطالب وسعة المجموعة وتعارض جلساته. مسار الإدارة على الويب له عقد مختلف؛ لا تستخدمه كـAPI للموبايل.

## 6. الجلسات والتقدم والحضور

إنشاء جلسة: `POST /api/v1/teacher/sessions`.

```json
{
  "type": "individual",
  "type_id": 5,
  "starts_at": "2026-11-03T10:00:00+03:00",
  "ends_at": "2026-11-03T11:00:00+03:00",
  "availability_id": null,
  "progress_notes": null
}
```

للجماعي type=group وtype_id معرّف المجموعة. teacher_id وgroup_id وenrollment_id وindividual_id حقول ممنوعة في هذا الطلب؛ الفردي يُحدد عبر type_id فقط.

تعديل الموعد: `PATCH /api/v1/teacher/sessions/{session}`. عند تغيير type أرسل type_id أيضاً. الجلسة ذات حضور مسجل لا يمكن تغيير المشاركين المرتبطين بها. الجلسات المدفوعة تستخدم مسار إعادة الجدولة المالي. الإلغاء عبر `POST /api/v1/teacher/sessions/{session}/cancel` والإكمال عبر `POST /api/v1/teacher/sessions/{session}/complete`؛ لا ترسل cancelled كحالة في PATCH العادي.

تقدم التدريس: `PATCH /api/v1/teacher/sessions/{session}/progress`

```json
{"progress_notes":"وصلنا للصفحة ٣٢ وحلّينا التمرين الرابع"}
```

للمسح أرسل `{ "progress_notes": null }`. الحد الأقصى 10000 حرف. يمكن التعديل على جلسة completed أو مدفوعة، ولا يمكن على cancelled. PATCH الجلسة العادي يقبل أيضاً طلباً فيه progress_notes وحدها لهذه الحالة. لا توجد روابط content_id أو lesson_id لهذا التقدم.

الحضور: `POST /api/v1/teacher/sessions/{id}/attendance`

```json
{
  "records": [
    {"student_id":12,"status":"present","note":null},
    {"student_id":13,"status":"late","note":"تأخر عشر دقائق"}
  ]
}
```

لا تكرر student_id داخل records، ولا ترسل enrollment_id. check_in_at وcheck_out_at اختياريان؛ وقت الخروج يجب ألا يسبق الدخول. المثال متعدد الطلاب للجلسة الجماعية فقط.

## 7. حجز الطالب والتقويم

كلا المسارين يستخدم individual_id:

- `POST /api/v1/teacher-availabilities/{availability}/book`
- `POST /api/v1/teachers/{teacher}/bookings`؛ أضف availability_id للجسم في هذا المسار.

```json
{
  "individual_id":5,
  "starts_at":"2026-11-03T10:00:00+03:00",
  "ends_at":"2026-11-03T11:00:00+03:00"
}
```

individual_id هنا يختلف عن طلب الأستاذ الذي يستخدم type + type_id. حجز الطالب يتطلب ارتباط Individual فعالاً يخصه ويخص المدرّس؛ Enrollment إلكتروني وحده لا يكفي. الفردي الناتج عن درس مدفوع لا يمكن استعماله لحجز إضافي عبر المسار العادي.

حقول الاستجابات:

- available-slots وavailable-sessions وفترات calendar تستخدم eligible_individual_ids.
- meta في calendar يستخدم has_eligible_individual_in_period.
- الجداول الزمنية لا تعرض بيانات طلاب آخرين.
- الإلغاء: `DELETE /api/v1/sessions/{id}/booking`، يحفظ سجل الجلسة.

## 8. DTO جلسات الطالب

`GET /api/v1/student/bookings?scope=upcoming|history|all` يعيد `{ "data": [...] }` بدون افتراض وجود meta أو status في هذا المسار. التفاصيل `GET /api/v1/student/sessions/{session}` تعيد success wrapper.

شكل عنصر القائمة الحالي:

```json
{
  "id":31,
  "type":"individual",
  "type_id":5,
  "starts_at":"2026-11-03T07:00:00.000000Z",
  "ends_at":"2026-11-03T08:00:00.000000Z",
  "status":"scheduled",
  "progress_notes":null,
  "progress_updated_at":null,
  "teacher":{"id":4,"name":"مدرّس تجريبي"},
  "teacher_subject_id":7,
  "subject_name":"رياضيات"
}
```

لا يوجد enrollment object هنا. هذا DTO مختصر وليس Session database row؛ لا تفترض وجود availability_id أو capacity أو teacher_id على المستوى الأعلى. subject_name واسم الأستاذ قد يكونان null إن لم تتوفر العلاقة.

## 9. الحجز المدفوع

طلب `POST /api/v1/payments/checkout` عندما يكون service_type=lesson_booking أصبح:

```json
{
  "service_type":"lesson_booking",
  "service_id":8,
  "teacher_subject_id":7,
  "starts_at":"2026-11-03T10:00:00+03:00",
  "ends_at":"2026-11-03T11:00:00+03:00"
}
```

service_id هنا معرّف TeacherAvailability، وteacher_subject_id يجب أن يكون مادة لنفس المدرّس. اسم service_id في شراء المحتوى له معنى آخر حسب service_type.

PaymentCheckout.teacher_subject_id قابل لـnull في DB للأنواع الأخرى أو البيانات القديمة، لكنه مطلوب في طلب lesson_booking الجديد. PaidBooking.individual_id قد يكون null قبل تأكيد الحجز، ثم يُربط بفردي عند إنشاء الجلسة. teacher_subject_id وindividual_id إضافتان لموديل PaidBooking؛ لا تزيل حقول الدفع أو حالة حجز الوقت.

حالات PaidBooking: HELD / EXPIRED / CONFIRMED / NEEDS_RESCHEDULE / REJECTED / CANCELLED / REFUNDED. لا تخلطها مع Session.status أو Payment.status أو payment_status المعروض من DTO.

المسارات المالية الحالية ما زالت مسؤولة عن الموافقة والاسترداد وإعادة الجدولة. إلغاء جلسة واحدة لا يلغي كل التدريس الفردي تلقائياً. يوجد سجل Enrollment مالي للحجز المدفوع داخل الباك؛ الفصل المقصود هو غياب enrollment_id من Session وAttendance وعضوية المجموعة.

## 10. التغليف والأخطاء

أغلب CRUD الجديد: `{ "status": true, "data": {...}, "message": "..." }`، وقد تغيب message. القوائم العامة تعيد data وmeta(current_page, per_page, total, last_page). لا تعمّم هذا الغلاف على تسجيل الدخول والتسجيل أو قائمة جلسات الطالب؛ لكل منها الاستجابة الخاصة الحالية.

- 401: غير مسجل أو بيانات دخول خاطئة.
- 403: غير مخوّل لهذا المسار أو الحساب غير فعال.
- 404: السجل غير موجود أو لا يخص الحساب.
- 409: تعارض موعد/سعة/حالة أو مسار مالي لا يسمح بالإجراء.
- 422: حقول أو حالة غير صالحة؛ قد تتضمن errors حسب مصدر الخطأ.

المسار الأقدم `/teachers/{teacher}/bookings` قد يعيد 422 عند تعارض الحجز، بينما مسار teacher-availabilities يعيد 409؛ تعامل مع الاثنين. لا تعتمد على نص الرسالة الإنجليزي كمفتاح منطقي.

## 11. ترتيب تحديث الفرونت

1. تحديث Auth requests ليستخدم phone وإلغاء إلزامية البريد في التسجيل.
2. إضافة Individual وGroupStudent وحذف GroupEnrollment من نماذج عضوية المجموعة.
3. تحديث Session.type ليكون lowercase وإضافة type_id والتقدم وحذف group_id/enrollment_id.
4. تحديث Attendance إلى student_id.
5. تحديث مسارات الأعضاء وطلبات حجز الطالب وcheckout المدفوع.
6. تحديث DTO قائمة الجلسات وأسماء عدادات المجموعة وحقول التقويم.
7. بناء/مراجعة بقية الموديلات من DATABASE_REFERENCE_AR.md، مع الالتزام بالاستجابات الفعلية؛ الحقول المخفية والداخلية ليست حقول واجهة.

البيانات والأوقات في الأمثلة توضيحية، وليست معرّفات أو مواعيد محجوزة فعلياً.
