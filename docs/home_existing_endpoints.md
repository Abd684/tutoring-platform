# APIs الموجودة لدعم الصفحة الرئيسية

> هذا الملف يوثق الحالة قبل إكمال الجزء الأول. توثيق الجلسات والمواعيد المتاحة والحصص المباشرة والدفع الحالي موجود في [student_part_one_api.md](student_part_one_api.md).

توثيق الكود المحلي بتاريخ 2026-10-10. القيم والمعرّفات في الأمثلة توضيحية وليست بيانات مأخوذة من السيرفر. استبدل `{BASE_URL}` بعنوان السيرفر، مثلاً `https://example.com`.

كل الطلبات تستخدم:

```http
Accept: application/json
```

طلبات JSON تستخدم أيضاً `Content-Type: application/json`. المسارات المحمية تحتاج `Authorization: Bearer {STUDENT_ACCESS_TOKEN}` لحساب طالب. لا يوجد body لطلبات GET وDELETE أدناه.

## 1. الدورات الأكثر طلباً

**Endpoint:** `GET {BASE_URL}/api/v1/teacher-subjects/most-requested`

**Auth:** لا تتطلب توكن حسب تعريف المسار الحالي.

**Request:**

```http
GET /api/v1/teacher-subjects/most-requested?limit=10
Accept: application/json
```

| Query | مطلوب | القيود |
|---|---|---|
| `limit` | لا | عدد صحيح بين 1 و100؛ الافتراضي 10 |

**Response — 200:**

```json
{
  "status": true,
  "data": [
    {
      "id": 12,
      "name": "الرياضيات",
      "teacher": { "id": 3, "name": "أحمد" },
      "students_count": 45
    }
  ],
  "meta": {
    "from": "2023-10-10T09:00:00+00:00",
    "to": "2026-10-10T09:00:00+00:00",
    "limit": 10
  }
}
```

`id` هو معرّف `teacher_subjects`. الترتيب تنازلي حسب عدد الطلاب المختلفين باشتراكات `active` أو `expired` التي بدأت خلال آخر 3 سنوات، ثم تصاعدي حسب ID عند التعادل. تظهر فقط الدورات والمواد والمدرسون وحساباتهم الفعالة، والتي لها اشتراكات مؤهلة. لا ترجع صوراً أو تقييمات. `from` و`to` محسوبان وقت الطلب بتوقيت التطبيق. عند عدم وجود نتائج: `data: []`.

## 2. نسبة حضور الطالب

**Endpoint:** `GET {BASE_URL}/api/v1/student/attendance/percentage`

**Auth:** توكن طالب مطلوب.

**Request:**

```http
GET /api/v1/student/attendance/percentage
Accept: application/json
Authorization: Bearer {STUDENT_ACCESS_TOKEN}
```

لا توجد query parameters خاصة بهذا المسار.

**Response — 200:**

```json
{
  "data": {
    "attendance_percentage": 75,
    "attended_sessions": 6,
    "absent_sessions": 2,
    "total_sessions": 8
  }
}
```

هذه الاستجابة لا تحتوي `status`. الحساب يعتمد على سجلات حضور اشتراكات الطالب للجلسات التي انتهت (`ends_at <= now`). تعتبر `present` و`late` حضوراً. المقام يشمل جميع حالات سجلات الحضور المطابقة، بما فيها `excused`؛ ولا يشمل جلسات بدون سجل حضور. النسبة مقربة إلى منزلتين وتكون 0 عندما لا توجد سجلات. هذه نسبة حضور وليست نسبة تقدم بالمحتوى.

**Response — 404، عند غياب ملف الطالب:**

```json
{ "message": "Student account not found." }
```

## 3. حجز جلسة فردية

**Endpoint:** `POST {BASE_URL}/api/v1/sessions/{id}/book`

**Auth:** توكن طالب مطلوب. `{id}` معرّف جلسة موجودة.

**Request:**

```http
POST /api/v1/sessions/20/book
Accept: application/json
Content-Type: application/json
Authorization: Bearer {STUDENT_ACCESS_TOKEN}

{
  "enrollment_id": 7
}
```

| Body | مطلوب | القيود |
|---|---|---|
| `enrollment_id` | لا | nullable، عدد صحيح ومعرّف موجود في `enrollments` |

يمكن إرسال `{}` إذا كان للطالب اشتراك واحد مؤهل عند مدرس الجلسة. عند تعدد الاشتراكات المؤهلة يجب تحديد `enrollment_id`.

يجب أن تكون الجلسة `INDIVIDUAL` و`scheduled` ولم تبدأ وغير محجوزة. يجب أن يكون الاشتراك للطالب نفسه وعند مدرس الجلسة، بحالة `active`، وقد بدأ ولم ينتهِ. فحص التعارض الحالي يبحث عن جلسة متداخلة لنفس `enrollment_id` بحالة `scheduled` أو `ongoing`.

**Response — 200:**

```json
{
  "status": true,
  "message": "Session booked successfully.",
  "data": {
    "id": 20,
    "teacher_id": 3,
    "availability_id": null,
    "type": "INDIVIDUAL",
    "group_id": null,
    "enrollment_id": 7,
    "starts_at": "2026-10-20T10:00:00.000000Z",
    "ends_at": "2026-10-20T11:00:00.000000Z",
    "capacity": 1,
    "status": "scheduled",
    "teacher": {
      "id": 3,
      "user_id": 9,
      "bio": null,
      "specialization": "رياضيات",
      "status": "active",
      "user": { "id": 9, "name": "أحمد", "email": "teacher@example.com" }
    },
    "enrollment": {
      "id": 7,
      "student_id": 2,
      "teacher_id": 3,
      "enrollable_type": "App\\Models\\TeacherSubject",
      "enrollable_id": 12,
      "price": "100.00",
      "status": "active",
      "starts_at": "2026-10-01T00:00:00.000000Z",
      "expires_at": null
    }
  }
}
```

الحجز يربط الجلسة بالاشتراك ويضبط `capacity` إلى 1. لا يغير حالة الجلسة.

**مثال خطأ:**

```json
{
  "status": false,
  "message": "This session is already booked.",
  "code": "SESSION_ALREADY_BOOKED"
}
```

| HTTP | `code` | السبب |
|---|---|---|
| 403 | `STUDENT_PROFILE_NOT_FOUND` | لا يوجد ملف طالب |
| 422 | `SESSION_TYPE_NOT_BOOKABLE` | الجلسة ليست فردية |
| 409 | `SESSION_NOT_AVAILABLE` | الحالة ليست scheduled |
| 409 | `SESSION_ALREADY_STARTED` | الجلسة بدأت أو ماضية |
| 409 | `ALREADY_BOOKED_BY_STUDENT` | محجوزة للطالب نفسه |
| 409 | `SESSION_ALREADY_BOOKED` | محجوزة مسبقاً |
| 422 | `INVALID_ENROLLMENT` | الاشتراك المحدد غير مؤهل |
| 422 | `NO_ACTIVE_ENROLLMENT` | لا يوجد اشتراك مؤهل |
| 422 | `ENROLLMENT_SELECTION_REQUIRED` | يجب اختيار اشتراك من عدة اشتراكات |
| 409 | `SESSION_TIME_CONFLICT` | يوجد تداخل لنفس الاشتراك |

## 4. إلغاء حجز الطالب

**Endpoint:** `DELETE {BASE_URL}/api/v1/sessions/{id}/booking`

**Auth:** توكن طالب مطلوب.

**Request:**

```http
DELETE /api/v1/sessions/20/booking
Accept: application/json
Authorization: Bearer {STUDENT_ACCESS_TOKEN}
```

لا يوجد body. يجب أن تكون الجلسة الفردية محجوزة للطالب الحالي، بحالة `scheduled`، ولم تبدأ.

**Response — 200:**

```json
{
  "status": true,
  "message": "Session booking cancelled successfully.",
  "data": {
    "id": 20,
    "teacher_id": 3,
    "availability_id": null,
    "type": "INDIVIDUAL",
    "group_id": null,
    "enrollment_id": null,
    "starts_at": "2026-10-20T10:00:00.000000Z",
    "ends_at": "2026-10-20T11:00:00.000000Z",
    "capacity": 1,
    "status": "scheduled"
  }
}
```

الإلغاء يفرغ `enrollment_id` فقط ويعيد إتاحة الجلسة. لا يحذفها ولا يحول حالتها إلى `cancelled`. لا تتضمن هذه الاستجابة علاقتي `teacher` و`enrollment`.

أخطاء الإلغاء تستخدم نفس شكل `{ "status": false, "message": "...", "code": "..." }`:

| HTTP | `code` | السبب |
|---|---|---|
| 403 | `STUDENT_PROFILE_NOT_FOUND` | لا يوجد ملف طالب |
| 404 | `BOOKING_NOT_FOUND` | ليست جلسة فردية محجوزة |
| 403 | `BOOKING_FORBIDDEN` | الحجز ليس للطالب الحالي |
| 409 | `BOOKING_CANNOT_BE_CANCELLED` | الحالة ليست scheduled |
| 409 | `BOOKING_ALREADY_STARTED` | الجلسة بدأت أو ماضية |

## 5. الإشعارات — CRUD عام موجود جزئياً

المسارات الحالية لا تحتوي middleware للمصادقة في تعريفها، ولا تحقق ملكية الإشعار داخل الـ controller. القائمة ترجع جميع الإشعارات ولا تخصص النتائج للطالب؛ إضافة `user_id` كـ query لا تقوم بفلترتها حالياً. لذلك هذا توثيق الموجود وليس تدفق إشعارات طالب مكتمل.

### عرض القائمة

**Endpoint / Request:**

```http
GET /api/v1/notifications?page=1&per_page=20
Accept: application/json
```

`page` رقم الصفحة، و`per_page` الافتراضي 20، ويتم حصره بين 1 و100. الترتيب حسب ID تنازلياً.

**Response — 200:**

```json
{
  "status": true,
  "data": [
    { "id": 5, "user_id": 4, "type": "lesson_reminder", "title": "تذكير", "body": "لديك درس قريب", "read_at": null }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
}
```

### إنشاء إشعار

**Endpoint / Request:**

```http
POST /api/v1/notifications
Accept: application/json
Content-Type: application/json

{
  "user_id": 4,
  "type": "lesson_reminder",
  "title": "تذكير",
  "body": "لديك درس قريب",
  "read_at": null
}
```

| Body | مطلوب | القيود |
|---|---|---|
| `user_id` | نعم | عدد صحيح موجود في users |
| `type` | نعم | نص، حتى 100 حرف |
| `title` | نعم | نص، حتى 255 حرف |
| `body` | نعم | نص |
| `read_at` | لا | تاريخ أو null |

**Response — 201:**

```json
{
  "status": true,
  "message": "Notification created successfully.",
  "data": { "user_id": 4, "type": "lesson_reminder", "title": "تذكير", "body": "لديك درس قريب", "read_at": null, "id": 5 }
}
```

### عرض إشعار واحد

**Endpoint / Request:** `GET /api/v1/notifications/5`، بلا body.

**Response — 200:**

```json
{
  "status": true,
  "data": { "id": 5, "user_id": 4, "type": "lesson_reminder", "title": "تذكير", "body": "لديك درس قريب", "read_at": null }
}
```

### تعديل إشعار / تعيينه كمقروء

**Endpoint:** `PATCH /api/v1/notifications/{notification}` أو `PUT` لنفس المسار.

**Request:**

```http
PATCH /api/v1/notifications/5
Accept: application/json
Content-Type: application/json

{ "read_at": "2026-10-10T09:00:00Z" }
```

حقول الإنشاء نفسها قابلة للتعديل، وكلها اختيارية؛ `read_at` يقبل null لإعادته غير مقروء. بقية الحقول إذا أرسلت يجب أن تحقق قيود الإنشاء ولا تقبل null. لا يوجد endpoint مخصص باسم mark-as-read.

**Response — 200:**

```json
{
  "status": true,
  "message": "Notification updated successfully.",
  "data": { "id": 5, "user_id": 4, "type": "lesson_reminder", "title": "تذكير", "body": "لديك درس قريب", "read_at": "2026-10-10T09:00:00.000000Z" }
}
```

### حذف إشعار

**Endpoint / Request:** `DELETE /api/v1/notifications/5`، بلا body.

**Response — 200:**

```json
{ "status": true, "message": "Notification deleted successfully." }
```

كل مسارات الإشعارات أعلاه تسبقها `{BASE_URL}`.

## 6. الاشتراكات الحالية — مسار موجود مرتبط بالقائمة

**Endpoint:** `GET {BASE_URL}/api/v1/student/booking`

هذا المسار بالمفرد `booking`، ويرجع الاشتراكات الحالية وليس موعد الدرس القادم ولا قائمة جلسات الطالب.

**Request:**

```http
GET /api/v1/student/booking?page=1&per_page=20
Accept: application/json
Authorization: Bearer {STUDENT_ACCESS_TOKEN}
```

`page` للترقيم، و`per_page` الافتراضي 20 ومحصور بين 1 و100. النتائج للطالب صاحب التوكن، بحالة `pending` أو `active`، و`expires_at` فارغ أو في المستقبل؛ الترتيب حسب ID تنازلياً. لا يشترط هذا المسار أن يكون `starts_at` قد حلّ.

**Response — 200:**

```json
{
  "status": true,
  "data": [
    {
      "id": 7,
      "type": "subject",
      "item": { "id": 12, "title": "الرياضيات", "subject_name": "الرياضيات" },
      "teacher": { "id": 3, "name": "أحمد" },
      "price": "100.00",
      "status": "active",
      "starts_at": "2026-10-01 00:00:00",
      "expires_at": null
    }
  ],
  "meta": { "current_page": 1, "per_page": 20, "total": 1, "last_page": 1 }
}
```

`type` إحدى `subject` أو `unit` أو `content`، وقد تكون `unknown` لنوع آخر. حقول عنوان المادة واسم المدرس قد تكون null إذا غابت العلاقة. التواريخ هنا بصيغة `Y-m-d H:i:s` بخلاف تواريخ نماذج الجلسات والإشعارات. عند غياب ملف الطالب ترجع 404 مع `{ "status": false, "message": "Student profile not found." }`.

## أخطاء عامة

- المسارات المحمية: 401 عند غياب/عدم صلاحية التوكن، و403 عند مخالفة الدور المطلوب حسب middleware.
- الجلسة أو الإشعار غير الموجود: 404 من Laravel؛ ليس بالضرورة أن يتضمن `code` الخاص بأخطاء الأعمال.
- فشل التحقق من المدخلات: 422. مثال توضيحي؛ نص رسالة التحقق قد يختلف حسب لغة التطبيق:

```json
{
  "message": "The limit field must be at least 1.",
  "errors": { "limit": ["The limit field must be at least 1."] }
}
```

## حدود التوثيق ومصادره

لا يتضمن المشروع حالياً مسار `GET /api/v1/student/bookings` بالجمع أو مسار `available-sessions` الظاهر بالصورة. البنود غير المنفذة (رفع صورة الطالب، تقدم المحتوى، الخطة الأسبوعية، المدرسون الأبرز، التوفر الآن، موعد الدرس القادم) لا توجد لها requests/responses مكتملة لتوثيقها.

مصادر المطابقة: `routes/api.php`، `routes/student.php`، `bootstrap/app.php`، controllers: `TeacherSubjectController` و`AttendanceController` و`SessionController` و`NotificationController` و`StudentEnrollmentController` و`ApiController`، والنماذج المرتبطة. لم يتم استدعاء السيرفر المنشور. تم التحقق سابقاً من الأكثر طلباً باختبارات `MostRequestedCoursesTest`: أربعة اختبارات ناجحة و29 assertion.
