> تحديث 2026-11-01: وصف الانضمام الفوري للمجموعات في هذا الملف أصبح قديماً؛ إرسال الطالب ينشئ طلباً معلقاً إلى أن يوافق الأستاذ أو يرفض. راجع [TEACHER_FRONTEND_APIS_2026-11-01_04-11.md](TEACHER_FRONTEND_APIS_2026-11-01_04-11.md) للمسارات والحقول المعتمدة لهذه الميزة. بقية هذا الملف محفوظة كمرجع سابق.

# مرجع جميع عمليات API المسجلة — 2026-10-31

يغطي 171 مساراً مسجلاً و177 عملية HTTP بعد استبعاد HEAD المكرر. المصدر هو RouteCollection الفعلي بعد تطبيق Migrations في ذاكرة مؤقتة.

ابدأ بـ FRONTEND_CONTRACT_FINAL_AR.md لتدفقات العمل والأمثلة المعتمدة، ثم استخدم هذا الملف كفهرس كامل لكل المسارات. ملف OpenAPI قابل للاستيراد في أدوات API وملف Postman جاهز للاستيراد مع تعبئة التوكن والمعرّفات والملفات.

## قواعد عامة

- Accept: application/json. المسارات المحمية تحتاج Authorization: Bearer <access_token>. توكن الطالب مرتبط بجهازه؛ لا تستبدله بتوكن مدرس.
- معرّف teacher هو teachers.id والطالب students.id والمستخدم users.id. مسارات checkout تقبل UUID.
- 401 للمصادقة،403 للصلاحية/التوثيق،404 لعدم الوجود/الملكية،409 لتعارض السعة/الوقت/الرصيد،422 للتحقق. بعض المسارات القديمة تعيد422 للتعارض.
- ملفات الدفع والاسترداد خاصة ولا تمرر روابطها لمستخدم آخر. بعض مسارات المحتوى تحتاج signed URL/توكناً صادراً من مسار access-token؛ لا تركب الرابط بنفسك.
- قواعد required_if/required_with والتحقق من ملكية الموارد والشروط داخل الخدمات إضافية إلى الحقول المباشرة. rules بصياغة Laravel كما بالكود لضمان عدم إسقاط القيود.
- جميع الاستجابات الخاصة بالتدفقات المعدلة موضحة أدناه وبالعقد النهائي؛ المخططات العامة للمسارات القديمة متساهلة عمداً، ولا تدّعي أن جميع أعمدة DB تظهر بالاستجابة.
- source هو ملف التنفيذ ورقم بداية العملية لتسهيل المراجعة، وليس رسالة خطأ تظهر للمستخدم.

## 1. `GET /api/v1/teachers/featured`

Controller: `FeaturedTeacherController@index`.

الصلاحيات/Middleware: `api`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/FeaturedTeacherController.php:12`.

## 2. `GET /api/v1/teachers/directory`

Controller: `TeacherDirectoryController@index`.

الصلاحيات/Middleware: `api`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `mode` | `['sometimes', 'in:online,physical']` |
| `search` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `specialization` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `region_id` | `['sometimes', 'integer', 'min:1']` |
| `subject_id` | `['sometimes', 'integer', 'min:1']` |
| `grade` | `['prohibited']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: data:[{id,name,specialization,avatar_url,modes:[online,physical],status,region_id}] وmeta. mode سياق عرض، وليس مفتاح تفعيل قدرة الأستاذ.

المصدر: `app/Http/Controllers/Api/V1/TeacherDirectoryController.php:10`.

## 3. `GET /api/v1/teachers`

Controller: `TeacherDirectoryController@index`.

الصلاحيات/Middleware: `api`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `mode` | `['sometimes', 'in:online,physical']` |
| `search` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `specialization` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `region_id` | `['sometimes', 'integer', 'min:1']` |
| `subject_id` | `['sometimes', 'integer', 'min:1']` |
| `grade` | `['prohibited']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: data:[{id,name,specialization,avatar_url,modes:[online,physical],status,region_id}] وmeta. mode سياق عرض، وليس مفتاح تفعيل قدرة الأستاذ.

المصدر: `app/Http/Controllers/Api/V1/TeacherDirectoryController.php:10`.

## 4. `GET /api/v1/avatars/{user}/{filename}`

Controller: `ProfileAvatarController@show`.

الصلاحيات/Middleware: `api`.

Path parameters: `user`, `filename`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/ProfileAvatarController.php:63`.

## 5. `GET /api/v1/teacher-subjects/most-requested`

Controller: `TeacherSubjectController@mostRequested`.

الصلاحيات/Middleware: `api`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `limit` | `['sometimes', 'integer', 'min:1', 'max:100']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectController.php:16`.

## 6. `GET /api/v1/teachers/{teacher}/subjects`

Controller: `TeacherSubjectController@getSubjectsByTeacher`.

الصلاحيات/Middleware: `api`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectController.php:159`.

## 7. `GET /api/v1/teacher/subjects/{teacherSubject}/units`

Controller: `TeacherSubjectController@units`.

الصلاحيات/Middleware: `api`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectController.php:173`.

## 8. `GET /api/v1/units/{unit}/lessons`

Controller: `UnitController@lessons`.

الصلاحيات/Middleware: `api`.

Path parameters: `unit`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/UnitController.php:74`.

## 9. `GET /api/v1/lessons/{lesson}/contents`

Controller: `CurriculumBrowseController@lessonContents`.

الصلاحيات/Middleware: `api`.

Path parameters: `lesson`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/CurriculumBrowseController.php:12`.

## 10. `PUT /api/v1/admin/payment-settings`

Controller: `ShamCashSettingController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `account_id` | `['required', 'string', 'max:255', 'regex:/\S/u']` |
| `qr_image` | `['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:5120', 'dimensions:max_width=4096,max_height=4096']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/ShamCashSettingController.php:21`.

## 11. `GET /api/v1/admin/payments`

Controller: `ShamCashPaymentController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payment_status` | `['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:132`.

## 12. `GET /api/v1/admin/refunds`

Controller: `RefundController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `status` | `['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED,COMPLETED']` |

الاستجابة والسلوك: data قائمة RefundRequest DTO؛ meta للصفحات.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:65`.

## 13. `POST /api/v1/admin/refunds/{refund}/review`

Controller: `RefundController@review`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `refund`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `decision` | `['required', 'in:approve,reject']` |
| `review_note` | `['required', 'string', 'max:2000', 'regex:/\S/u']` |
| `rejection_code` | `['required_if:decision,reject', 'nullable', 'in:rejected_no_balance,rejected_invalid_data,rejected']` |

الاستجابة والسلوك: data: RefundRequest؛ قرار المراجعة لا يعني إرسال الأموال. rejection_code مطلوب عند الرفض.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:97`.

## 14. `POST /api/v1/admin/refunds/{refund}/complete`

Controller: `RefundController@complete`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `refund`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `transfer_reference` | `['nullable', 'string', 'max:255', Rule::unique('refund_requests', 'transfer_reference')]` |
| `refund_transferred_at` | `['required', 'date', 'before_or_equal:now']` |
| `refund_proof` | `['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:5120']` |

الاستجابة والسلوك: data: RefundRequest مع refund_status=refund_sent وrefunded_amount وrefund_proof_url. الإثبات وتاريخ التحويل إلزاميان، رقم التحويل اختياري.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:122`.

## 15. `POST /api/v1/admin/payments/{payment}/review`

Controller: `EnrollmentPaymentController@review`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `decision` | `['required', 'in:confirm,reject']` |
| `rejection_reason` | `[$payment->provider === 'sham_cash' ? 'required_if:decision,reject' : 'nullable', 'nullable', 'string', 'max:2000', 'regex:/\S/u']` |

الاستجابة والسلوك: data:{payment:PaymentDTO,enrollment:Enrollment}. confirm أوreject؛ السبب إلزامي عند رفض شام كاش.

المصدر: `app/Http/Controllers/Api/V1/EnrollmentPaymentController.php:34`.

## 16. `PATCH /api/v1/admin/users/{user}/suspend`

Controller: `AdminController@suspendUser`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `user`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AdminController.php:28`.

## 17. `PATCH /api/v1/admin/users/{user}/activate`

Controller: `AdminController@activateUser`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `user`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AdminController.php:59`.

## 18. `PATCH /api/v1/admin/students/{user}/suspend`

Controller: `AdminController@suspendStudent`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `user`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `reason` | `['nullable', 'string', 'max:500']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AdminController.php:79`.

## 19. `PATCH /api/v1/admin/students/{user}/activate`

Controller: `AdminController@activateStudent`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `user`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AdminController.php:166`.

## 20. `GET /api/v1/payment-settings`

Controller: `ShamCashSettingController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,company_admin, App\Http\Middleware\StudentDeviceWhenRequired`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/ShamCashSettingController.php:14`.

## 21. `GET /api/v1/payment-settings/qr`

Controller: `ShamCashSettingController@qr`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,company_admin, App\Http\Middleware\StudentDeviceWhenRequired`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/ShamCashSettingController.php:52`.

## 22. `GET /api/v1/payment-proofs/{payment}`

Controller: `ShamCashPaymentController@proof`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,company_admin, App\Http\Middleware\StudentDeviceWhenRequired`.

Path parameters: `payment`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:151`.

## 23. `GET /api/v1/refunds/{refund}`

Controller: `RefundController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,company_admin, App\Http\Middleware\StudentDeviceWhenRequired`.

Path parameters: `refund`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: RefundRequest مع refund_status وrefund_proof_url وoriginal_proof_url؛ حقول مسار الملفات الداخلية مخفية.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:76`.

## 24. `GET /api/v1/refunds/{refund}/proof/{kind}`

Controller: `RefundController@proof`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,company_admin, App\Http\Middleware\StudentDeviceWhenRequired`.

Path parameters: `refund`, `kind`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: ملف خاص؛ يعرضه صاحب الطلب أو الإدارة فقط. kind=refund أو original.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:162`.

## 25. `GET /api/v1/payments`

Controller: `PaymentController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:13`.

## 26. `POST /api/v1/payments`

Controller: `PaymentController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payer_id` | `['required', 'integer', 'exists:users,id']` |
| `payable_type` | `['required', 'string', 'max:255']` |
| `payable_id` | `['required', 'integer', 'min:1']` |
| `amount` | `['required', 'numeric', 'min:0.01', 'max:9999999999.99']` |
| `currency` | `['required', 'string', 'size:3']` |
| `provider` | `['required', 'string', 'max:100']` |
| `provider_reference` | `['nullable', 'string', 'max:255']` |
| `status` | `['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])]` |
| `paid_at` | `['nullable', 'date']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:27`.

## 27. `GET /api/v1/payments/{payment}`

Controller: `PaymentController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:53`.

## 28. `PUT /api/v1/payments/{payment}`

Controller: `PaymentController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payer_id` | `['sometimes', 'integer', 'exists:users,id']` |
| `payable_type` | `['sometimes', 'required', 'string', 'max:255']` |
| `payable_id` | `['sometimes', 'integer', 'min:1']` |
| `amount` | `['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99']` |
| `currency` | `['sometimes', 'string', 'size:3']` |
| `provider` | `['sometimes', 'string', 'max:100']` |
| `provider_reference` | `['nullable', 'string', 'max:255']` |
| `status` | `['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])]` |
| `paid_at` | `['nullable', 'date']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:60`.

## 29. `PATCH /api/v1/payments/{payment}`

Controller: `PaymentController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payer_id` | `['sometimes', 'integer', 'exists:users,id']` |
| `payable_type` | `['sometimes', 'required', 'string', 'max:255']` |
| `payable_id` | `['sometimes', 'integer', 'min:1']` |
| `amount` | `['sometimes', 'numeric', 'min:0.01', 'max:9999999999.99']` |
| `currency` | `['sometimes', 'string', 'size:3']` |
| `provider` | `['sometimes', 'string', 'max:100']` |
| `provider_reference` | `['nullable', 'string', 'max:255']` |
| `status` | `['sometimes', Rule::in(['pending', 'succeeded', 'failed', 'refunded'])]` |
| `paid_at` | `['nullable', 'date']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:60`.

## 30. `DELETE /api/v1/payments/{payment}`

Controller: `PaymentController@destroy`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaymentController.php:87`.

## 31. `GET /api/v1/governorates`

Controller: `LocationController@governorates`.

الصلاحيات/Middleware: `api`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/LocationController.php:18`.

## 32. `GET /api/v1/governorates/{id}/regions`

Controller: `LocationController@regions`.

الصلاحيات/Middleware: `api`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/LocationController.php:36`.

## 33. `GET /api/v1/regions/{id}/schools`

Controller: `LocationController@schools`.

الصلاحيات/Middleware: `api`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/LocationController.php:66`.

## 34. `POST /api/v1/admin/payments/{payment}/pricing-allocation`

Controller: `LegacyPricingAllocationController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `payment`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `review_note` | `['required', 'string', 'max:2000']` |
| `lessons` | `['required', 'array', 'min:1']` |
| `lessons.*.lesson_id` | `['required', 'distinct', 'integer', 'exists:lessons,id']` |
| `lessons.*.allocated_amount` | `['required', 'numeric', 'min:0']` |
| `lessons.*.lesson_price_snapshot` | `['required', 'numeric', 'min:0']` |
| `lessons.*.content_ids_snapshot` | `['required', 'array']` |
| `lessons.*.content_ids_snapshot.*` | `['integer', 'exists:contents,id']` |

الاستجابة والسلوك: data: أهلية الاسترداد بعد حفظ لقطة قديمة موثّقة. ممنوع استبدال لقطة موجودة؛ مجموع التوزيع يجب أن يساوي الدفعة.

المصدر: `app/Http/Controllers/Api/V1/LegacyPricingAllocationController.php:18`.

## 35. `POST /api/v1/teacher/register`

Controller: `TeacherAuthController@registerTeacher`.

الصلاحيات/Middleware: `api`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `'required\|string\|max:255'` |
| `email` | `'nullable\|string\|email\|max:255\|unique:users,email'` |
| `password` | `'required\|string\|min:8\|confirmed'` |
| `phone` | `'required\|string\|max:20\|unique:users,phone'` |
| `region_id` | `'required\|exists:regions,id'` |
| `specialization` | `'nullable\|string\|max:255'` |
| `bio` | `'nullable\|string\|max:1000'` |

الاستجابة والسلوك: 201 message وaccess_token وrefresh_token وuser.teacher_status=pending_review وuser.can_teach=false. التسجيل يدخل الأستاذ لكنه لا يمنحه صلاحيات التدريس.

المصدر: `app/Http/Controllers/Api/V1/TeacherAuthController.php:15`.

## 36. `POST /api/v1/teacher/login`

Controller: `AuthController@login`.

الصلاحيات/Middleware: `api`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `phone` | `['required', 'string', 'max:30']` |
| `password` | `['required', 'string']` |

الاستجابة والسلوك: message والتوكنات وuser مع teacher_status وcan_teach. يستخدم phone+password.

المصدر: `app/Http/Controllers/Api/V1/AuthController.php:16`.

## 37. `POST /api/v1/teacher/refresh`

Controller: `AuthController@refresh`.

الصلاحيات/Middleware: `api`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `refresh_token` | `['required', 'string']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AuthController.php:75`.

## 38. `GET /api/v1/teacher/subjects`

Controller: `TeacherSubjectManagementController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:16`.

## 39. `POST /api/v1/teacher/subjects`

Controller: `TeacherSubjectManagementController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `subject_id` | `['required','integer','exists:subjects,id']` |
| `price` | `['required','numeric','between:0,9999999999.99']` |
| `group_enabled` | `['sometimes','boolean']` |
| `max_group_size` | `['nullable','integer','min:1','max:100000']` |
| `teacher_id` | `['prohibited']` |
| `status` | `$course === null ? ['prohibited'] : ['sometimes', Rule::in(['active', 'inactive'])]` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:26`.

## 40. `GET /api/v1/teacher/subjects/{teacherSubject}`

Controller: `TeacherSubjectManagementController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:52`.

## 41. `PUT /api/v1/teacher/subjects/{teacherSubject}`

Controller: `TeacherSubjectManagementController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherSubject`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `subject_id` | `['sometimes','integer','exists:subjects,id']` |
| `price` | `['sometimes','numeric','between:0,9999999999.99']` |
| `group_enabled` | `['sometimes', 'boolean']` |
| `max_group_size` | `['nullable', 'integer', 'min:1', 'max:100000']` |
| `teacher_id` | `['prohibited']` |
| `status` | `['sometimes','in:active,inactive']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:59`.

## 42. `PATCH /api/v1/teacher/subjects/{teacherSubject}`

Controller: `TeacherSubjectManagementController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherSubject`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `subject_id` | `['sometimes','integer','exists:subjects,id']` |
| `price` | `['sometimes','numeric','between:0,9999999999.99']` |
| `group_enabled` | `['sometimes', 'boolean']` |
| `max_group_size` | `['nullable', 'integer', 'min:1', 'max:100000']` |
| `teacher_id` | `['prohibited']` |
| `status` | `['sometimes','in:active,inactive']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:59`.

## 43. `DELETE /api/v1/teacher/subjects/{teacherSubject}`

Controller: `TeacherSubjectManagementController@destroy`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubjectManagementController.php:72`.

## 44. `GET /api/v1/teacher/me`

Controller: `AuthController@me`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: User مع teacher وcan_teach على المستوى الأعلى؛ ليس data wrapper.

المصدر: `app/Http/Controllers/Api/V1/AuthController.php:70`.

## 45. `GET /api/v1/teacher/subject-catalog`

Controller: `TeacherCourseController@catalog`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/TeacherCourseController.php:11`.

## 46. `GET /api/v1/teacher/subscription`

Controller: `TeacherDashboardController@current`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/TeacherDashboardController.php:11`.

## 47. `GET /api/v1/teacher/subscriptions`

Controller: `TeacherDashboardController@history`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/TeacherDashboardController.php:19`.

## 48. `GET /api/v1/teacher/usage`

Controller: `TeacherDashboardController@usage`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherDashboardController.php:29`.

## 49. `POST /api/v1/teacher/logout`

Controller: `AuthController@logout`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `refresh_token` | `['required', 'string']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AuthController.php:51`.

## 50. `GET /api/v1/teacher/subscription-plans`

Controller: `SubscriptionPlanController@available`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `billing_cycle` | `['sometimes', Rule::in(['monthly', 'yearly'])]` |
| `per_page` | `['sometimes', 'integer', 'min:1', 'max:100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/SubscriptionPlanController.php:12`.

## 51. `POST /api/v1/teacher/subscription-plans/{subscriptionPlan}/subscribe`

Controller: `TeacherSubscriptionController@subscribe`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `subscriptionPlan`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `auto_renew` | `['sometimes', 'boolean']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherSubscriptionController.php:15`.

## 52. `GET /api/v1/teacher/content-assets`

Controller: `TeacherContentAssetController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentAssetController.php:18`.

## 53. `POST /api/v1/teacher/contents`

Controller: `TeacherContentController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `lesson_id` | `[ 'required', 'integer', Rule::exists('lessons', 'id')->where(fn ($query) => $query ->whereExists(fn ($owned) => $owned ->selectRaw('1') ->from('units') ->join('teacher_subjects', 'teacher_subjects.id', '=', 'units.teacher_subject_id') ->whereColumn('units.id', 'lessons.unit_id') ->where('teacher_subjects.teacher_id', $teacherId))), ]` |
| `type` | `['required', Rule::in(['video', 'pdf', 'quiz', 'link'])]` |
| `title` | `['required', 'string', 'max:255']` |
| `description` | `['nullable', 'string']` |
| `learning_objectives` | `['nullable', 'array', 'max:100']` |
| `learning_objectives.*` | `['string', 'max:1000']` |
| `price` | `['sometimes', 'numeric', 'min:0']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', Rule::in(['draft', 'published', 'archived'])]` |
| `published_at` | `['nullable', 'date']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentController.php:13`.

## 54. `POST /api/v1/teacher/contents/{content}/asset`

Controller: `TeacherContentAssetController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `content`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `disk` | `['required', Rule::in(array_values(array_diff(array_keys(config('filesystems.disks')), ['public'])))]` |
| `storage_key` | `['required', 'string', 'max:2048']` |
| `mime_type` | `['required', Rule::in(['application/pdf', 'video/mp4', 'video/quicktime', 'video/webm', 'video/x-matroska'])]` |
| `file_size` | `['nullable', 'integer', 'min:0']` |
| `checksum` | `['nullable', 'string', 'max:255']` |
| `encryption_type` | `['nullable', 'string', 'max:100']` |
| `encryption_status` | `['sometimes', Rule::in(['pending', 'processing', 'done', 'failed'])]` |
| `status` | `['sometimes', Rule::in(['active', 'archived'])]` |
| `file` | `['required', 'file', 'max:512000']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentAssetController.php:34`.

## 55. `GET /api/v1/teacher/content-assets/{contentAsset}`

Controller: `TeacherContentAssetController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `contentAsset`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentAssetController.php:115`.

## 56. `DELETE /api/v1/teacher/content-assets/{contentAsset}`

Controller: `TeacherContentAssetController@destroy`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `contentAsset`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentAssetController.php:122`.

## 57. `GET /api/v1/teacher/availability`

Controller: `TeacherAvailabilityController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/TeacherAvailabilityController.php:26`.

## 58. `POST /api/v1/teacher/availability`

Controller: `TeacherAvailabilityController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `price` | `['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99']` |
| `weekday` | `[ 'nullable', 'integer', 'between:0,6', ]` |
| `date` | `[ 'nullable', 'date', ]` |
| `start_time` | `[ 'required', 'date_format:H:i', ]` |
| `end_time` | `[ 'required', 'date_format:H:i', 'after:start_time', ]` |
| `recurrence_type` | `[ 'required', 'in:none,weekly,custom', ]` |
| `timezone` | `[ 'required', 'timezone', ]` |
| `status` | `[ 'sometimes', 'in:active,disabled', ]` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherAvailabilityController.php:46`.

## 59. `PUT /api/v1/teacher/availability/{teacherAvailability}`

Controller: `TeacherAvailabilityController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherAvailability`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `price` | `['sometimes', 'nullable', 'numeric', 'min:0.01', 'max:9999999999.99']` |
| `weekday` | `[ 'sometimes', 'nullable', 'integer', 'between:0,6', ]` |
| `date` | `[ 'sometimes', 'nullable', 'date', ]` |
| `start_time` | `[ 'sometimes', 'date_format:H:i', ]` |
| `end_time` | `[ 'sometimes', 'date_format:H:i', ]` |
| `recurrence_type` | `[ 'sometimes', 'in:none,weekly,custom', ]` |
| `timezone` | `[ 'sometimes', 'timezone', ]` |
| `status` | `[ 'sometimes', 'in:active,disabled', ]` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherAvailabilityController.php:120`.

## 60. `DELETE /api/v1/teacher/availability/{teacherAvailability}`

Controller: `TeacherAvailabilityController@destroy`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `teacherAvailability`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherAvailabilityController.php:203`.

## 61. `GET /api/v1/teacher/sessions`

Controller: `SessionController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:36`.

## 62. `POST /api/v1/teacher/sessions`

Controller: `SessionController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `type` | `['sometimes', 'in:individual,group']` |
| `type_id` | `['sometimes', 'required', 'integer', 'min:1']` |
| `starts_at` | `['sometimes', 'date']` |
| `ends_at` | `['sometimes', 'date']` |
| `teacher_id` | `$teacherId` |
| `group_id` | `['prohibited']` |
| `enrollment_id` | `['prohibited']` |
| `individual_id` | `['prohibited']` |
| `availability_id` | `['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id']` |
| `progress_notes` | `['sometimes', 'nullable', 'string', 'max:10000']` |
| `capacity` | `['sometimes', 'nullable', 'integer', 'min:1']` |
| `status` | `'scheduled'` |

الاستجابة والسلوك: 201 data: Session. type=group|individual وtype_id، الفردي سعته1 دائماً.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:54`.

## 63. `PATCH /api/v1/teacher/sessions/{session}`

Controller: `SessionController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `session`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `teacher_id` | `['prohibited']` |
| `type` | `['sometimes', 'in:individual,group']` |
| `type_id` | `['sometimes', 'required', 'integer', 'min:1']` |
| `group_id` | `['prohibited']` |
| `enrollment_id` | `['prohibited']` |
| `individual_id` | `['prohibited']` |
| `availability_id` | `['sometimes', 'nullable', 'integer', 'exists:teacher_availabilities,id']` |
| `starts_at` | `['sometimes', 'date']` |
| `ends_at` | `['sometimes', 'date']` |
| `progress_notes` | `['sometimes', 'nullable', 'string', 'max:10000']` |
| `capacity` | `['sometimes', 'nullable', 'integer', 'min:1']` |
| `status` | `['sometimes', 'in:scheduled,ongoing,completed']` |

الاستجابة والسلوك: data: Session. تغيير type يتطلب type_id. تغيير المشاركين ممنوع بعد تسجيل حضور.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:203`.

## 64. `POST /api/v1/teacher/v1/sessions/{session}/cancel`

Controller: `SessionController@cancel`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `session`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:120`.

## 65. `POST /api/v1/teacher/sessions/{id}/attendance`

Controller: `AttendanceController@recordForSession`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `id`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `records` | `['required', 'array', 'min:1']` |
| `records.*.student_id` | `['required', 'distinct', 'integer', 'exists:students,id']` |
| `records.*.enrollment_id` | `['prohibited']` |
| `records.*.status` | `['required', Rule::in(['present', 'absent', 'late', 'excused'])]` |
| `records.*.check_in_at` | `['nullable', 'date']` |
| `records.*.check_out_at` | `['nullable', 'date']` |
| `records.*.note` | `['nullable', 'string', 'max:10000']` |

الاستجابة والسلوك: data: Attendance[] مع student عند تحميله؛ التحديث idempotent لكل session_id+student_id.

المصدر: `app/Http/Controllers/Api/V1/AttendanceController.php:41`.

## 66. `PATCH /api/v1/teacher/{teacher}/activate`

Controller: `TeacherAuthController@activateTeacher`.

الصلاحيات/Middleware: `api, auth:sanctum, role:company_admin`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/TeacherAuthController.php:64`.

## 67. `GET /api/v1/teacher/groups`

Controller: `GroupController@indexForTeacher`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/GroupController.php:14`.

## 68. `POST /api/v1/teacher/groups`

Controller: `GroupController@storeForTeacher`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `teacher_subject_id` | `['required', 'integer', 'exists:teacher_subjects,id']` |
| `teaching_scope_type` | `['sometimes', 'in:subject,unit,lesson']` |
| `teaching_scope_id` | `['required_with:teaching_scope_type', 'integer']` |
| `session_price` | `['required', 'numeric', 'between:0,9999999999.99']` |
| `starts_at` | `['nullable', 'date']` |
| `ends_at` | `array_merge(['nullable', 'date'], $request->filled('starts_at') ? ['after:starts_at'] : [])` |
| `name` | `['required', 'string', 'max:255']` |
| `capacity` | `['required', 'integer', 'min:1', 'max:100000']` |
| `status` | `['sometimes', Rule::in(['forming', 'active', 'closed'])]` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/GroupController.php:69`.

## 69. `POST /api/v1/teacher/groups/{id}/students`

Controller: `GroupStudentController@addToTeacherGroup`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `id`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `student_id` | `['required', 'integer', 'exists:students,id']` |
| `enrollment_id` | `['prohibited']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/GroupStudentController.php:15`.

## 70. `DELETE /api/v1/teacher/groups/{id}/students/{student}`

Controller: `GroupStudentController@removeFromTeacherGroup`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `id`, `student`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/GroupStudentController.php:35`.

## 71. `PATCH /api/v1/teacher/profile`

Controller: `StudentLearningController@updateTeacher`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `email` | `['sometimes', 'required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)]` |
| `phone` | `['sometimes', 'required', 'string', 'max:20', Rule::unique('users', 'phone')->ignore($user->id)]` |
| `region_id` | `['sometimes', 'required', 'integer', 'exists:regions,id']` |
| `experience_years` | `['sometimes', 'nullable', 'integer', 'min:0', 'max:100']` |
| `bio` | `['sometimes', 'nullable', 'string', 'max:10000']` |
| `specialization` | `['sometimes', 'nullable', 'string', 'max:255']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentLearningController.php:111`.

## 72. `PATCH /api/v1/teacher/contents/{content}`

Controller: `TeacherContentController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `content`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `title` | `['sometimes', 'required', 'string', 'max:255']` |
| `description` | `['sometimes', 'nullable', 'string']` |
| `learning_objectives` | `['sometimes', 'nullable', 'array', 'max:100']` |
| `learning_objectives.*` | `['string', 'max:1000']` |
| `price` | `['sometimes', 'numeric', 'min:0']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', Rule::in(['draft', 'published', 'archived'])]` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeacherContentController.php:48`.

## 73. `POST /api/v1/teacher/sessions/{session}/complete`

Controller: `SessionController@complete`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `session`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:251`.

## 74. `POST /api/v1/teacher/sessions/{session}/cancel`

Controller: `SessionController@cancel`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `session`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:120`.

## 75. `PATCH /api/v1/teacher/sessions/{session}/progress`

Controller: `SessionController@progress`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `session`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `progress_notes` | `['present', 'nullable', 'string', 'max:10000']` |

الاستجابة والسلوك: data: Session؛ progress_notes وprogress_updated_at محدثان؛ يقبل null للمسح، ويعمل على completed ولا يعمل على cancelled.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:236`.

## 76. `GET /api/v1/teacher/individuals`

Controller: `IndividualController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: Individual[] مع student.user المختصر وteacher_subject.subject؛ meta.

المصدر: `app/Http/Controllers/Api/V1/IndividualController.php:15`.

## 77. `POST /api/v1/teacher/individuals`

Controller: `IndividualController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `student_id` | `['required', 'integer', 'exists:students,id']` |
| `teacher_subject_id` | `['required', 'integer', 'exists:teacher_subjects,id']` |
| `teaching_scope_type` | `['sometimes', 'in:subject,unit,lesson']` |
| `teaching_scope_id` | `['required_with:teaching_scope_type', 'integer']` |
| `session_price` | `['sometimes', 'numeric', 'between:0,9999999999.99']` |
| `starts_at` | `['nullable', 'date']` |
| `ends_at` | `array_merge(['nullable', 'date'], $request->filled('starts_at') ? ['after:starts_at'] : [])` |
| `notes` | `['nullable', 'string', 'max:10000']` |

الاستجابة والسلوك: 201 data: Individual. لا enrollment_id؛ المدرّس من مادة المدرّس.

المصدر: `app/Http/Controllers/Api/V1/IndividualController.php:23`.

## 78. `GET /api/v1/teacher/individuals/{individual}`

Controller: `IndividualController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `individual`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: Individual مع student.user وteacher_subject.subject وsessions.

المصدر: `app/Http/Controllers/Api/V1/IndividualController.php:42`.

## 79. `PUT /api/v1/teacher/individuals/{individual}`

Controller: `IndividualController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `individual`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `student_id` | `['prohibited']` |
| `teacher_subject_id` | `['prohibited']` |
| `status` | `['sometimes', 'in:active,completed,cancelled']` |
| `starts_at` | `['sometimes', 'nullable', 'date']` |
| `ends_at` | `['sometimes', 'nullable', 'date']` |
| `notes` | `['sometimes', 'nullable', 'string', 'max:10000']` |

الاستجابة والسلوك: data: Individual. الطالب والمادة غير قابلين لإعادة التعيين؛ تغيير الحالة/الفترة لا يبطل جلسات معلقة.

المصدر: `app/Http/Controllers/Api/V1/IndividualController.php:49`.

## 80. `PATCH /api/v1/teacher/individuals/{individual}`

Controller: `IndividualController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `individual`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `student_id` | `['prohibited']` |
| `teacher_subject_id` | `['prohibited']` |
| `status` | `['sometimes', 'in:active,completed,cancelled']` |
| `starts_at` | `['sometimes', 'nullable', 'date']` |
| `ends_at` | `['sometimes', 'nullable', 'date']` |
| `notes` | `['sometimes', 'nullable', 'string', 'max:10000']` |

الاستجابة والسلوك: data: Individual. الطالب والمادة غير قابلين لإعادة التعيين؛ تغيير الحالة/الفترة لا يبطل جلسات معلقة.

المصدر: `app/Http/Controllers/Api/V1/IndividualController.php:49`.

## 81. `GET /api/v1/teacher/individual-offers`

Controller: `IndividualOfferController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/IndividualOfferController.php:15`.

## 82. `POST /api/v1/teacher/individual-offers`

Controller: `IndividualOfferController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `teacher_subject_id` | `['required', 'integer', 'exists:teacher_subjects,id']` |
| `teaching_scope_type` | `['required', 'in:subject,unit,lesson']` |
| `teaching_scope_id` | `['required', 'integer']` |
| `title` | `['required', 'string', 'max:255']` |
| `session_price` | `['required', 'numeric', 'min:0', 'max:9999999999.99']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/IndividualOfferController.php:20`.

## 83. `PATCH /api/v1/teacher/individual-offers/{individualOffer}`

Controller: `IndividualOfferController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `individualOffer`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `title` | `['sometimes', 'string', 'max:255']` |
| `status` | `['sometimes', 'in:active,inactive']` |
| `session_price` | `['sometimes', 'numeric', 'min:0', 'max:9999999999.99']` |
| `teacher_subject_id` | `['prohibited']` |
| `teaching_scope_type` | `['prohibited']` |
| `teaching_scope_id` | `['prohibited']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/IndividualOfferController.php:30`.

## 84. `GET /api/v1/teacher/groups/{group}/students`

Controller: `TeacherGroupMembersController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `group`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: GroupStudent[] مع student(user:{id,name,phone,region_id,avatar_url},school.region)؛ للأستاذ المالك فقط، وmeta.

المصدر: `app/Http/Controllers/Api/V1/TeacherGroupMembersController.php:10`.

## 85. `POST /api/v1/teacher/units`

Controller: `TeachingContentController@storeUnit`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `teacher_subject_id` | `['required', 'integer', 'exists:teacher_subjects,id']` |
| `title` | `['required', 'string', 'max:255']` |
| `price` | `['nullable', 'numeric', 'min:0', 'max:9999999999.99']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', 'in:draft,published,archived']` |

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:15`.

## 86. `PATCH /api/v1/teacher/units/{unit}`

Controller: `TeachingContentController@updateUnit`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `unit`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `title` | `['sometimes', 'required', 'string', 'max:255']` |
| `price` | `['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', 'in:draft,published,archived']` |
| `teacher_subject_id` | `['prohibited']` |

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:23`.

## 87. `POST /api/v1/teacher/lessons`

Controller: `TeachingContentController@storeLesson`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `unit_id` | `['required', 'integer', 'exists:units,id']` |
| `title` | `['required', 'string', 'max:255']` |
| `price` | `['required', 'numeric', 'min:0', 'max:9999999999.99']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', 'in:draft,published,archived']` |

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:32`.

## 88. `PATCH /api/v1/teacher/lessons/{lesson}`

Controller: `TeachingContentController@updateLesson`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `lesson`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `title` | `['sometimes', 'required', 'string', 'max:255']` |
| `price` | `['sometimes', 'numeric', 'min:0', 'max:9999999999.99']` |
| `order_no` | `['sometimes', 'integer', 'min:0']` |
| `status` | `['sometimes', 'in:draft,published,archived']` |
| `unit_id` | `['prohibited']` |

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:40`.

## 89. `PUT /api/v1/teacher/contents/{content}/quiz`

Controller: `TeachingContentController@quiz`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `content`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `quiz_type` | `['required', 'in:simple,protected']` |
| `attempts_allowed` | `['required', 'integer', 'between:1,100']` |
| `duration_minutes` | `['nullable', 'integer', 'between:1,1440']` |
| `status` | `['required', 'in:draft,published,archived']` |
| `questions` | `['required', 'array', 'min:1', 'max:100']` |
| `questions.*.type` | `['required', 'in:mcq,true_false,essay']` |
| `questions.*.body` | `['required', 'string', 'max:10000']` |
| `questions.*.points` | `['required', 'numeric', 'between:0,1000']` |
| `questions.*.rubric_json` | `['nullable', 'array']` |
| `questions.*.rubric_json.options` | `['sometimes', 'array']` |
| `questions.*.rubric_json.correct_answer` | `['sometimes']` |
| `questions.*.rubric_json.explanation` | `['sometimes', 'string']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:49`.

## 90. `GET /api/v1/teacher/notifications`

Controller: `StudentNotificationController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:[{id,type,title,body,data,created_at,related_payment_id,read,read_at}] وmeta.

المصدر: `app/Http/Controllers/Api/V1/StudentNotificationController.php:10`.

## 91. `POST /api/v1/teacher/notifications/{notification}/read`

Controller: `StudentNotificationController@read`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `notification`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:{id,read:true,read_at}. سجل المستخدم نفسه فقط.

المصدر: `app/Http/Controllers/Api/V1/StudentNotificationController.php:18`.

## 92. `POST /api/v1/teacher/answers/{answer}/grade`

Controller: `TeachingContentController@grade`.

الصلاحيات/Middleware: `api, auth:sanctum, role:teacher`.

Path parameters: `answer`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `score` | `['required', 'numeric', 'min:0']` |

الاستجابة والسلوك: data:{answer,attempt}. يراجع المدرّس صاحب الاختبار فقط، والعلامة لا تتجاوز points للسؤال؛ يُعاد حساب نتيجة المحاولة.

المصدر: `app/Http/Controllers/Api/V1/TeachingContentController.php:74`.

## 93. `POST /api/v1/auth/register`

Controller: `StudentAuthController@register`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['required', 'string', 'max:255']` |
| `phone` | `['required', 'string', 'max:30', 'unique:users,phone']` |
| `email` | `['nullable', 'email', 'max:255', 'unique:users,email']` |
| `password` | `['required', 'string', 'min:8', 'confirmed']` |
| `region_id` | `['required', 'integer', 'exists:regions,id']` |
| `grade` | `['required', 'string', 'max:100']` |
| `school_id` | `[ 'required', 'integer', Rule::exists('schools', 'id')->where( fn ($query) => $query->where('region_id', $request->region_id) ), ]` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: 201 استجابة تسجيل دخول الطالب: status,message,token_type,access_token,access_token_expires_at,refresh_token,refresh_token_expires_at,user,student,device. البريد غير ظاهر في user.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:31`.

## 94. `POST /api/v1/auth/login`

Controller: `StudentAuthController@studentLogin`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `phone` | `['required', 'string', 'max:30']` |
| `password` | `['required', 'string']` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: نفس بيانات جلسة الطالب والتوكنات وuser/student/device. لا تتجاوز بيانات الجهاز.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:78`.

## 95. `POST /api/v1/auth/refresh`

Controller: `StudentAuthController@studentRefresh`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `refresh_token` | `['required', 'string']` |
| `device_uuid` | `['required', 'string', 'max:255']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:181`.

## 96. `POST /api/v1/auth/device/transfer`

Controller: `StudentAuthController@studentDeviceTransfer`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `phone` | `['required','string','max:30']` |
| `password` | `['required','string']` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:17`.

## 97. `POST /api/v1/auth/logout`

Controller: `StudentAuthController@studentLogout`.

الصلاحيات/Middleware: `api, throttle:10,1, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:123`.

## 98. `GET /api/v1/me`

Controller: `StudentAuthController@studentMe`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status,user,student على المستوى الأعلى، دون data wrapper؛ لا email داخل user.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:350`.

## 99. `POST /api/v1/teachers/{teacher}/bookings`

Controller: `IndividualBookingController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `availability_id` | `['required', 'integer', 'exists:teacher_availabilities,id']` |
| `individual_id` | `['required', 'integer', 'exists:individuals,id']` |
| `enrollment_id` | `['prohibited']` |
| `starts_at` | `['required', 'date', 'after:now']` |
| `ends_at` | `['required', 'date', 'after:starts_at']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/IndividualBookingController.php:12`.

## 100. `GET /api/v1/teachers/{teacher}/available-sessions`

Controller: `AvailableSessionController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `from` | `['sometimes', 'date']` |
| `to` | `array_merge(['sometimes', 'date'], $request->filled('from') ? ['after_or_equal:from'] : [])` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/AvailableSessionController.php:17`.

## 101. `GET /api/v1/student/bookings`

Controller: `StudentBookingController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `scope` | `['sometimes', 'in:upcoming,history,all']` |

الاستجابة والسلوك: {data:[StudentSessionSummary]}. لا status ولا meta في هذا المسار القديم؛ DTO موضح في دليل التدفقات.

المصدر: `app/Http/Controllers/Api/V1/StudentBookingController.php:10`.

## 102. `POST /api/v1/student/enrollments/{enrollment}/payments`

Controller: `EnrollmentPaymentController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `enrollment`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: 410 CHECKOUT_REQUIRED؛ لا تنشئ حوالة عبر المسار القديم، استخدم checkout ثم student/payments.

المصدر: `app/Http/Controllers/Api/V1/EnrollmentPaymentController.php:16`.

## 103. `POST /api/v1/payments/checkout`

Controller: `ShamCashPaymentController@checkout`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `service_type` | `['required', 'in:teacher_subject,unit,lesson,content']` |
| `service_id` | `['required', 'integer', 'min:1']` |

الاستجابة والسلوك: 201 data:{checkout_id,internal_reference,amount,currency,service,account_id,qr_url,expires_at,settings_url}. الأونلاين فقط: teacher_subject/unit/lesson/content.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:21`.

## 104. `GET /api/v1/teachers/{teacher}/paid-slots`

Controller: `Closure`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: 410 PHYSICAL_CASH_ONLY؛ استخدم الحجز الفيزيائي النقدي.

## 105. `GET /api/v1/student/paid-bookings`

Controller: `PaidLessonController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/PaidLessonController.php:184`.

## 106. `GET /api/v1/student/paid-bookings/{booking}`

Controller: `PaidLessonController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `booking`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/PaidLessonController.php:192`.

## 107. `POST /api/v1/student/paid-bookings/{booking}/reschedule`

Controller: `Closure`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `booking`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: 410 LEGACY_BOOKING_REQUIRES_SUPPORT؛ استخدم الحجز الفيزيائي النقدي.

## 108. `POST /api/v1/refunds`

Controller: `RefundController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payment_id` | `['required', 'integer']` |
| `account_id` | `['required', 'string', 'max:255', 'regex:/\S/u']` |
| `reason_code` | `['sometimes', 'in:exceptional,service_unavailable,teacher_suspended']` |
| `reason` | `['sometimes', 'string', 'max:2000']` |
| `original_transfer_date` | `['nullable', 'date', 'before_or_equal:now']` |
| `original_transfer_number` | `['nullable', 'string', 'max:255']` |
| `original_proof` | `['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']` |

الاستجابة والسلوك: 201 data: RefundRequest DTO مع refund_status وproof URLs المصرح بها. المبلغ من الباك ولا يقبل قيمة من الطالب.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:36`.

## 109. `GET /api/v1/refunds/my`

Controller: `RefundController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `status` | `['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED,COMPLETED']` |

الاستجابة والسلوك: data قائمة RefundRequest DTO؛ meta للصفحات.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:65`.

## 110. `GET /api/v1/payments/checkout/{checkout}/qr`

Controller: `ShamCashPaymentController@checkoutQr`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `checkout`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:123`.

## 111. `POST /api/v1/student/payments`

Controller: `ShamCashPaymentController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `checkout_id` | `['required', 'uuid']` |
| `provider_reference` | `['required', 'string', 'max:255']` |
| `proof` | `['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'mimetypes:application/pdf,image/jpeg,image/png', 'max:'.config('payments.proof_max_kb')]` |

الاستجابة والسلوك: 201 (أو200 عند إعادة نفس الطلب) data: Payment DTO مع financial_status/refundable_amount/refunded_amount/payment_status/proof_url. body=file proof وcheckout_id وprovider_reference.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:44`.

## 112. `GET /api/v1/student/payments`

Controller: `ShamCashPaymentController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payment_status` | `['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/ShamCashPaymentController.php:132`.

## 113. `GET /api/v1/student/payments/{payment}`

Controller: `EnrollmentPaymentController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `payment`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: PaymentDTO لشام كاش، أو Payment للسجلات المالية القديمة الأخرى.

المصدر: `app/Http/Controllers/Api/V1/EnrollmentPaymentController.php:27`.

## 114. `GET /api/v1/student/enrollments`

Controller: `StudentEnrollmentController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentEnrollmentController.php:20`.

## 115. `GET /api/v1/student/booking`

Controller: `StudentEnrollmentController@current`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentEnrollmentController.php:27`.

## 116. `POST /api/v1/teacher-subjects/{id}/enroll`

Controller: `StudentEnrollmentController@enrollTeacherSubject`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/StudentEnrollmentController.php:83`.

## 117. `POST /api/v1/units/{id}/enroll`

Controller: `StudentEnrollmentController@enrollUnit`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/StudentEnrollmentController.php:127`.

## 118. `POST /api/v1/contents/{id}/enroll`

Controller: `StudentEnrollmentController@enrollContent`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/StudentEnrollmentController.php:185`.

## 119. `GET /api/v1/student/attendance/percentage`

Controller: `AttendanceController@percentage`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/AttendanceController.php:105`.

## 120. `GET /api/v1/quizzes/{quiz}/data`

Controller: `QuizController@studentData`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `quiz`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:{quiz_type,protected,progress_weight,quiz,attempts_used,attempts_remaining,active_attempt}. quiz.questions لا تتضمن مفاتيح التصحيح السرية.

المصدر: `app/Http/Controllers/Api/V1/QuizController.php:43`.

## 121. `POST /api/v1/quizzes/{quiz}/attempts`

Controller: `QuizAttemptController@start`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `quiz`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: 201 data:{attempt,attempt_id,quiz_id,attempts_remaining}. لا يمكن تجاوز attempts_allowed.

المصدر: `app/Http/Controllers/Api/V1/QuizAttemptController.php:47`.

## 122. `POST /api/v1/quiz-attempts/{quizAttempt}/answers`

Controller: `AnswerController@submitForAttempt`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `quizAttempt`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `question_id` | `['required', 'integer', 'exists:questions,id']` |
| `answer_text` | `['present', 'nullable', 'string']` |

الاستجابة والسلوك: data: Answer مع score/status من الباك؛ لا يقبل score من الطالب.

المصدر: `app/Http/Controllers/Api/V1/AnswerController.php:46`.

## 123. `POST /api/v1/contents/{id}/playback-token`

Controller: `StudentContentAssetController@playbackToken`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentContentAssetController.php:19`.

## 124. `GET /api/v1/content-assets/{contentAsset}/play`

Controller: `StudentContentAssetController@play`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device, signed`.

Path parameters: `contentAsset`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentContentAssetController.php:79`.

## 125. `POST /api/v1/sessions/{id}/book`

Controller: `SessionController@book`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:82`.

## 126. `DELETE /api/v1/sessions/{id}/booking`

Controller: `SessionController@cancelBooking`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `id`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/SessionController.php:93`.

## 127. `GET /api/v1/auth/device/transfer-requests/{transfer}`

Controller: `StudentAuthController@transferStatus`.

الصلاحيات/Middleware: `api, throttle:30,1`.

Path parameters: `transfer`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:49`.

## 128. `POST /api/v1/auth/device/transfer-requests`

Controller: `StudentAuthController@studentDeviceTransfer`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `phone` | `['required','string','max:30']` |
| `password` | `['required','string']` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:17`.

## 129. `POST /api/v1/auth/device/transfer-requests/{transfer}/claim`

Controller: `StudentAuthController@claimTransfer`.

الصلاحيات/Middleware: `api, throttle:10,1`.

Path parameters: `transfer`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:63`.

## 130. `GET /api/v1/teachers/{teacher}`

Controller: `StudentLearningController@teacher`.

الصلاحيات/Middleware: `api`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentLearningController.php:89`.

## 131. `PUT /api/v1/profile`

Controller: `StudentProfileController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `profile_image` | `['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` |
| `phone` | `['prohibited']` |
| `email` | `['prohibited']` |
| `password` | `['prohibited']` |
| `region_id` | `['prohibited']` |
| `school_id` | `['prohibited']` |
| `grade` | `['prohibited']` |

الاستجابة والسلوك: استجابة studentMe نفسها: status,user,student. لا يقبل الهاتف والبريد والصف والمدرسة والمنطقة أو كلمة المرور.

المصدر: `app/Http/Controllers/Api/V1/StudentProfileController.php:11`.

## 132. `PATCH /api/v1/profile`

Controller: `StudentProfileController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `profile_image` | `['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` |
| `phone` | `['prohibited']` |
| `email` | `['prohibited']` |
| `password` | `['prohibited']` |
| `region_id` | `['prohibited']` |
| `school_id` | `['prohibited']` |
| `grade` | `['prohibited']` |

الاستجابة والسلوك: استجابة studentMe نفسها: status,user,student. لا يقبل الهاتف والبريد والصف والمدرسة والمنطقة أو كلمة المرور.

المصدر: `app/Http/Controllers/Api/V1/StudentProfileController.php:11`.

## 133. `GET /api/v1/contents/{content}/assets`

Controller: `StudentContentAssetController@assets`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `content`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentContentAssetController.php:40`.

## 134. `POST /api/v1/content-assets/{contentAsset}/access-token`

Controller: `StudentContentAssetController@accessToken`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `contentAsset`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentContentAssetController.php:52`.

## 135. `POST /api/v1/student/contents/{content}/complete`

Controller: `StudentLearningController@complete`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `content`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentLearningController.php:20`.

## 136. `GET /api/v1/student/teacher-subjects/{teacherSubject}/progress`

Controller: `StudentLearningController@progress`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentLearningController.php:40`.

## 137. `GET /api/v1/student/teacher-subjects/{teacherSubject}`

Controller: `StudentLearningController@course`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/StudentLearningController.php:64`.

## 138. `GET /api/v1/student/sessions/{session}`

Controller: `StudentBookingController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `session`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: StudentSessionSummary.

المصدر: `app/Http/Controllers/Api/V1/StudentBookingController.php:23`.

## 139. `GET /api/v1/student/device-transfer-requests`

Controller: `StudentAuthController@pendingTransfers`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:88`.

## 140. `POST /api/v1/student/device-transfer-requests/{transfer}/{decision}`

Controller: `StudentAuthController@decideTransfer`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `transfer`, `decision`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/Concerns/HandlesDeviceTransfers.php:97`.

## 141. `GET /api/v1/student/notifications`

Controller: `StudentNotificationController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:[{id,type,title,body,data,created_at,related_payment_id,read,read_at}] وmeta.

المصدر: `app/Http/Controllers/Api/V1/StudentNotificationController.php:10`.

## 142. `POST /api/v1/student/notifications/{notification}/read`

Controller: `StudentNotificationController@read`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `notification`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:{id,read:true,read_at}. سجل المستخدم نفسه فقط.

المصدر: `app/Http/Controllers/Api/V1/StudentNotificationController.php:18`.

## 143. `GET /api/v1/teachers/{teacher}/available-slots`

Controller: `AvailabilityBookingController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `from` | `['sometimes', 'date']` |
| `to` | `['sometimes', 'date']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/AvailabilityBookingController.php:14`.

## 144. `POST /api/v1/teacher-availabilities/{availability}/book`

Controller: `AvailabilityBookingController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `availability`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `individual_id` | `['required', 'integer', 'exists:individuals,id']` |
| `enrollment_id` | `['prohibited']` |
| `starts_at` | `['required', 'date']` |
| `ends_at` | `['required', 'date', 'after:starts_at']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/AvailabilityBookingController.php:26`.

## 145. `POST /api/v1/quiz-attempts/{quizAttempt}/submit`

Controller: `QuizAttemptController@submit`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `quizAttempt`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data:{attempt_id,quiz_id,status,submitted_at,score,grading_pending}. simple الموضوعي يُصحح آلياً والمقالي ينتظر مراجعة الأستاذ.

المصدر: `app/Http/Controllers/Api/V1/QuizAttemptController.php:113`.

## 146. `GET /api/v1/contents/{content}`

Controller: `CurriculumBrowseController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `content`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/CurriculumBrowseController.php:37`.

## 147. `GET /api/v1/teachers/{teacher}/calendar`

Controller: `TeacherCalendarController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `from` | `['sometimes', 'date']` |
| `to` | `['sometimes', 'date']` |
| `timezone` | `['sometimes', 'timezone']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: استجابة JSON مخصصة لهذا المسار؛ لا تفترض أنها تتبع غلاف paginated العام. راجع تفاصيل هذا المورد في ملفات docs الخاصة به ومرجع النموذج.

المصدر: `app/Http/Controllers/Api/V1/TeacherCalendarController.php:15`.

## 148. `GET /api/v1/student/my-quizzes`

Controller: `StudentQuizReadController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `scope` | `['sometimes', 'in:available,history,all']` |
| `status` | `['sometimes', 'in:not_started,in_progress,submitted,graded,pending_grading']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/StudentQuizReadController.php:28`.

## 149. `GET /api/v1/student/my-quizzes/{quiz}/attempts`

Controller: `StudentQuizReadController@attempts`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `quiz`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/StudentQuizReadController.php:92`.

## 150. `GET /api/v1/student/groups`

Controller: `StudentGroupReadController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `scope` | `['sometimes', 'in:active,history,all']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: status=true وdata قائمة بالعناصر التي يختارها Controller وmeta(current_page,per_page,total,last_page). العلاقات اختيارية؛ راجع موديل المورد في DATABASE_REFERENCE_AR.md.

المصدر: `app/Http/Controllers/Api/V1/StudentGroupReadController.php:12`.

## 151. `GET /api/v1/student/groups/{group}/members`

Controller: `StudentGroupReadController@members`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `group`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: مسار خاص/ملف أو إجراء مفوّض إلى خدمة؛ الشروط والصلاحيات موضحة في middleware ومصدر العملية.

المصدر: `app/Http/Controllers/Api/V1/StudentGroupReadController.php:59`.

## 152. `GET /api/v1/contents/{content}/quizzes`

Controller: `CurriculumBrowseController@quizzes`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `content`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/CurriculumBrowseController.php:44`.

## 153. `POST /api/v1/profile/avatar`

Controller: `ProfileAvatarController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,teacher, App\Http\Middleware\StudentDeviceWhenRequired, throttle:20,1`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `avatar` | `['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:max_width=4096,max_height=4096']` |

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/ProfileAvatarController.php:15`.

## 154. `DELETE /api/v1/profile/avatar`

Controller: `ProfileAvatarController@destroy`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student,teacher, App\Http\Middleware\StudentDeviceWhenRequired`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: غلاف success الحالي: status=true وdata للمورد/العملية وmessage عند توفرها. الحقول الإضافية والعلاقات تختلف حسب العملية؛ مخططات DB مرجع أسماء وليست تعهداً بإرجاع كل الأعمدة.

المصدر: `app/Http/Controllers/Api/V1/ProfileAvatarController.php:43`.

## 155. `POST /api/v1/student/register`

Controller: `StudentAuthController@register`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['required', 'string', 'max:255']` |
| `phone` | `['required', 'string', 'max:30', 'unique:users,phone']` |
| `email` | `['nullable', 'email', 'max:255', 'unique:users,email']` |
| `password` | `['required', 'string', 'min:8', 'confirmed']` |
| `region_id` | `['required', 'integer', 'exists:regions,id']` |
| `grade` | `['required', 'string', 'max:100']` |
| `school_id` | `[ 'required', 'integer', Rule::exists('schools', 'id')->where( fn ($query) => $query->where('region_id', $request->region_id) ), ]` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: 201 استجابة تسجيل دخول الطالب: status,message,token_type,access_token,access_token_expires_at,refresh_token,refresh_token_expires_at,user,student,device. البريد غير ظاهر في user.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:31`.

## 156. `POST /api/v1/student/login`

Controller: `StudentAuthController@studentLogin`.

الصلاحيات/Middleware: `api, throttle:10,1`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `phone` | `['required', 'string', 'max:30']` |
| `password` | `['required', 'string']` |
| `device_uuid` | `['required','string','max:255']` |
| `platform` | `['required','in:android,ios']` |

الاستجابة والسلوك: نفس بيانات جلسة الطالب والتوكنات وuser/student/device. لا تتجاوز بيانات الجهاز.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:78`.

## 157. `GET /api/v1/student/profile`

Controller: `StudentAuthController@studentMe`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: status,user,student على المستوى الأعلى، دون data wrapper؛ لا email داخل user.

المصدر: `app/Http/Controllers/Api/V1/StudentAuthController.php:350`.

## 158. `PUT /api/v1/student/profile`

Controller: `StudentProfileController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `profile_image` | `['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` |
| `phone` | `['prohibited']` |
| `email` | `['prohibited']` |
| `password` | `['prohibited']` |
| `region_id` | `['prohibited']` |
| `school_id` | `['prohibited']` |
| `grade` | `['prohibited']` |

الاستجابة والسلوك: استجابة studentMe نفسها: status,user,student. لا يقبل الهاتف والبريد والصف والمدرسة والمنطقة أو كلمة المرور.

المصدر: `app/Http/Controllers/Api/V1/StudentProfileController.php:11`.

## 159. `PATCH /api/v1/student/profile`

Controller: `StudentProfileController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `profile_image` | `['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` |
| `phone` | `['prohibited']` |
| `email` | `['prohibited']` |
| `password` | `['prohibited']` |
| `region_id` | `['prohibited']` |
| `school_id` | `['prohibited']` |
| `grade` | `['prohibited']` |

الاستجابة والسلوك: استجابة studentMe نفسها: status,user,student. لا يقبل الهاتف والبريد والصف والمدرسة والمنطقة أو كلمة المرور.

المصدر: `app/Http/Controllers/Api/V1/StudentProfileController.php:11`.

## 160. `POST /api/v1/student/profile`

Controller: `StudentProfileController@update`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `name` | `['sometimes', 'required', 'string', 'max:255']` |
| `profile_image` | `['sometimes', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']` |
| `phone` | `['prohibited']` |
| `email` | `['prohibited']` |
| `password` | `['prohibited']` |
| `region_id` | `['prohibited']` |
| `school_id` | `['prohibited']` |
| `grade` | `['prohibited']` |

الاستجابة والسلوك: استجابة studentMe نفسها: status,user,student. لا يقبل الهاتف والبريد والصف والمدرسة والمنطقة أو كلمة المرور.

المصدر: `app/Http/Controllers/Api/V1/StudentProfileController.php:11`.

## 161. `GET /api/v1/student/teachers`

Controller: `TeacherDirectoryController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `mode` | `['sometimes', 'in:online,physical']` |
| `search` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `specialization` | `['sometimes', 'nullable', 'string', 'max:100']` |
| `region_id` | `['sometimes', 'integer', 'min:1']` |
| `subject_id` | `['sometimes', 'integer', 'min:1']` |
| `grade` | `['prohibited']` |
| `page` | `['sometimes', 'integer', 'min:1']` |
| `per_page` | `['sometimes', 'integer', 'between:1,100']` |

الاستجابة والسلوك: data:[{id,name,specialization,avatar_url,modes:[online,physical],status,region_id}] وmeta. mode سياق عرض، وليس مفتاح تفعيل قدرة الأستاذ.

المصدر: `app/Http/Controllers/Api/V1/TeacherDirectoryController.php:10`.

## 162. `GET /api/v1/student/teachers/{teacher}/physical/groups`

Controller: `PhysicalTeachingController@groups`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data قائمة بالمجموعات ونطاقها وأسعار الحصة والجدول والأسماء؛ meta للصفحات.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:26`.

## 163. `POST /api/v1/student/groups/{group}/join`

Controller: `PhysicalTeachingController@join`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `group`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {membership: GroupStudent, payment_method: cash_to_teacher, requires_teacher_approval: false}. 201 للانضمام و200 لإعادة الطلب نفسه. 409 GROUP_FULL عند اكتمال السعة.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:49`.

## 164. `POST /api/v1/student/groups/{group}/join-request`

Controller: `PhysicalTeachingController@join`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `group`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {membership: GroupStudent, payment_method: cash_to_teacher, requires_teacher_approval: false}. 201 للانضمام و200 لإعادة الطلب نفسه. 409 GROUP_FULL عند اكتمال السعة.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:49`.

## 165. `POST /api/v1/student/groups/{group}/withdraw`

Controller: `PhysicalTeachingController@withdraw`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `group`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {membership_status:left,refund_eligible:false,payment_method:cash_to_teacher}. 409 بعد بدء المجموعة.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:77`.

## 166. `GET /api/v1/student/teachers/{teacher}/physical/individual-offers`

Controller: `PhysicalTeachingController@offers`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data قائمة IndividualOffer مع content.type/id/title وpayment_method=cash_to_teacher؛ meta للصفحات.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:90`.

## 167. `GET /api/v1/student/teachers/{teacher}/availability`

Controller: `PhysicalAvailabilityController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `offer_id` | `['required', 'integer', 'exists:individual_offers,id']` |
| `date` | `['required', 'date_format:Y-m-d', 'after_or_equal:today']` |

الاستجابة والسلوك: data: {date,offer_id,slots:[{availability_id,starts_at,ends_at,timezone,status:available|booked|unavailable}]}.

المصدر: `app/Http/Controllers/Api/V1/PhysicalAvailabilityController.php:15`.

## 168. `POST /api/v1/student/individual-bookings`

Controller: `PhysicalTeachingController@book`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `offer_id` | `['required', 'integer', 'exists:individual_offers,id']` |
| `availability_id` | `['required', 'integer', 'exists:teacher_availabilities,id']` |
| `starts_at` | `['required', 'date', 'after:now']` |
| `ends_at` | `['required', 'date', 'after:starts_at']` |

الاستجابة والسلوك: data: {session: Session, individual: Individual, payment_method: cash_to_teacher, payment_required_on_platform: false}. لا Payment ولا Enrollment جديد.

المصدر: `app/Http/Controllers/Api/V1/PhysicalTeachingController.php:99`.

## 169. `GET /api/v1/student/teachers/{teacher}/online-content`

Controller: `OnlineLearningController@tree`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacher`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data.teacher وdata.subjects[].units[].lessons[].contents[]؛ أسعار وعدادات وhas_access، دون ملفات مدفوعة مكشوفة.

المصدر: `app/Http/Controllers/Api/V1/OnlineLearningController.php:25`.

## 170. `GET /api/v1/student/lessons/{lesson}/content`

Controller: `OnlineLearningController@content`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `lesson`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {lesson,access:{allowed,reason},contents:[{id,type,title,description,has_access,protected,assets_endpoint,playback_token_endpoint,quizzes}]}. الاختبارات البسيطة تظهر دون شراء.

المصدر: `app/Http/Controllers/Api/V1/OnlineLearningController.php:36`.

## 171. `POST /api/v1/student/lessons/{lesson}/complete`

Controller: `OnlineLearningController@complete`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `lesson`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {lesson_id,completed:true,completed_at}. 422 إن لم تُكمل كل المحتويات التعليمية المنشورة غير الاختبارات، و404 عند غياب حق الوصول.

المصدر: `app/Http/Controllers/Api/V1/OnlineLearningController.php:52`.

## 172. `GET /api/v1/student/teacher-subjects/{teacherSubject}/learning-summary`

Controller: `OnlineLearningController@progress`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `teacherSubject`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: {teacher_subject_id,total_lessons,completed_lessons,total_quizzes,completed_quizzes,lesson_weight:1,quiz_weight:0.30,earned_progress_points,total_progress_points,progress_percentage}.

المصدر: `app/Http/Controllers/Api/V1/OnlineLearningController.php:69`.

## 173. `GET /api/v1/student/refunds/eligibility`

Controller: `RefundController@eligibility`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payment_id` | `['sometimes', 'integer', 'min:1']` |

الاستجابة والسلوك: data: {payment_id,currency,eligible,refundable_amount,consumed_amount,refunded_amount,reserved_amount,lesson_allocations,code,message} عند payment_id؛ وإلا قائمة paginated.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:23`.

## 174. `GET /api/v1/student/refunds`

Controller: `RefundController@index`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول Query — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `status` | `['sometimes', 'in:PENDING_REVIEW,APPROVED,REJECTED,COMPLETED']` |

الاستجابة والسلوك: data قائمة RefundRequest DTO؛ meta للصفحات.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:65`.

## 175. `POST /api/v1/student/refunds`

Controller: `RefundController@store`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

حقول الطلب — multipart/form-data:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `payment_id` | `['required', 'integer']` |
| `account_id` | `['required', 'string', 'max:255', 'regex:/\S/u']` |
| `reason_code` | `['sometimes', 'in:exceptional,service_unavailable,teacher_suspended']` |
| `reason` | `['sometimes', 'string', 'max:2000']` |
| `original_transfer_date` | `['nullable', 'date', 'before_or_equal:now']` |
| `original_transfer_number` | `['nullable', 'string', 'max:255']` |
| `original_proof` | `['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120']` |

الاستجابة والسلوك: 201 data: RefundRequest DTO مع refund_status وproof URLs المصرح بها. المبلغ من الباك ولا يقبل قيمة من الطالب.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:36`.

## 176. `GET /api/v1/student/refunds/{refund}`

Controller: `RefundController@show`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `refund`.

لا توجد حقول body متحقَّق منها مباشرة في هذه العملية. استخدم Path parameters، وأضف page/per_page للقوائم إن كانت مدعومة. بعض العمليات تفوّض التحقق لخدمة؛ شروط التدفق موضحة بالعقد النهائي.

الاستجابة والسلوك: data: RefundRequest مع refund_status وrefund_proof_url وoriginal_proof_url؛ حقول مسار الملفات الداخلية مخفية.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:76`.

## 177. `PATCH /api/v1/student/refunds/{refund}/payout-details`

Controller: `RefundController@payout`.

الصلاحيات/Middleware: `api, auth:sanctum, role:student, student.device`.

Path parameters: `refund`.

حقول الطلب — JSON أو Query بحسب الطريقة:

| الحقل | قواعد التحقق الحالية |
|---|---|
| `account_id` | `['required', 'string', 'max:255', 'regex:/\S/u']` |

الاستجابة والسلوك: data: RefundRequest؛ يُسمح بتعديل account_id لطلبك pending/approved فقط.

المصدر: `app/Http/Controllers/Api/V1/RefundController.php:83`.
