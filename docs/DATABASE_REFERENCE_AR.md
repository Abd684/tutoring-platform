# مرجع قاعدة بيانات لقاء الحالية — 2026-10-31

مرجع البنية الكاملة من الصفر: **60 جدولاً، 51 موديل**. استُخرج من جميع Migrations على قاعدة SQLite مؤقتة بالذاكرة، وليس من القاعدة الفعلية.

## قراءة المرجع

- هذا مرجع DB وليس عقد API. راجع FRONTEND_CONTRACT_FINAL_AR.md للطلبات والاستجابات؛ بعض الأعمدة داخلية ولا تصل للفرونت.
- nullable لا تعني optional في الطلب. لا تُرسل id أو timestamps أو teacher_id أو status تلقائياً لمجرد وجودها بالجدول.
- أنواع SQL كما أرجعتها SQLite؛ أطوال varchar ودقة decimal وبعض enum constraints تختلف عن MySQL. إنشاء القاعدة يتم بالـMigrations الأصلية.
- الهاتف String. التواريخ في JSON نصوص؛ أرسل ISO 8601 مع timezone. decimal:2 يُعاد عادة كنص مثل "50.00".
- العلاقات تظهر عند تحميلها فقط، بأسماء snake_case مثل teacher_subject وpaid_booking. بعض endpoints تستخدم DTO مختصراً مختلفاً.
- password_hash وtoken_hash وrefresh_token_hash ومسارات التخزين والطوابير حقول خادم، لا حقول واجهة. استعمل avatar_url بدلاً من avatar_path.
- student_id يشير إلى students.id وteacher_id إلى teachers.id؛ لا تخلطهما مع users.id.
- قيم الحالات أدناه من Migrations أو قواعد التطبيق؛ ليست جميعها قيود enum في DB، وقد تقبل نقطة نهاية قيماً أقل.

## علاقات Morph

| المكان | حقول الربط | الأهداف |
|---|---|---|
| sessions | type + type_id | group → groups.id أو individual → individuals.id |
| enrollments | enrollable_type + enrollable_id | teacher_subject أو unit أو lesson أو content أو paid_booking |
| payments | payable_type + payable_id | السجل المدفوع حسب المسار المالي |
| wallet_transactions | reference_type + reference_id | مرجع الحركة المالية |
| personal_access_tokens | tokenable_type + tokenable_id | صاحب التوكن، داخلي |

لا تجمع قيم Morph في enum واحدة؛ كل علاقة لها أنواعها. JSON يحتوي morph_map، وقد تستعمل علاقات أخرى اسم موديل PHP كاملاً عند غياب alias. لا يوجد ارتباط مباشر لـsessions أو attendance أو group_students مع enrollments.


## الحسابات والموقع

### `users`

الهاتف فريد والبريد اختياري.

موديل Laravel: `User`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `phone` | `varchar` | لا | — | String |
| `email` | `varchar` | نعم | — | String أو null |
| `password_hash` | `varchar` | لا | — | String |
| `role` | `varchar` | لا | — | String |
| `status` | `varchar` | لا | `'active'` | String |
| `region_id` | `integer` | نعم | — | int أو null |
| `avatar_path` | `varchar` | نعم | — | String أو null |

قيم `role`: `student`, `teacher`, `company_admin`.

قيم `status`: `active`, `suspended`, `banned`.

- FK `region_id` → `regions.id`؛ عند الحذف: `set null`.
- قيد فريد: `(id)`.
- قيد فريد: `(email)`.
- قيد فريد: `(phone)`.

حقول مخفية بالموديل: `avatar_path`, `password_hash`.

حقول محسوبة: `avatar_url`.

علاقات ORM، عند تحميلها فقط:

- `student`: `HasOne` → `students`.
- `region`: `BelongsTo` → `regions`.
- `teacher`: `HasOne` → `teachers`.
- `payments`: `HasMany` → `payments`.
- `wallets`: `HasMany` → `wallets`.
- `notifications`: `HasMany` → `notifications`.
- `audit_logs`: `HasMany` → `audit_logs`.

### `students`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Student`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | لا | — | int |
| `grade` | `varchar` | نعم | — | String أو null |
| `status` | `varchar` | لا | `'active'` | String |
| `school_id` | `integer` | نعم | — | int أو null |

قيم `status`: `active`, `inactive`.

- FK `school_id` → `schools.id`؛ عند الحذف: `set null`.
- FK `user_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(user_id)`.

علاقات ORM، عند تحميلها فقط:

- `user`: `BelongsTo` → `users`.
- `school`: `BelongsTo` → `schools`.
- `enrollments`: `HasMany` → `enrollments`.
- `student_devices`: `HasMany` → `student_devices`.
- `quiz_attempts`: `HasMany` → `quiz_attempts`.
- `individuals`: `HasMany` → `individuals`.
- `attendance`: `HasMany` → `attendance`.
- `group_students`: `HasMany` → `group_students`.
- `groups`: `BelongsToMany` → `groups`.

### `teachers`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Teacher`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | لا | — | int |
| `bio` | `text` | نعم | — | String أو null |
| `specialization` | `varchar` | نعم | — | String أو null |
| `status` | `varchar` | لا | `'pending_review'` | String |
| `experience_years` | `integer` | نعم | — | int أو null |

قيم `status`: `pending_review`, `active`, `suspended`.

- FK `user_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(user_id)`.

علاقات ORM، عند تحميلها فقط:

- `user`: `BelongsTo` → `users`.
- `teacher_subjects`: `HasMany` → `teacher_subjects`.
- `enrollments`: `HasMany` → `enrollments`.
- `availabilities`: `HasMany` → `teacher_availabilities`.
- `sessions`: `HasMany` → `sessions`.
- `subscriptions`: `HasMany` → `teacher_subscriptions`.
- `escrow_transactions`: `HasMany` → `escrow_transactions`.

### `governortates`

تهجئة الجدول الحالية كما في المشروع؛ لا تستبدلها تلقائياً بـgovernorates.

موديل Laravel: `Governortate`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |

- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `regions`: `HasMany` → `regions`.

### `regions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Region`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `governortate_id` | `integer` | لا | — | int |

- FK `governortate_id` → `governortates.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `governortate`: `BelongsTo` → `governortates`.
- `schools`: `HasMany` → `schools`.
- `users`: `HasMany` → `users`.

### `schools`

student_id حقل تاريخي اختياري موجود؛ يوجد أيضاً students.school_id.

موديل Laravel: `School`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `region_id` | `integer` | لا | — | int |
| `student_id` | `integer` | نعم | — | int أو null |

- FK `student_id` → `students.id`؛ عند الحذف: `set null`.
- FK `region_id` → `regions.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `region`: `BelongsTo` → `regions`.
- `student`: `BelongsTo` → `students`.
- `students`: `HasMany` → `students`.


## المواد والمحتوى والملفات

### `subjects`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Subject`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `description` | `text` | نعم | — | String أو null |
| `status` | `varchar` | لا | `'active'` | String |

- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher_subjects`: `HasMany` → `teacher_subjects`.

### `teacher_subjects`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `TeacherSubject`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `subject_id` | `integer` | لا | — | int |
| `price` | `numeric` | لا | `'0'` | String عشري |
| `group_enabled` | `tinyint(1)` | لا | `'0'` | bool |
| `max_group_size` | `integer` | نعم | — | int أو null |
| `status` | `varchar` | لا | `'active'` | String |

- FK `subject_id` → `subjects.id`؛ عند الحذف: `cascade`.
- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(teacher_id, subject_id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher`: `BelongsTo` → `teachers`.
- `subject`: `BelongsTo` → `subjects`.
- `units`: `HasMany` → `units`.
- `groups`: `HasMany` → `groups`.
- `enrollments`: `MorphMany` → `enrollments`.
- `individuals`: `HasMany` → `individuals`.

### `units`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Unit`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_subject_id` | `integer` | لا | — | int |
| `title` | `varchar` | لا | — | String |
| `description` | `text` | نعم | — | String أو null |
| `order_no` | `integer` | لا | `'0'` | int |
| `status` | `varchar` | لا | `'draft'` | String |
| `price` | `numeric` | نعم | — | String عشري أو null |

قيم `status`: `draft`, `published`, `archived`.

- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher_subject`: `BelongsTo` → `teacher_subjects`.
- `lessons`: `HasMany` → `lessons`.
- `enrollments`: `MorphMany` → `enrollments`.

### `lessons`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Lesson`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `unit_id` | `integer` | لا | — | int |
| `title` | `varchar` | لا | — | String |
| `description` | `text` | نعم | — | String أو null |
| `order_no` | `integer` | لا | `'0'` | int |
| `status` | `varchar` | لا | `'draft'` | String |
| `price` | `numeric` | نعم | — | String عشري أو null |

قيم `status`: `draft`, `published`, `archived`.

- FK `unit_id` → `units.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `unit`: `BelongsTo` → `units`.
- `contents`: `HasMany` → `contents`.
- `enrollments`: `MorphMany` → `enrollments`.

### `contents`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Content`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `lesson_id` | `integer` | لا | — | int |
| `type` | `varchar` | لا | — | String |
| `title` | `varchar` | لا | — | String |
| `description` | `text` | نعم | — | String أو null |
| `order_no` | `integer` | لا | `'0'` | int |
| `status` | `varchar` | لا | `'draft'` | String |
| `published_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `price` | `numeric` | نعم | — | String عشري أو null |
| `learning_objectives` | `text` | نعم | — | JSON أو null |
| `scheduled_publish_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `type`: `video`, `pdf`, `quiz`, `link`.

قيم `status`: `draft`, `published`, `archived`.

- FK `lesson_id` → `lessons.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `lesson`: `BelongsTo` → `lessons`.
- `assets`: `HasMany` → `content_assets`.
- `quizzes`: `HasMany` → `quizzes`.
- `enrollments`: `MorphMany` → `enrollments`.

### `content_assets`

موجود مسبقاً ولم يتغير بهذا التعديل.

موديل Laravel: `ContentAsset`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `content_id` | `integer` | لا | — | int |
| `disk` | `varchar` | لا | — | String |
| `storage_key` | `varchar` | لا | — | String |
| `mime_type` | `varchar` | نعم | — | String أو null |
| `file_size` | `integer` | نعم | — | int أو null |
| `checksum` | `varchar` | نعم | — | String أو null |
| `encryption_type` | `varchar` | نعم | — | String أو null |
| `encryption_status` | `varchar` | لا | `'pending'` | String |
| `status` | `varchar` | لا | `'active'` | String |

قيم `encryption_status`: `pending`, `processing`, `done`, `failed`.

قيم `status`: `active`, `archived`.

- FK `content_id` → `contents.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `content`: `BelongsTo` → `contents`.
- `video_asset`: `HasOne` → `video_assets`.
- `pdf_asset`: `HasOne` → `pdf_assets`.

### `video_assets`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `VideoAsset`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `content_asset_id` | `integer` | لا | — | int |
| `hls_manifest_key` | `varchar` | نعم | — | String أو null |
| `duration_seconds` | `integer` | نعم | — | int أو null |
| `key_reference` | `varchar` | نعم | — | String أو null |
| `watermark_enabled` | `tinyint(1)` | لا | `'1'` | bool |

- FK `content_asset_id` → `content_assets.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(content_asset_id)`.

علاقات ORM، عند تحميلها فقط:

- `content_asset`: `BelongsTo` → `content_assets`.

### `pdf_assets`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PdfAsset`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `content_asset_id` | `integer` | لا | — | int |
| `page_count` | `integer` | نعم | — | int أو null |
| `watermark_enabled` | `tinyint(1)` | لا | `'1'` | bool |
| `status` | `varchar` | لا | `'active'` | String |

- FK `content_asset_id` → `content_assets.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(content_asset_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `content_asset`: `BelongsTo` → `content_assets`.

### `content_progress`

تقدم المحتوى الإلكتروني، مستقل عن ملاحظة التدريس في sessions.

موديل Laravel: `ContentProgress`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `content_id` | `integer` | لا | — | int |
| `completed_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- FK `content_id` → `contents.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(student_id, content_id)`.
- قيد فريد: `(id)`.


### `content_completions`

إكمال المحتوى، وليس حضور جلسة.

موديل Laravel: `ContentCompletion`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `content_id` | `integer` | لا | — | int |
| `completed_at` | `datetime` | لا | — | نص تاريخ/وقت |

- FK `content_id` → `contents.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(student_id, content_id)`.
- قيد فريد: `(id)`.


### `lesson_completions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `LessonCompletion`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `lesson_id` | `integer` | لا | — | int |
| `completed_at` | `datetime` | لا | — | نص تاريخ/وقت |

- FK `lesson_id` → `lessons.id`؛ عند الحذف: `restrict`.
- FK `student_id` → `students.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(student_id, lesson_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student`: `BelongsTo` → `students`.
- `lesson`: `BelongsTo` → `lessons`.


## التدريس والجلسات والحضور

### `groups`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Group`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_subject_id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `capacity` | `integer` | لا | — | int |
| `status` | `varchar` | لا | `'forming'` | String |
| `teaching_scope_type` | `varchar` | نعم | — | String أو null |
| `teaching_scope_id` | `integer` | نعم | — | int أو null |
| `session_price` | `numeric` | لا | `'0'` | String عشري |
| `currency` | `varchar` | لا | `'SYP'` | String |
| `starts_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `ends_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `status`: `forming`, `active`, `closed`.

- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher_subject`: `BelongsTo` → `teacher_subjects`.
- `group_students`: `HasMany` → `group_students`.
- `sessions`: `MorphMany` → `sessions`.
- `students`: `BelongsToMany` → `students`.

### `group_students`

عضوية مباشرة؛ إعادة الانضمام تعيد تفعيل السجل نفسه.

موديل Laravel: `GroupStudent`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `group_id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `status` | `varchar` | لا | `'active'` | String |
| `joined_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `left_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- FK `student_id` → `students.id`؛ عند الحذف: `restrict`.
- FK `group_id` → `groups.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(group_id, student_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `group`: `BelongsTo` → `groups`.
- `student`: `BelongsTo` → `students`.

### `individuals`

طالب ومادة مدرس، يتبعهما عدة جلسات؛ لا يحتاج Enrollment.

موديل Laravel: `Individual`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `teacher_subject_id` | `integer` | لا | — | int |
| `status` | `varchar` | لا | `'active'` | String |
| `starts_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `ends_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `notes` | `text` | نعم | — | String أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `teaching_scope_type` | `varchar` | نعم | — | String أو null |
| `teaching_scope_id` | `integer` | نعم | — | int أو null |
| `session_price` | `numeric` | لا | `'0'` | String عشري |
| `currency` | `varchar` | لا | `'SYP'` | String |
| `individual_offer_id` | `integer` | نعم | — | int أو null |

- FK `individual_offer_id` → `individual_offers.id`؛ عند الحذف: `restrict`.
- FK `student_id` → `students.id`؛ عند الحذف: `restrict`.
- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `paid_bookings`: `HasMany` → `paid_bookings`.
- `student`: `BelongsTo` → `students`.
- `teacher_subject`: `BelongsTo` → `teacher_subjects`.
- `sessions`: `MorphMany` → `sessions`.
- `individual_offer`: `BelongsTo` → `individual_offers`.

### `individual_offers`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `IndividualOffer`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_subject_id` | `integer` | لا | — | int |
| `teaching_scope_type` | `varchar` | لا | — | String |
| `teaching_scope_id` | `integer` | لا | — | int |
| `title` | `varchar` | لا | — | String |
| `session_price` | `numeric` | لا | — | String عشري |
| `currency` | `varchar` | لا | `'SYP'` | String |
| `status` | `varchar` | لا | `'active'` | String |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher_subject`: `BelongsTo` → `teacher_subjects`.
- `individuals`: `HasMany` → `individuals`.

### `teacher_availabilities`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `TeacherAvailability`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `weekday` | `integer` | نعم | — | int أو null |
| `date` | `date` | نعم | — | نص تاريخ/وقت أو null |
| `start_time` | `time` | لا | — | نص تاريخ/وقت |
| `end_time` | `time` | لا | — | نص تاريخ/وقت |
| `recurrence_type` | `varchar` | لا | `'none'` | String |
| `timezone` | `varchar` | لا | `'UTC'` | String |
| `status` | `varchar` | لا | `'active'` | String |
| `price` | `numeric` | نعم | — | String عشري أو null |

قيم `recurrence_type`: `none`, `weekly`, `custom`.

قيم `status`: `active`, `disabled`.

- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher`: `BelongsTo` → `teachers`.
- `sessions`: `HasMany` → `sessions`.

### `sessions`

الجلسة الفعلية وملاحظات تقدم التدريس؛ لا يوجد group_id أو enrollment_id.

موديل Laravel: `Session`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `availability_id` | `integer` | نعم | — | int أو null |
| `type` | `varchar` | لا | — | String |
| `starts_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `ends_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `capacity` | `integer` | لا | `'1'` | int |
| `status` | `varchar` | لا | `'scheduled'` | String |
| `type_id` | `integer` | لا | — | int |
| `progress_notes` | `text` | نعم | — | String أو null |
| `progress_updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `type`: `INDIVIDUAL`, `GROUP`.

قيم `status`: `scheduled`, `ongoing`, `completed`, `cancelled`.

- FK `availability_id` → `teacher_availabilities.id`؛ عند الحذف: `set null`.
- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `target`: `MorphTo` → هدف متغير حسب Morph.
- `teacher`: `BelongsTo` → `teachers`.
- `availability`: `BelongsTo` → `teacher_availabilities`.
- `attendance`: `HasMany` → `attendance`.
- `paid_booking`: `HasOne` → `paid_bookings`.

### `attendance`

حضور الطالب بالجلسة؛ student_id بدلاً من enrollment_id.

موديل Laravel: `Attendance`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `session_id` | `integer` | لا | — | int |
| `status` | `varchar` | لا | — | String |
| `check_in_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `check_out_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `note` | `text` | نعم | — | String أو null |
| `student_id` | `integer` | لا | — | int |

قيم `status`: `present`, `absent`, `late`, `excused`.

- FK `session_id` → `sessions.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(session_id, student_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `session`: `BelongsTo` → `sessions`.
- `student`: `BelongsTo` → `students`.


## الاشتراكات والدفع والاسترداد

### `enrollments`

اشتراك المحتوى والسجل المالي الحالي للحجز المدفوع؛ مستقل عن تنظيم الجلسات.

موديل Laravel: `Enrollment`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `enrollable_type` | `varchar` | لا | — | String |
| `enrollable_id` | `integer` | لا | — | int |
| `price` | `numeric` | لا | `'0'` | String عشري |
| `status` | `varchar` | لا | `'pending'` | String |
| `starts_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `expires_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `access_frozen_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `status`: `pending`, `active`, `expired`, `cancelled`.

- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student`: `BelongsTo` → `students`.
- `teacher`: `BelongsTo` → `teachers`.
- `enrollable`: `MorphTo` → هدف متغير حسب Morph.
- `escrow_transactions`: `HasMany` → `escrow_transactions`.

### `subscription_plans`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `SubscriptionPlan`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `name` | `varchar` | لا | — | String |
| `price` | `numeric` | لا | `'0'` | String عشري |
| `billing_cycle` | `varchar` | لا | — | String |
| `commission_rate` | `numeric` | لا | `'0'` | String عشري |
| `max_students` | `integer` | نعم | — | int أو null |
| `max_storage` | `integer` | نعم | — | int أو null |
| `max_ai_usage` | `integer` | نعم | — | int أو null |
| `max_group_size` | `integer` | نعم | — | int أو null |
| `status` | `varchar` | لا | `'active'` | String |

قيم `billing_cycle`: `monthly`, `yearly`.

- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher_subscriptions`: `HasMany` → `teacher_subscriptions`.

### `teacher_subscriptions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `TeacherSubscription`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `plan_id` | `integer` | لا | — | int |
| `starts_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `ends_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `auto_renew` | `tinyint(1)` | لا | `'0'` | bool |
| `status` | `varchar` | لا | `'active'` | String |

قيم `status`: `active`, `expired`, `cancelled`.

- FK `plan_id` → `subscription_plans.id`؛ عند الحذف: `cascade`.
- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `teacher`: `BelongsTo` → `teachers`.
- `plan`: `BelongsTo` → `subscription_plans`.

### `payment_settings`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PaymentSetting`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `account_id` | `varchar` | نعم | — | String أو null |
| `qr_path` | `varchar` | نعم | — | String أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- قيد فريد: `(id)`.

حقول مخفية بالموديل: `qr_path`.


### `payment_checkouts`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PaymentCheckout`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `varchar` | لا | — | String |
| `user_id` | `integer` | لا | — | int |
| `service_type` | `varchar` | لا | — | String |
| `service_id` | `integer` | لا | — | int |
| `amount` | `numeric` | لا | — | String عشري |
| `currency` | `varchar` | لا | — | String |
| `account_id` | `varchar` | لا | — | String |
| `qr_path` | `varchar` | لا | — | String |
| `expires_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `payment_id` | `integer` | نعم | — | int أو null |
| `created_at` | `datetime` | لا | `CURRENT_TIMESTAMP` | نص تاريخ/وقت |
| `booking_starts_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `booking_ends_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `teacher_subject_id` | `integer` | نعم | — | int أو null |
| `pricing_snapshot` | `text` | نعم | — | JSON أو null |

- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `restrict`.
- FK `user_id` → `users.id`؛ عند الحذف: `restrict`.
- FK `payment_id` → `payments.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(payment_id)`.
- قيد فريد: `(id)`.

حقول مخفية بالموديل: `qr_path`.


### `paid_bookings`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PaidBooking`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `availability_id` | `integer` | لا | — | int |
| `payment_id` | `integer` | نعم | — | int أو null |
| `session_id` | `integer` | نعم | — | int أو null |
| `status` | `varchar` | لا | `'HELD'` | String |
| `starts_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `ends_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `hold_expires_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `cancelled_by_role` | `varchar` | نعم | — | String أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `teacher_subject_id` | `integer` | نعم | — | int أو null |
| `individual_id` | `integer` | نعم | — | int أو null |

- FK `individual_id` → `individuals.id`؛ عند الحذف: `restrict`.
- FK `teacher_subject_id` → `teacher_subjects.id`؛ عند الحذف: `restrict`.
- FK `student_id` → `students.id`؛ عند الحذف: `restrict`.
- FK `teacher_id` → `teachers.id`؛ عند الحذف: `restrict`.
- FK `availability_id` → `teacher_availabilities.id`؛ عند الحذف: `restrict`.
- FK `payment_id` → `payments.id`؛ عند الحذف: `restrict`.
- FK `session_id` → `sessions.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(payment_id)`.
- قيد فريد: `(session_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student`: `BelongsTo` → `students`.
- `teacher`: `BelongsTo` → `teachers`.
- `availability`: `BelongsTo` → `teacher_availabilities`.
- `payment`: `BelongsTo` → `payments`.
- `session`: `BelongsTo` → `sessions`.
- `individual`: `BelongsTo` → `individuals`.
- `teacher_subject`: `BelongsTo` → `teacher_subjects`.
- `enrollment`: `MorphOne` → `enrollments`.

### `payments`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Payment`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `payer_id` | `integer` | لا | — | int |
| `payable_type` | `varchar` | لا | — | String |
| `payable_id` | `integer` | لا | — | int |
| `amount` | `numeric` | لا | — | String عشري |
| `currency` | `varchar` | لا | — | String |
| `provider` | `varchar` | لا | — | String |
| `provider_reference` | `varchar` | نعم | — | String أو null |
| `status` | `varchar` | لا | `'pending'` | String |
| `paid_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `internal_reference` | `varchar` | نعم | — | String أو null |
| `sham_transfer_reference` | `varchar` | نعم | — | String أو null |
| `proof_path` | `varchar` | نعم | — | String أو null |
| `proof_mime` | `varchar` | نعم | — | String أو null |
| `service_snapshot` | `text` | نعم | — | JSON أو null |
| `rejection_reason` | `text` | نعم | — | String أو null |
| `reviewed_by` | `integer` | نعم | — | int أو null |
| `reviewed_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `submitted_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `status`: `pending`, `succeeded`, `failed`, `refunded`.

- FK `reviewed_by` → `users.id`؛ عند الحذف: `set null`.
- FK `payer_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(internal_reference)`.
- قيد فريد: `(sham_transfer_reference)`.
- قيد فريد: `(id)`.

حقول مخفية بالموديل: `proof_path`, `sham_transfer_reference`.

علاقات ORM، عند تحميلها فقط:

- `payer`: `BelongsTo` → `users`.
- `payable`: `MorphTo` → هدف متغير حسب Morph.
- `escrow_transactions`: `HasMany` → `escrow_transactions`.

### `payment_lesson_allocations`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PaymentLessonAllocation`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `payment_id` | `integer` | لا | — | int |
| `lesson_id` | `integer` | لا | — | int |
| `lesson_title` | `varchar` | لا | — | String |
| `lesson_price_snapshot` | `numeric` | لا | — | String عشري |
| `allocated_amount` | `numeric` | لا | — | String عشري |
| `content_ids_snapshot` | `text` | لا | — | JSON |

- FK `lesson_id` → `lessons.id`؛ عند الحذف: `restrict`.
- FK `payment_id` → `payments.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(payment_id, lesson_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `payment`: `BelongsTo` → `payments`.
- `lesson`: `BelongsTo` → `lessons`.

### `refund_requests`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `RefundRequest`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `payment_id` | `integer` | لا | — | int |
| `active_payment_id` | `integer` | نعم | — | int أو null |
| `user_id` | `integer` | لا | — | int |
| `amount` | `numeric` | لا | — | String عشري |
| `currency` | `varchar` | لا | — | String |
| `account_id` | `varchar` | لا | — | String |
| `reason_code` | `varchar` | لا | — | String |
| `reason` | `text` | لا | — | String |
| `status` | `varchar` | لا | `'PENDING_REVIEW'` | String |
| `review_note` | `text` | نعم | — | String أو null |
| `reviewed_by` | `integer` | نعم | — | int أو null |
| `reviewed_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `transfer_reference` | `varchar` | نعم | — | String أو null |
| `completed_by` | `integer` | نعم | — | int أو null |
| `completed_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `rejection_code` | `varchar` | نعم | — | String أو null |
| `refund_proof_path` | `varchar` | نعم | — | String أو null |
| `refund_proof_mime` | `varchar` | نعم | — | String أو null |
| `refund_transferred_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `payout_details_required` | `tinyint(1)` | لا | `'0'` | bool |
| `original_transfer_date` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `original_transfer_number` | `varchar` | نعم | — | String أو null |
| `original_proof_path` | `varchar` | نعم | — | String أو null |
| `original_proof_mime` | `varchar` | نعم | — | String أو null |

- FK `completed_by` → `users.id`؛ عند الحذف: `set null`.
- FK `reviewed_by` → `users.id`؛ عند الحذف: `set null`.
- FK `user_id` → `users.id`؛ عند الحذف: `restrict`.
- FK `payment_id` → `payments.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(id)`.
- قيد فريد: `(active_payment_id)`.
- قيد فريد: `(transfer_reference)`.

حقول مخفية بالموديل: `active_payment_id`, `refund_proof_path`, `original_proof_path`.

علاقات ORM، عند تحميلها فقط:

- `payment`: `BelongsTo` → `payments`.
- `user`: `BelongsTo` → `users`.

### `refund_lesson_items`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `RefundLessonItem`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `refund_request_id` | `integer` | لا | — | int |
| `payment_lesson_allocation_id` | `integer` | لا | — | int |
| `amount` | `numeric` | لا | — | num أو String حسب الاستجابة |

- FK `payment_lesson_allocation_id` → `payment_lesson_allocations.id`؛ عند الحذف: `restrict`.
- FK `refund_request_id` → `refund_requests.id`؛ عند الحذف: `restrict`.
- قيد فريد: `(id)`.
- قيد فريد: `(refund_request_id, payment_lesson_allocation_id)`.

علاقات ORM، عند تحميلها فقط:

- `refund_request`: `BelongsTo` → `refund_requests`.
- `allocation`: `BelongsTo` → `payment_lesson_allocations`.

### `wallets`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Wallet`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | لا | — | int |
| `currency` | `varchar` | لا | — | String |
| `status` | `varchar` | لا | `'active'` | String |

قيم `status`: `active`, `frozen`.

- FK `user_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(user_id, currency)`.

علاقات ORM، عند تحميلها فقط:

- `user`: `BelongsTo` → `users`.
- `transactions`: `HasMany` → `wallet_transactions`.

### `wallet_transactions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `WalletTransaction`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `wallet_id` | `integer` | لا | — | int |
| `type` | `varchar` | لا | — | String |
| `amount` | `numeric` | لا | — | String عشري |
| `reference_type` | `varchar` | لا | — | String |
| `reference_id` | `integer` | لا | — | int |
| `status` | `varchar` | لا | `'pending'` | String |

قيم `type`: `credit`, `debit`.

قيم `status`: `pending`, `completed`, `reversed`.

- FK `wallet_id` → `wallets.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `wallet`: `BelongsTo` → `wallets`.
- `reference`: `MorphTo` → هدف متغير حسب Morph.

### `escrow_transactions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `EscrowTransaction`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `payment_id` | `integer` | لا | — | int |
| `enrollment_id` | `integer` | لا | — | int |
| `teacher_id` | `integer` | لا | — | int |
| `gross_amount` | `numeric` | لا | — | String عشري |
| `commission` | `numeric` | لا | — | String عشري |
| `teacher_amount` | `numeric` | لا | — | String عشري |
| `status` | `varchar` | لا | `'held'` | String |
| `release_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `released_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

قيم `status`: `held`, `released`, `disputed`, `refunded`.

- FK `teacher_id` → `teachers.id`؛ عند الحذف: `cascade`.
- FK `enrollment_id` → `enrollments.id`؛ عند الحذف: `cascade`.
- FK `payment_id` → `payments.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `payment`: `BelongsTo` → `payments`.
- `enrollment`: `BelongsTo` → `enrollments`.
- `teacher`: `BelongsTo` → `teachers`.


## الاختبارات والتقييم

### `quizzes`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Quiz`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `content_id` | `integer` | لا | — | int |
| `duration_minutes` | `integer` | نعم | — | int أو null |
| `attempts_allowed` | `integer` | لا | `'1'` | int |
| `status` | `varchar` | لا | `'draft'` | String |
| `quiz_type` | `varchar` | لا | `'protected'` | String |
| `progress_weight` | `numeric` | لا | `'0.3'` | num أو String حسب الاستجابة |

قيم `status`: `draft`, `published`, `archived`.

- FK `content_id` → `contents.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `content`: `BelongsTo` → `contents`.
- `questions`: `HasMany` → `questions`.
- `attempts`: `HasMany` → `quiz_attempts`.

### `questions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Question`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `quiz_id` | `integer` | لا | — | int |
| `type` | `varchar` | لا | — | String |
| `body` | `text` | لا | — | String |
| `points` | `numeric` | لا | `'0'` | String عشري |
| `rubric_json` | `text` | نعم | — | JSON أو null |
| `order_no` | `integer` | لا | `'0'` | int |

قيم `type`: `mcq`, `true_false`, `essay`.

- FK `quiz_id` → `quizzes.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `quiz`: `BelongsTo` → `quizzes`.
- `answers`: `HasMany` → `answers`.

### `quiz_attempts`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `QuizAttempt`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `quiz_id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `started_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `submitted_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `score` | `numeric` | نعم | — | String عشري أو null |
| `status` | `varchar` | لا | `'in_progress'` | String |

قيم `status`: `in_progress`, `submitted`, `graded`.

- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- FK `quiz_id` → `quizzes.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `quiz`: `BelongsTo` → `quizzes`.
- `student`: `BelongsTo` → `students`.
- `answers`: `HasMany` → `answers`.

### `answers`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Answer`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `attempt_id` | `integer` | لا | — | int |
| `question_id` | `integer` | لا | — | int |
| `answer_text` | `text` | نعم | — | String أو null |
| `score` | `numeric` | نعم | — | String عشري أو null |
| `status` | `varchar` | لا | `'pending'` | String |

قيم `status`: `pending`, `auto_graded`, `teacher_reviewed`.

- FK `question_id` → `questions.id`؛ عند الحذف: `cascade`.
- FK `attempt_id` → `quiz_attempts.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(attempt_id, question_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `attempt`: `BelongsTo` → `quiz_attempts`.
- `question`: `BelongsTo` → `questions`.
- `ai_evaluation`: `HasOne` → `ai_evaluations`.

### `ai_evaluations`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `AiEvaluation`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `answer_id` | `integer` | لا | — | int |
| `model` | `varchar` | لا | — | String |
| `suggested_score` | `numeric` | نعم | — | String عشري أو null |
| `confidence` | `numeric` | نعم | — | String عشري أو null |
| `explanation` | `text` | نعم | — | String أو null |
| `teacher_score` | `numeric` | نعم | — | String عشري أو null |
| `status` | `varchar` | لا | `'suggested'` | String |

قيم `status`: `suggested`, `approved`, `overridden`.

- FK `answer_id` → `answers.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(answer_id)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `answer`: `BelongsTo` → `answers`.


## الأجهزة والتوكنات

### `devices`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Device`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `device_uuid` | `varchar` | لا | — | String |
| `platform` | `varchar` | لا | — | String |
| `manufacturer` | `varchar` | نعم | — | String أو null |
| `model` | `varchar` | نعم | — | String أو null |
| `os_version` | `varchar` | نعم | — | String أو null |
| `app_version` | `varchar` | نعم | — | String أو null |
| `public_key` | `text` | نعم | — | String أو null |
| `status` | `varchar` | لا | `'trusted'` | String |

قيم `platform`: `android`, `ios`.

قيم `status`: `trusted`, `blocked`.

- قيد فريد: `(device_uuid)`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student_devices`: `HasMany` → `student_devices`.

### `student_devices`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `StudentDevice`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `device_id` | `integer` | لا | — | int |
| `status` | `varchar` | لا | `'active'` | String |
| `is_active` | `tinyint(1)` | لا | `'1'` | bool |
| `activated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `last_seen_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `revoked_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `revoke_reason` | `varchar` | نعم | — | String أو null |

قيم `status`: `active`, `revoked`.

- FK `device_id` → `devices.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(student_id)`.
- قيد فريد: `(student_id, device_id)`.

علاقات ORM، عند تحميلها فقط:

- `student`: `BelongsTo` → `students`.
- `device`: `BelongsTo` → `devices`.
- `device_sessions`: `HasMany` → `device_sessions`.
- `device_events`: `HasMany` → `device_events`.

### `device_sessions`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `DeviceSession`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_device_id` | `integer` | لا | — | int |
| `refresh_token_hash` | `varchar` | لا | — | String |
| `status` | `varchar` | لا | `'active'` | String |
| `last_activity_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `expires_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `revoked_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `revoke_reason` | `varchar` | نعم | — | String أو null |

قيم `status`: `active`, `expired`, `revoked`.

- FK `student_device_id` → `student_devices.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student_device`: `BelongsTo` → `student_devices`.

### `device_events`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `DeviceEvent`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_device_id` | `integer` | لا | — | int |
| `event_type` | `varchar` | لا | — | String |
| `metadata_json` | `text` | نعم | — | JSON أو null |
| `created_at` | `datetime` | لا | `CURRENT_TIMESTAMP` | نص تاريخ/وقت |

- FK `student_device_id` → `student_devices.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `student_device`: `BelongsTo` → `student_devices`.

### `device_transfer_requests`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `DeviceTransferRequest`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `student_id` | `integer` | لا | — | int |
| `source_student_device_id` | `integer` | لا | — | int |
| `target_device_id` | `integer` | لا | — | int |
| `token_hash` | `varchar` | لا | — | String |
| `status` | `varchar` | لا | `'pending'` | String |
| `expires_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `resolved_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `claimed_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- FK `target_device_id` → `devices.id`؛ عند الحذف: `cascade`.
- FK `source_student_device_id` → `student_devices.id`؛ عند الحذف: `cascade`.
- FK `student_id` → `students.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

حقول مخفية بالموديل: `token_hash`.


### `refresh_tokens`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `RefreshToken`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | لا | — | int |
| `token_hash` | `varchar` | لا | — | String |
| `expires_at` | `datetime` | لا | — | نص تاريخ/وقت |
| `revoked_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- FK `user_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.
- قيد فريد: `(token_hash)`.


### `personal_access_tokens`

البنية الحالية بعد جميع Migrations.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `tokenable_type` | `varchar` | لا | — | String |
| `tokenable_id` | `integer` | لا | — | int |
| `name` | `text` | لا | — | String |
| `token` | `varchar` | لا | — | String |
| `abilities` | `text` | نعم | — | String أو null |
| `last_used_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `expires_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- قيد فريد: `(token)`.
- قيد فريد: `(id)`.

### `password_reset_tokens`

البنية الحالية بعد جميع Migrations.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `email` | `varchar` | لا | — | String |
| `token` | `varchar` | لا | — | String |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- قيد فريد: `(email)`.


## التنبيهات والإعدادات والتدقيق

### `notifications`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `Notification`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | لا | — | int |
| `type` | `varchar` | لا | — | String |
| `title` | `varchar` | لا | — | String |
| `body` | `text` | لا | — | String |
| `read_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `related_payment_id` | `integer` | نعم | — | int أو null |
| `data` | `text` | نعم | — | JSON أو null |

- FK `related_payment_id` → `payments.id`؛ عند الحذف: `restrict`.
- FK `user_id` → `users.id`؛ عند الحذف: `cascade`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `user`: `BelongsTo` → `users`.

### `platform_settings`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `PlatformSetting`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `key` | `varchar` | لا | — | String |
| `value` | `text` | لا | — | JSON |
| `created_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |
| `updated_at` | `datetime` | نعم | — | نص تاريخ/وقت أو null |

- قيد فريد: `(key)`.
- قيد فريد: `(id)`.


### `audit_logs`

البنية الحالية بعد جميع Migrations.

موديل Laravel: `AuditLog`.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `user_id` | `integer` | نعم | — | int أو null |
| `action` | `varchar` | لا | — | String |
| `entity_type` | `varchar` | لا | — | String |
| `entity_id` | `integer` | لا | — | int |
| `metadata_json` | `text` | نعم | — | JSON أو null |
| `ip` | `varchar` | نعم | — | String أو null |
| `created_at` | `datetime` | لا | `CURRENT_TIMESTAMP` | نص تاريخ/وقت |

- FK `user_id` → `users.id`؛ عند الحذف: `set null`.
- قيد فريد: `(id)`.

علاقات ORM، عند تحميلها فقط:

- `user`: `BelongsTo` → `users`.
- `entity`: `MorphTo` → هدف متغير حسب Morph.


## جداول داخلية وأرشيفية

### `migrations`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `migration` | `varchar` | لا | — | String |
| `batch` | `integer` | لا | — | int |

- قيد فريد: `(id)`.

### `cache`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `key` | `varchar` | لا | — | String |
| `value` | `text` | لا | — | String |
| `expiration` | `integer` | لا | — | int |

- قيد فريد: `(key)`.

### `cache_locks`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `key` | `varchar` | لا | — | String |
| `owner` | `varchar` | لا | — | String |
| `expiration` | `integer` | لا | — | int |

- قيد فريد: `(key)`.

### `jobs`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `queue` | `varchar` | لا | — | String |
| `payload` | `text` | لا | — | String |
| `attempts` | `integer` | لا | — | int |
| `reserved_at` | `integer` | نعم | — | int أو null |
| `available_at` | `integer` | لا | — | int |
| `created_at` | `integer` | لا | — | int |

- قيد فريد: `(id)`.

### `job_batches`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `varchar` | لا | — | String |
| `name` | `varchar` | لا | — | String |
| `total_jobs` | `integer` | لا | — | int |
| `pending_jobs` | `integer` | لا | — | int |
| `failed_jobs` | `integer` | لا | — | int |
| `failed_job_ids` | `text` | لا | — | String |
| `options` | `text` | نعم | — | String أو null |
| `cancelled_at` | `integer` | نعم | — | int أو null |
| `created_at` | `integer` | لا | — | int |
| `finished_at` | `integer` | نعم | — | int أو null |

- قيد فريد: `(id)`.

### `failed_jobs`

جدول داخلي للخادم.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `uuid` | `varchar` | لا | — | String |
| `connection` | `text` | لا | — | String |
| `queue` | `text` | لا | — | String |
| `payload` | `text` | لا | — | String |
| `exception` | `text` | لا | — | String |
| `failed_at` | `datetime` | لا | `CURRENT_TIMESTAMP` | نص تاريخ/وقت |

- قيد فريد: `(uuid)`.
- قيد فريد: `(id)`.

### `legacy_session_slots`

أرشيف تحويل فتحات الجلسات القديمة.

| الحقل | SQL type | يقبل null | الافتراضي | تمثيل مبدئي في JSON إن ظهر |
|---|---|---|---|---|
| `id` | `integer` | لا | — | int |
| `original_session` | `text` | لا | — | String |
| `converted_availability_id` | `integer` | لا | — | int |

- قيد فريد: `(id)`.
