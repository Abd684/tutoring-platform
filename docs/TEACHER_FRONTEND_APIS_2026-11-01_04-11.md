# ربط واجهات الأستاذ — لقاء

**التنفيذ محلي فقط. هذا العقد يحدّث سلوك انضمام الطالب: لا عضوية قبل موافقة الأستاذ. لم تُرفع هذه التعديلات على السيرفر.**

## عنوان الاتصال والمصادقة
- محلياً: `http://127.0.0.1:8098/api/v1`.
- كل المسارات أدناه نسبة إلى `/api/v1`؛ أرسل `Accept: application/json` و`Content-Type: application/json`.
- باستثناء الدخول، أرسل `Authorization: Bearer <access_token>`.
- مسارات `/teacher/...` تتطلب توكن مدرس فعّال وموثّق. هوية المدرس من التوكن، ولا ترسل teacher_id.
- مسارات `/student/...` تتطلب توكن الطالب وسياق الجهاز الحالي وفق آلية التطبيق القائمة. المدرس لا يرسل الطلب نيابة عن الطالب.
- الدخول الإداري `/admin/login` بالبريد وكلمة المرور مستقل، ولم يتغير.

## ما كان موجوداً وما أُكمل
| الميزة | المسار والطريقة | النتيجة |
|---|---|---|
| دخول الأستاذ بالهاتف | POST `/teacher/login` | موجود ومختبر؛ لم يُنشأ بديل |
| جلسات اليوم | GET `/teacher/sessions?scope=today&timezone=Asia/Damascus` | إضافة فلاتر إلى المسار الموجود |
| طلاب المجموعة | GET `/teacher/groups/{group}/students?status=active` | موجود؛ إضافة فلتر حالة اختياري |
| إضافة طالب مباشرة | POST `/teacher/groups/{group}/students` | موجود؛ يوافق أيضاً على طلبه المعلّق إن وجد |
| مواعيد المجموعة | POST `/teacher/sessions` ثم GET `/teacher/sessions?type=group&type_id={group}` | إعادة استخدام الإنشاء والتعديل، وإضافة فلترة الجلب |
| إرسال طلب انضمام | POST `/student/groups/{group}/join-request` | أصبح طلباً معلّقاً بدلاً من انضمام مباشر |
| متابعة طلبات الطالب | GET `/student/groups/{group}/join-requests` | جديد؛ يرى الطالب سجله فقط |
| جلب طلبات الأستاذ | GET `/teacher/groups/{group}/join-requests` | جديد؛ المعلّقة افتراضياً |
| قبول أو رفض | POST `/teacher/groups/{group}/join-requests/{joinRequest}/approve` أو `/reject` | جديد؛ لصاحب المجموعة فقط |

## 1. الدخول بالهاتف
`POST /teacher/login` دون Bearer.
```json
{"phone":"0990000001","password":"ExamplePassword123!"}
```
`phone`: نص مطلوب حتى 30 محرفاً، كما سجل عند إنشاء الحساب. `password`: نص مطلوب.
الاستجابة **ليست مغلفة في data**: access_token وrefresh_token وtoken_type وexpires_in في الجذر. access_token صالح 900 ثانية؛ refresh_token ينتهي بعد 30 يوماً ويُستبدل عند التجديد عبر `POST /teacher/refresh` مع `{ "refresh_token": "..." }`.
حساب الأستاذ قيد التوثيق قد ينجح بالدخول لكن يرجع can_teach=false؛ خدمات التدريس تعيد 403 TEACHER_NOT_VERIFIED إلى حين التفعيل. `/teacher/me` لبيانات المستخدم وملف المدرس.
مثال ناتج فعلي من اختبار مع إخفاء التوكنات:

```json
{
  "message": "Login successful",
  "access_token": "[REDACTED_TEST_TOKEN]",
  "refresh_token": "[REDACTED_TEST_TOKEN]",
  "token_type": "Bearer",
  "expires_in": 900,
  "user": {
    "name": "Example teacher",
    "email": "mkreiger@example.com",
    "phone": "0990000001",
    "status": "active",
    "role": "teacher",
    "teacher_status": "active",
    "can_teach": true
  }
}
```

الأخطاء: 401 `{ "message": "Invalid credentials" }`، أو 403 للحساب غير الفعّال أو غير المدرس، أو 422 لنقص الحقول. احتفظ بالتوكنين بأمان؛ لا تستخدم email لتسجيل دخول الأستاذ.

## 2. جلسات اليوم وتواريخها
`GET /teacher/sessions?scope=today&timezone=Asia/Damascus`
الحقول الاختيارية:
- scope: القيمة today فقط؛ أو date بصيغة YYYY-MM-DD لتاريخ محدد، **لا تجمعهما**.
- timezone: اسم IANA صالح؛ الافتراضي Asia/Damascus. يؤثر على حساب بداية ونهاية اليوم؛ الاستجابة تظل بتوقيت UTC.
- type: group أو individual.
- type_id: معرف مجموعة أو سجل فردي؛ يتطلب type. المجموعة أو السجل يجب أن يكون ملك الأستاذ وإلا 404.
- status: scheduled أو ongoing أو completed أو cancelled. عند عدم إرساله تُرجع الحالات كلها، بما فيها الملغاة.
- page وper_page: ترقيم الصفحات المعتاد؛ الافتراضي 20، والأقصى 100.
الجلسة التي تتداخل مع اليوم تدخل القائمة حتى إن بدأت في اليوم السابق. البداية والنهاية حدود زمنية محلية صحيحة، وليست مقارنة بتاريخ UTC فقط. النتائج اليومية مرتبة بالبداية ثم المعرف. بدون فلتر يوم يحتفظ المسار بترتيبه السابق: الأحدث معرفاً أولاً.

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "teacher_id": 1,
      "availability_id": null,
      "type": "group",
      "starts_at": "2026-11-01T13:00:00.000000Z",
      "ends_at": "2026-11-01T14:00:00.000000Z",
      "capacity": 3,
      "status": "scheduled",
      "type_id": 1,
      "progress_notes": null,
      "progress_updated_at": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

## 3. جلب أعضاء المجموعة
`GET /teacher/groups/{group}/students?status=active`
- group معرف جدول groups.
- status اختياري: active أو left أو removed أو all. **عند إغفاله يبقى السلوك السابق بإرجاع السجل بكامل حالاته**.
- page وper_page اختياريان.
كل صف عضوية: id معرف العضوية، student_id معرف ملف students، وstudent.user بيانات الاسم والهاتف والصورة. لا تستخدم user.id أو id العضوية مكان student_id.

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "group_id": 1,
      "student_id": 1,
      "status": "active",
      "joined_at": "2026-11-01T12:00:00.000000Z",
      "left_at": null,
      "student": {
        "id": 1,
        "user_id": 2,
        "grade": null,
        "status": "active",
        "school_id": null,
        "user": {
          "id": 2,
          "name": "Example student",
          "phone": "0990000002",
          "region_id": null,
          "avatar_url": null
        },
        "school": null
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

## 4. إضافة طالب من الأستاذ مباشرة
`POST /teacher/groups/{group}/students`
```json
{"student_id":2}
```
student_id عدد صحيح مطلوب من جدول students، وليس users. enrollment_id ممنوع.
201 عند النجاح، 409 للعضو الموجود أو المجموعة المغلقة أو الممتلئة أو تعارض وقت الطالب، و422 للتحقق. إذا للطالب طلب معلّق في المجموعة، يُغلق كمقبول في المعاملة نفسها. هذه إضافة من الأستاذ ولا تنتظر موافقة ثانية منه.

```json
{
  "status": true,
  "message": "Student added to group.",
  "data": {
    "group_id": 1,
    "student_id": 2,
    "status": "active",
    "joined_at": "2026-11-01T12:00:00.000000Z",
    "left_at": null,
    "id": 2
  }
}
```

## 5. حفظ مواعيد المجموعة وجلبها
المقصود هنا جلسات فعلية بتاريخ وبداية ونهاية، وليس فترة توافر عامة للأستاذ أو قالب تكرار أسبوعي.
`POST /teacher/sessions` لكل موعد:
```json
{"type":"group","type_id":1,"starts_at":"2026-11-01T16:00:00+03:00","ends_at":"2026-11-01T17:00:00+03:00"}
```
- type وtype_id وstarts_at وends_at مطلوبة؛ ends_at بعد starts_at.
- capacity اختياري موجب؛ يغطي الأعضاء الحاليين ولا يتجاوز سعة المجموعة.
- availability_id اختياري إذا أردت ربط الموعد بتوافر الأستاذ؛ يجب أن يطابقه.
- progress_notes نص اختياري حتى 10000 محرف.
- لا ترسل group_id أو enrollment_id أو individual_id أو teacher_id؛ الربط هو type/type_id.
الإنشاء يرجع 201، والتداخل مع الأستاذ أو الطلاب يرجع 409 دون إنشاء جلسة ثانية. لكل موعد مستقل session.id. لا يوجد حفظ جماعي أو محرك تكرار أسبوعي جديد؛ أنشئ التواريخ المطلوبة كل واحد بطلبه وتعامل مع أخطاء كل طلب.

```json
{
  "status": true,
  "message": "Session created successfully.",
  "data": {
    "type": "group",
    "type_id": 1,
    "starts_at": "2026-11-01T13:00:00.000000Z",
    "ends_at": "2026-11-01T14:00:00.000000Z",
    "teacher_id": 1,
    "status": "scheduled",
    "capacity": 3,
    "id": 1
  }
}
```

الجلب: `GET /teacher/sessions?type=group&type_id=1`، ويمكن إضافة فلاتر اليوم أو الحالة أو الصفحات أعلاه. هكذا لا تظهر مواعيد مجموعات أخرى.

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "teacher_id": 1,
      "availability_id": null,
      "type": "group",
      "starts_at": "2026-11-01T13:00:00.000000Z",
      "ends_at": "2026-11-01T14:00:00.000000Z",
      "capacity": 3,
      "status": "scheduled",
      "type_id": 1,
      "progress_notes": null,
      "progress_updated_at": null
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

التعديل الموجود: `PATCH /teacher/sessions/{session}` مع starts_at وends_at فقط عند تغيير الوقت؛ يراجع التعارض. الإلغاء الموجود: `POST /teacher/sessions/{session}/cancel` دون body. لا تُحذف الجلسة لإلغائها. الجلسات المقفلة أو المدفوعة القديمة لها قيود التدفق القائم؛ لا تُعدّلها كجلسة جديدة.

## 6. طلب الطالب ثم موافقة الأستاذ أو رفضه
### إرسال الطلب — من الطالب
`POST /student/groups/{group}/join-request`
```json
{"message":"I would like to join"}
```
message اختياري، نص أو null، حتى 1000 محرف. يمكن إرسال `{}`. student_id وstatus ممنوعان؛ الهوية من التوكن.
المسار القديم `POST /student/groups/{group}/join` أصبح alias لنفس الطلب المعلّق، ولا يضيف عضوية مباشرة. هذا تغيير مقصود بناءً على طلب المنتج، ويستبدل وصف الانضمام الفوري في العقود القديمة. العضويات السابقة تبقى كما هي.

```json
{
  "status": true,
  "message": "طلب الانضمام بانتظار الأستاذ.",
  "data": {
    "join_request": {
      "group_id": 1,
      "student_id": 1,
      "message": "I would like to join",
      "status": "pending",
      "updated_at": "2026-11-01T12:00:00.000000Z",
      "created_at": "2026-11-01T12:00:00.000000Z",
      "id": 1
    },
    "requires_teacher_approval": true,
    "seat_reserved": false,
    "payment_method": "cash_to_teacher"
  }
}
```

- أول إرسال 201، وتكرار الطلب المعلّق نفسه 200 بنفس id، دون مضاعفة الطلب أو الإشعار.
- لا تتوقع membership في استجابة الطلب: الطلب ليس عضوية.
- pending لا يحجز مقعداً ولا يزيد members_count، ولا ينشئ اشتراكاً إلكترونياً أو دفعة.
- المجموعة الممتلئة: 409 GROUP_FULL. العضو الفعّال مسبقاً: 409. المجموعة أو المدرس غير متاحين: 409.
- لا تعرض «انضممت» أو تفتح مزايا العضو قبل الموافقة؛ اعرض «بانتظار موافقة الأستاذ».
- قائمة مجموعات الطالب تبقي membership_status لحالة العضوية الفعلية، وتضيف requires_teacher_approval=true وjoin_request لآخر طلب يخصه؛ افحص join_request.status لعرض المعلّق أو المرفوض.

### سجل الطالب
`GET /student/groups/{group}/join-requests?page=1&per_page=20`
الأحدث أولاً، للطالب الحالي فقط. الطالب الآخر لا يرى هذه الطلبات؛ يرجع سجله الخاص، وقد يكون فارغاً.

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "group_id": 1,
      "student_id": 1,
      "status": "pending",
      "message": "I would like to join",
      "review_note": null,
      "reviewed_by": null,
      "reviewed_at": null,
      "created_at": "2026-11-01T12:00:00.000000Z",
      "updated_at": "2026-11-01T12:00:00.000000Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

### جلب طلبات المجموعة — من الأستاذ
`GET /teacher/groups/{group}/join-requests?status=pending`
status اختياري: pending أو approved أو rejected أو all؛ الافتراضي pending. page وper_page اختياريان. تتضمن بيانات الطالب اللازمة للمراجعة؛ لا ترجع تجزئة كلمة المرور.

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "group_id": 1,
      "student_id": 1,
      "status": "pending",
      "message": "I would like to join",
      "review_note": null,
      "reviewed_by": null,
      "reviewed_at": null,
      "created_at": "2026-11-01T12:00:00.000000Z",
      "updated_at": "2026-11-01T12:00:00.000000Z",
      "student": {
        "id": 1,
        "user_id": 2,
        "grade": null,
        "status": "active",
        "school_id": null,
        "user": {
          "id": 2,
          "name": "Example student",
          "phone": "0990000002",
          "avatar_url": null
        }
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

### قبول الطلب
`POST /teacher/groups/{group}/join-requests/{joinRequest}/approve`
Body: `{}` أو `{ "review_note": "ملاحظة اختيارية" }`؛ النص حتى 1000 محرف. joinRequest معرف الطلب من القائمة، وليس معرف العضوية.

```json
{
  "status": true,
  "data": {
    "join_request": {
      "id": 1,
      "group_id": 1,
      "student_id": 1,
      "status": "approved",
      "message": "I would like to join",
      "review_note": null,
      "reviewed_by": 1,
      "reviewed_at": "2026-11-01T12:00:00.000000Z",
      "created_at": "2026-11-01T12:00:00.000000Z",
      "updated_at": "2026-11-01T12:00:00.000000Z"
    },
    "membership": {
      "id": 1,
      "group_id": 1,
      "student_id": 1,
      "status": "active",
      "joined_at": "2026-11-01T12:00:00.000000Z",
      "left_at": null
    }
  }
}
```

القبول يفحص الطالب والحسابات والمادة والسعة وتعارض الجلسات لحظة التنفيذ، ثم ينشئ/يعيد تفعيل العضوية داخل معاملة واحدة. إذا بقي مقعد واحد وقُبل طلبان معاً، واحد فقط ينجح والثاني 409 GROUP_FULL ويظل معلّقاً. تكرار نفس قرار القبول 200 دون عضوية أو إشعار مكرر. إعادة قبول طلب قديم بعد أن ترك الطالب المجموعة لا تعيد عضويته تلقائياً؛ الطلب القديم محفوظ كقرار تاريخي.

### رفض الطلب
`POST /teacher/groups/{group}/join-requests/{joinRequest}/reject`
```json
{"review_note":"The level is not suitable"}
```
review_note **مطلوب** وغير فارغ حتى 1000 محرف. لا ينشأ طالب جديد أو عضوية أو دفع. يمكن للطالب لاحقاً تقديم طلب جديد بمعرف جديد مع بقاء الرفض في التاريخ.

```json
{
  "status": true,
  "data": {
    "join_request": {
      "id": 2,
      "group_id": 1,
      "student_id": 2,
      "status": "rejected",
      "message": null,
      "review_note": "The level is not suitable",
      "reviewed_by": 1,
      "reviewed_at": "2026-11-01T12:00:00.000000Z",
      "created_at": "2026-11-01T12:00:00.000000Z",
      "updated_at": "2026-11-01T12:00:00.000000Z"
    },
    "membership": null
  }
}
```

تكرار نفس قرار الرفض يعيد 200 دون إعادة الإشعار. محاولة تحويل قرار نهائي إلى العكس تعيد 409. الأستاذ الآخر أو عدم تطابق الطلب مع المجموعة يرجع 404. الطالب لا يملك مسار القرار. الطالب غير الفعّال لا يقبل، وتظل المعاملة دون عضوية جزئية. تصل إشعارات قاعدة البيانات من خلال مسارات الإشعارات القائمة؛ لا يوجد وعد Push أو SMS أو بريد جديد.

## أخطاء التعامل المشتركة
- 401: لا يوجد توكن صالح؛ جدّد أو اطلب الدخول حسب تدفق التطبيق.
- 403: الدور غير صحيح أو الحساب/الطالب غير فعّال؛ الأستاذ غير الموثق يرجع TEACHER_NOT_VERIFIED.
- 404: المورد غير موجود أو لا يتبع الأستاذ، ويُحجب أيضاً طلب المجموعة الأخرى.
- 409: تعارض وقت، مجموعة ممتلئة أو مغلقة، عضوية قائمة، أو قرار طلب نهائي متعارض.
- 422: خطأ حقول؛ اعرض errors حسب اسم الحقل، ولا تفترض وجود status في كل أخطاء Laravel.
مثال تحقق فعلي:

```json
{
  "message": "The timezone field must be a valid timezone.",
  "errors": {
    "timezone": [
      "The timezone field must be a valid timezone."
    ]
  }
}
```

مثال تعارض جدولة فعلي:

```json
{
  "message": "Teacher or student already has an overlapping session."
}
```

مثال امتلاء المجموعة:
```json
{"status":false,"code":"GROUP_FULL","message":"Group is full."}
```
لا تبنِ منطقاً على ترجمة message؛ اعتمد HTTP status وcode عند توفره، ثم errors لحقول التحقق.

## التحقق والنشر
- نجح 221 اختباراً و3824 تحققاً على SQLite، إضافة إلى استخراج الأمثلة عبر طلبات HTTP داخل اختبارات فعلية.
- نجح اختبار MariaDB بعمليتي قبول متوازيتين لآخر مقعد: قبول واحد، تعارض واحد، عضوية واحدة. فهرس قاعدة البيانات يمنع طلبين معلّقين لنفس الطالب والمجموعة.
- الترحيل الجديد إضافي فقط: `2026_11_01_000001_add_group_join_requests.php`. ينشئ جدول طلبات الانضمام ولا يحول العضويات السابقة إلى طلبات.
- لا علاقة لهذه المهمة بملاحظات تدقيق لوحة الإدارة المؤجلة، ولم تُنفّذ منها تغييرات.
- محلي فقط؛ قبل تجربة هذه المسارات على الإنتاج يجب نشر الملفات وترحيل قاعدة السيرفر بتنسيق مستقل. رابط localhost لا يعمل على هاتف Flutter منفصل؛ في الشبكة المحلية استخدم عنوان الجهاز المناسب وفق إعداد تشغيلك.
