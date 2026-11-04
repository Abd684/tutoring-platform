# ربط واجهات الأستاذ — لقاء

**منشور ومتحقق منه على السيرفر بتاريخ 2026-11-01 الساعة 04-41 بتوقيت دمشق. لا عضوية للطالب قبل موافقة الأستاذ.**

أمثلة الطلبات توضيحية. أمثلة الاستجابات الأساسية مأخوذة من فحص داخل Laravel على السيرفر نفسه باستخدام بيانات تحقق ضمن معاملة جرى التراجع عنها بالكامل. معرفات هذه الأمثلة لا تمثل بيانات قابلة لإعادة الاستخدام؛ اجلب معرفات حسابك الفعلية من القوائم. التوكنات مخفية، ولم تُترك حسابات تجريبية.

## عنوان الاتصال والمصادقة
- الإنتاج: `https://mandobe.net/liqaa/api/v1`.
- المحلي: `http://127.0.0.1:8098/api/v1`.
- **لواجهات الأستاذ الجديدة أرسل `X-Liqaa-Teaching-Contract: 2`** مع كل الطلبات. بدونها تحتفظ استجابات جلسات الأستاذ بالصيغة القديمة: type بحروف كبيرة وgroup_id/enrollment_id؛ مع الترويسة تعتمد type=group|individual وtype_id. لا تخلط الصيغتين في DTO واحد.
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
`phone`: نص مطلوب في الربط الجديد حتى 30 محرفاً، كما سجل عند إنشاء الحساب. `password`: نص مطلوب.
الاستجابة **ليست مغلفة في data**: access_token وrefresh_token وtoken_type وexpires_in في الجذر. access_token صالح 900 ثانية؛ refresh_token ينتهي بعد 30 يوماً ويُستبدل عند التجديد عبر `POST /teacher/refresh` مع `{ "refresh_token": "..." }`.
حساب الأستاذ قيد التوثيق قد ينجح بالدخول لكن يرجع can_teach=false؛ خدمات التدريس تعيد 403 TEACHER_NOT_VERIFIED إلى حين التفعيل. `/teacher/me` لبيانات المستخدم وملف المدرس.
مثال فعلي من السيرفر مع إخفاء التوكنات:

```json
{
  "message": "Login successful",
  "access_token": "[REDACTED]",
  "refresh_token": "[REDACTED]",
  "token_type": "Bearer",
  "expires_in": 900,
  "user": {
    "name": "Deployment check teacher",
    "email": "qa-t-7f322cf58d38@example.invalid",
    "phone": "QA-T-7f322cf58d38",
    "status": "active",
    "role": "teacher",
    "teacher_status": "active",
    "can_teach": true
  }
}
```

الأخطاء: 401 `{ "message": "Invalid credentials" }`، أو 403 للحساب غير الفعّال أو غير المدرس، أو 422 لنقص الحقول. احتفظ بالتوكنين بأمان؛ استخدم phone في الربط الجديد. بقي إرسال email وكلمة المرور مدعوماً للعملاء المنشورين سابقاً؛ عند إرسال الحقلين تُستخدم phone ولا يُجرّب البريد تلقائياً عند فشلها.

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
      "id": 45,
      "teacher_id": 14,
      "availability_id": null,
      "type": "group",
      "starts_at": "2026-11-01T03:37:00.000000Z",
      "ends_at": "2026-11-01T04:37:00.000000Z",
      "capacity": 3,
      "status": "scheduled",
      "type_id": 22,
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
      "id": 16,
      "group_id": 22,
      "student_id": 26,
      "status": "active",
      "joined_at": "2026-11-01T01:37:02.000000Z",
      "left_at": null,
      "student": {
        "id": 26,
        "user_id": 41,
        "grade": null,
        "status": "active",
        "school_id": null,
        "user": {
          "id": 41,
          "name": "Deployment check student 1",
          "phone": "QA-S-1-7f322cf58d38",
          "region_id": null,
          "avatar_url": null
        },
        "school": null
      }
    },
    {
      "id": 15,
      "group_id": 22,
      "student_id": 25,
      "status": "active",
      "joined_at": "2026-11-01T01:37:02.000000Z",
      "left_at": null,
      "student": {
        "id": 25,
        "user_id": 40,
        "grade": null,
        "status": "active",
        "school_id": null,
        "user": {
          "id": 40,
          "name": "Deployment check student 0",
          "phone": "QA-S-0-7f322cf58d38",
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
    "total": 2,
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
    "group_id": 22,
    "student_id": 26,
    "status": "active",
    "joined_at": "2026-11-01T01:37:02.000000Z",
    "left_at": null,
    "id": 16
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
    "type_id": 22,
    "starts_at": "2026-11-01T03:37:00.000000Z",
    "ends_at": "2026-11-01T04:37:00.000000Z",
    "teacher_id": 14,
    "status": "scheduled",
    "capacity": 3,
    "id": 45
  }
}
```

الجلب: `GET /teacher/sessions?type=group&type_id=1`، ويمكن إضافة فلاتر اليوم أو الحالة أو الصفحات أعلاه. هكذا لا تظهر مواعيد مجموعات أخرى.

```json
{
  "status": true,
  "data": [
    {
      "id": 45,
      "teacher_id": 14,
      "availability_id": null,
      "type": "group",
      "starts_at": "2026-11-01T03:37:00.000000Z",
      "ends_at": "2026-11-01T04:37:00.000000Z",
      "capacity": 3,
      "status": "scheduled",
      "type_id": 22,
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
      "group_id": 22,
      "student_id": 25,
      "message": "Deployment verification",
      "status": "pending",
      "updated_at": "2026-11-01T01:37:02.000000Z",
      "created_at": "2026-11-01T01:37:02.000000Z",
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
      "group_id": 22,
      "student_id": 25,
      "status": "pending",
      "message": "Deployment verification",
      "review_note": null,
      "reviewed_by": null,
      "reviewed_at": null,
      "created_at": "2026-11-01T01:37:02.000000Z",
      "updated_at": "2026-11-01T01:37:02.000000Z",
      "student": {
        "id": 25,
        "user_id": 40,
        "grade": null,
        "status": "active",
        "school_id": null,
        "user": {
          "id": 40,
          "name": "Deployment check student 0",
          "phone": "QA-S-0-7f322cf58d38",
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
      "group_id": 22,
      "student_id": 25,
      "status": "approved",
      "message": "Deployment verification",
      "review_note": null,
      "reviewed_by": 39,
      "reviewed_at": "2026-11-01T01:37:02.000000Z",
      "created_at": "2026-11-01T01:37:02.000000Z",
      "updated_at": "2026-11-01T01:37:02.000000Z"
    },
    "membership": {
      "id": 15,
      "group_id": 22,
      "student_id": 25,
      "status": "active",
      "joined_at": "2026-11-01T01:37:02.000000Z",
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
      "group_id": 22,
      "student_id": 26,
      "status": "rejected",
      "message": null,
      "review_note": "Test rejection",
      "reviewed_by": 39,
      "reviewed_at": "2026-11-01T01:37:02.000000Z",
      "created_at": "2026-11-01T01:37:02.000000Z",
      "updated_at": "2026-11-01T01:37:02.000000Z"
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

## تشغيل Flutter خطوة بخطوة
1. سجّل الأستاذ بـphone/password واحفظ access_token وrefresh_token في مخزن التطبيق الآمن. لا تضعهما في سجلات الأخطاء أو ملف إعدادات عام. أرسل توكن الأستاذ إلى مسارات teacher، وتوكن الطالب المرتبط بجهازه إلى مسارات student.
2. اجلب `GET /teacher/groups` للحصول على groups.id. لا تستخدم teacher_subject_id مكان groupId. كل شاشة مجموعة تحتفظ بمعرف مجموعتها، ولا ترسل teacher_id.
3. شاشة اليوم تجلب sessions مع scope=today وtimezone=Asia/Damascus؛ حوّل starts_at/ends_at من UTC إلى وقت عرض الهاتف. شاشة جدول المجموعة تضيف type=group وtype_id=groupId. لا تكتفِ بأول صفحة إذا meta.last_page أكبر من 1.
4. تبويب الطلاب يجلب students?status=active، ويعرض student.user.name وphone والصورة إن وجدت. يوجد فرق بين User.id وStudent.id وعضوية المجموعة id؛ الإضافة تستخدم student_id.
5. الطالب يضغط «طلب انضمام»: POST join-request. عند 201 أو 200 اعرض pending. لا تضف طالباً محلياً لقائمة الأعضاء ولا تفتح محتوى مدفوعاً اعتماداً على الطلب. الطلب لا ينشئ اشتراك مادة إلكترونية.
6. الأستاذ يفتح تبويب «طلبات الانضمام»: GET join-requests?status=pending. زر قبول يرسل POST approve مع `{}`؛ زر رفض يفتح حقل سبب إلزامياً ويرسل POST reject. عطّل الزر أثناء الطلب؛ بعد النجاح أعد جلب الطلبات والطلاب وأعدادهم. لا ترسل status=approved في body.
7. بعد عودة الطالب إلى الشاشة أو تحديثها، اجلب سجل طلبه ومجموعاته مجدداً. pending: انتظار، approved: حدّث العضوية الفعلية، rejected: اعرض review_note مع السماح بطلب جديد. لا يوجد بث لحظي أو Push جديد مضمون؛ استخدم تحديث الشاشة أو التحديث الدوري الذي يعتمده التطبيق.
8. الإضافة المباشرة من الأستاذ تستخدم POST students. إذا كان هناك طلب معلق يصير مقبولاً تلقائياً؛ حدّث قائمتي الطلاب والطلبات معاً.
9. لكل موعد مجموعة أرسل POST sessions واحداً. عند 201 خزّن session.id من الاستجابة. التعديل PATCH بنفس session.id؛ عند فشل طلب ضمن عدة مواعيد، أبقِ الناجح وحدد الفاشل، ولا تعِد إنشاء الناجح عشوائياً.
10. عند 409 أعد الجلب واعرض السبب؛ قد يكون آخر مقعد حُجز أو يوجد تعارض. عند 422 اربط errors باسم الحقل. عند 404 لا تعرض مورد أستاذ آخر. عند 403 TEACHER_NOT_VERIFIED اعرض انتظار التوثيق. عند 401 جرّب تجديد التوكن مرة واحدة فقط قبل طلب الدخول.

### مثال Dio قابل للتوصيل بمخزن التوكنات الموجود
المثال يفترض أن مشروع Flutter يحتوي Dio مسبقاً. لا توجد كلمات مرور أو توكنات ثابتة. استخدم instance مستقل للطالب إذا كان توكنه مختلفاً. أسماء الدوال داخل Flutter أمثلة، والمسارات هي المنشورة فعلياً.
```dart
import 'package:dio/dio.dart';

class TeacherApi {
  TeacherApi(String accessToken)
      : dio = Dio(BaseOptions(
          baseUrl: 'https://mandobe.net/liqaa/api/v1/',
          headers: {
            'Accept': 'application/json',
            'Content-Type': 'application/json',
            'Authorization': 'Bearer $accessToken',
            'X-Liqaa-Teaching-Contract': '2',
          },
        ));
  final Dio dio;

  Future<Map<String, dynamic>> today({int page = 1}) async =>
      Map<String, dynamic>.from((await dio.get('teacher/sessions',
        queryParameters: {'scope': 'today', 'timezone': 'Asia/Damascus',
          'page': page, 'per_page': 20})).data);

  Future<Map<String, dynamic>> students(int groupId, {int page = 1}) async =>
      Map<String, dynamic>.from((await dio.get('teacher/groups/$groupId/students',
        queryParameters: {'status': 'active', 'page': page})).data);

  Future<Map<String, dynamic>> joinRequests(int groupId, {int page = 1}) async =>
      Map<String, dynamic>.from((await dio.get('teacher/groups/$groupId/join-requests',
        queryParameters: {'status': 'pending', 'page': page})).data);

  Future<void> approve(int groupId, int requestId) async {
    await dio.post('teacher/groups/$groupId/join-requests/$requestId/approve',
      data: <String, dynamic>{});
  }
  Future<void> reject(int groupId, int requestId, String reason) async {
    final note = reason.trim();
    if (note.isEmpty) throw ArgumentError('سبب الرفض مطلوب');
    await dio.post('teacher/groups/$groupId/join-requests/$requestId/reject',
      data: {'review_note': note});
  }
  Future<void> addStudent(int groupId, int studentId) async {
    await dio.post('teacher/groups/$groupId/students', data: {'student_id': studentId});
  }
  Future<Map<String, dynamic>> schedule(int groupId, DateTime start, DateTime end) async {
    final result = await dio.post('teacher/sessions', data: {
      'type': 'group', 'type_id': groupId,
      'starts_at': start.toUtc().toIso8601String(),
      'ends_at': end.toUtc().toIso8601String(),
    });
    return Map<String, dynamic>.from(result.data['data']);
  }
  Future<Map<String, dynamic>> scheduleList(int groupId, {int page = 1}) async =>
      Map<String, dynamic>.from((await dio.get('teacher/sessions',
        queryParameters: {'type': 'group', 'type_id': groupId, 'page': page})).data);
}
```
الطلبات لا تبتلع DioException: في الواجهة التقط e.response?.statusCode، وافحص e.response?.data['errors'] أو ['code'] و['message'] بحسب نوع الاستجابة. لا تستدعِ approve/reject مرة ثانية تلقائياً عند Timeout قبل إعادة جلب الحالة؛ الطلب قد يكون نجح رغم انقطاع الرد. المساران يدعمان تكرار القرار نفسه، لكن إعادة الجلب تمنع عرض حالة خاطئة.

### تجديد التوكن
استخدم `POST https://mandobe.net/liqaa/api/v1/teacher/refresh` بدون Bearer وبجسم `{"refresh_token":"<stored_refresh_token>"}`. احفظ التوكنين الجديدين معاً، وحدث Authorization، ثم أعد الطلب الأصلي مرة واحدة. نفذ تجديداً واحداً مشتركاً عند تزامن عدة أخطاء 401. لا تجعل طلب refresh نفسه يدخل في حلقة تجديد. إذا فشل التجديد اطلب تسجيل الدخول. لا تنقل توكن الطالب إلى مسارات الأستاذ.

### جدول العناوين الكاملة للميزات المطلوبة
| الطريقة | العنوان الكامل | Bearer |
|---|---|---|
| POST | https://mandobe.net/liqaa/api/v1/teacher/login | لا |
| GET | https://mandobe.net/liqaa/api/v1/teacher/groups | أستاذ |
| GET | https://mandobe.net/liqaa/api/v1/teacher/sessions?scope=today&timezone=Asia/Damascus | أستاذ |
| GET | https://mandobe.net/liqaa/api/v1/teacher/groups/{group}/students?status=active | أستاذ |
| POST | https://mandobe.net/liqaa/api/v1/teacher/groups/{group}/students | أستاذ |
| POST | https://mandobe.net/liqaa/api/v1/teacher/sessions | أستاذ |
| PATCH | https://mandobe.net/liqaa/api/v1/teacher/sessions/{session} | أستاذ |
| GET | https://mandobe.net/liqaa/api/v1/teacher/sessions?type=group&type_id={group} | أستاذ |
| POST | https://mandobe.net/liqaa/api/v1/student/groups/{group}/join-request | طالب مع جهازه |
| GET | https://mandobe.net/liqaa/api/v1/student/groups/{group}/join-requests | طالب مع جهازه |
| GET | https://mandobe.net/liqaa/api/v1/teacher/groups/{group}/join-requests?status=pending | أستاذ |
| POST | https://mandobe.net/liqaa/api/v1/teacher/groups/{group}/join-requests/{joinRequest}/approve | أستاذ |
| POST | https://mandobe.net/liqaa/api/v1/teacher/groups/{group}/join-requests/{joinRequest}/reject | أستاذ |

## التوافق وحدود التنفيذ
- بقيت مسارات API السابقة موجودة؛ رابطا `/teacher/groups/{id}/enrollments` و`.../enrollments/{enrollment}` مدعومان للتطبيق القديم، مع تفسير enrollment_id على أنه اشتراك مادة مطابق فعال. الربط الجديد يستخدم students وstudent_id ولا يحتاج اشتراك مادة إلكترونية للتدريس الحضوري.
- طلب الجلسة القديم بصيغة type=GROUP مع group_id، أو type=INDIVIDUAL مع enrollment_id، يبقى مدعوماً. لا تجمع الهدفين. الربط الجديد يستخدم type/type_id مع الترويسة 2.
- تغير واحد مقصود في تجربة الطالب: `/student/groups/{group}/join` يعيد طلباً pending، لا عضوية مباشرة؛ يجب تحديث واجهة الطالب لهذا المعنى.
- توافر الأستاذ ليس موعد المجموعة. لا يوجد API جديد لتكرار أسبوعي تلقائي أو حفظ عدة مواعيد بمعاملة واحدة. لا يوجد Push/SMS جديد ضمن هذه المهمة. لا تفترض وجودها في الواجهة.
- أمثلة سجل طلب الطالب وبعض الأخطاء العامة توضيحية مبنية على العقد والاختبارات المحلية؛ ملحق السيرفر أدناه هو الاستجابات الملتقطة حرفياً بعد إخفاء التوكنات.

## ما تم التحقق منه
- محلياً: 225 اختباراً و3866 تحققاً ناجحاً، بما فيها التوافق والدخول والملكية وطلبات الانضمام وتعارض الأوقات.
- نسخة معزولة من قاعدة الإنتاج: تنفيذ الترحيلات الستة، حفظ 14 عضوية و15 جلسة، وعدم وجود أهداف جلسات مجهولة. تصحيح الجلسة 21 إلى جماعية للمجموعة 1 بتأكيد صاحب المشروع، مع بقاء حالتها completed.
- على السيرفر الإنتاجي: 38 تحققاً عبر HTTP Kernel الفعلي داخل معاملة تراجعت كاملة. شملت الدخول بالهاتف والبريد، جلب البيانات، إرسال الطلب وتكراره، قبول/رفض، إضافة مباشرة، مواعيد المجموعة وتعديلها، توافق المسارين القديمين، رفض أستاذ آخر ورفض الطلب دون توكن. هذا ليس اختبار ضغط ولا مروراً عبر شبكة الهاتف.
- عبر HTTPS العام بعد تنظيف ملفات النشر: 7 فحوص ناجحة؛ الصحة وصفحة دخول الإدارة والمحافظات 200، جلسات الأستاذ وطلبات الانضمام ومسار العضوية القديم دون توكن 401، وطلب دخول ناقص 422. لا ننشر توكنات حسابات حقيقية للتجربة.
- 201 مسار سابق محفوظ ضمن 275 مساراً حالياً؛ 57 ترحيلاً مسجلاً؛ نُشر 145 ملف مصدر متغير دون استبدال .env أو public/index.php أو vendor أو رفع قاعدة محلية فوق الإنتاج.
- آخر اختبار تزامن لآخر مقعد تم على MariaDB محلية بعمليتين متوازيتين؛ لم يُنفذ اختبار ضغط متوازٍ على قاعدة الإنتاج.

## ملحق الاستجابات الملتقطة من السيرفر

### POST /api/v1/teacher/groups/22/join-requests/1/reject — HTTP 409
```json
{
  "message": "Request already decided."
}
```

### POST /api/v1/teacher/groups/22/join-requests/2/reject — HTTP 422
```json
{
  "message": "The review note field is required.",
  "errors": {
    "review_note": [
      "The review note field is required."
    ]
  }
}
```

### GET /api/v1/teacher/sessions?date=2026-11-01&timezone=Asia%2FDamascus&type=group&type_id=22 — HTTP 200
```json
{
  "status": true,
  "data": [
    {
      "id": 45,
      "teacher_id": 14,
      "availability_id": null,
      "type": "group",
      "starts_at": "2026-11-01T03:37:00.000000Z",
      "ends_at": "2026-11-01T04:37:00.000000Z",
      "capacity": 3,
      "status": "scheduled",
      "type_id": 22,
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

### GET /api/v1/teacher/groups/22/students — HTTP 404
```json
{
  "message": "Not Found"
}
```

### GET /api/v1/teacher/groups/22/join-requests — HTTP 404
```json
{
  "message": "Not Found"
}
```

### GET /api/v1/teacher/sessions?type=group&type_id=22 — HTTP 404
```json
{
  "message": "Not Found"
}
```

### GET /api/v1/teacher/sessions — HTTP 401
```json
{
  "message": "Unauthenticated."
}
```
