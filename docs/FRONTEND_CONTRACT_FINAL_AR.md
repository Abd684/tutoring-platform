# تحديث الفيديو — 2026-11-01

تشغيل الفيديو أصبح HLS مع AES-128 دون DRM، ولا يُقدم الأصل للطالب. راجع [عقد الفيديو والتشغيل الجديد](HLS_IMPLEMENTATION_AR.md) قبل استعمال playback-token: معالجة بالخلفية،409 أثناء التجهيز،وBearer مطلوب لجميع القوائم والمقاطع والمفاتيح. ملفاتPDF تبقى بعقدها السابق.


# عقد الفرونت النهائي — لقاء — 2026-10-31

هذا الملف يصف التنفيذ الحالي بعد قرارات صاحب المشروع. يكمله APIS_FULL_AR.md الذي يسرد **كل** عمليات API المسجلة وحقول التحقق والصلاحيات، وملفا OpenAPI وPostman للاستيراد. قاعدة التطوير التجريبية تُنشأ بجميع Migrations؛ لم يُنفذ نشر أو ترحيل على قاعدة فعلية من هذه المحادثة.

## 1. قواعد الربط

- API base: أصل الخادم ثم `/api/v1`، ومسارات المدرس `/api/v1/teacher`. استخدم HTTPS في بيئة النشر.
- كل مسار محمي يحتاج Bearer token للدور الصحيح. الطالب مرتبط بجهازه؛ لا تتجاوز device_uuid/platform عند التسجيل أو الدخول.
- الهاتف String. student_id يشير إلى students.id وteacher_id إلى teachers.id، بينما user_id إلى users.id.
- التواريخ في الطلبات ISO 8601 مع timezone، مثل `2026-11-03T10:00:00+03:00`. لا تفترض timezone الهاتف هو نفسه timezone الأستاذ.
- الأسعار من الباك وتُعاد عادة كسلاسل عشرية. currency الحالية SYP. لا ترسل amount أو refundable_amount أو status للتأثير في الدفعة.
- الغلاف المعتاد: `{status:true,data:...,message?:...}`؛ القوائم المعتادة تضيف meta(current_page,per_page,total,last_page)، وper_page غالباً 20 وحده الأعلى100. الاستثناءات المهمة للمصادقة وقائمة الجلسات موضحة أدناه.
- لا نعتمد أمثلة الوثيقة القديمة كأسماء endpoints ملزمة: الفهرس الحالي مصدر المسارات الفعلي. أسماء الحقول التالية هي العقد الحالي.

## 2. إنشاء الحساب والدخول

### الطالب

`POST /student/register` أو المسار المتوافق `/auth/register`، من دون Bearer.

```json
{
  "name":"طالب تجريبي",
  "phone":"0991234567",
  "password":"Password123!",
  "password_confirmation":"Password123!",
  "region_id":1,
  "school_id":1,
  "grade":"12",
  "device_uuid":"frontend-device-uuid",
  "platform":"android"
}
```

البريد اختياري في تخزين الحساب وليس معرّف دخول، ولا يظهر في user الخاص بالطالب. المدرسة تتبع المنطقة. اسم الطالب والهاتف وتأكيد كلمة المرور والبيانات التعليمية كلها مطلوبة، ومعلومات الجهاز يرسلها التطبيق تلقائياً. platform=android|ios؛ بيانات manufacturer/model/os_version/app_version/public_key اختيارية كما في auth القديم.

الاستجابة201 تُدخل الطالب مباشرة وتحتوي على المستوى الأعلى: status،message،token_type،access_token،access_token_expires_at،refresh_token،refresh_token_expires_at،user،student،device. لا يوجد data wrapper هنا. فشل ربط الجهاز يلغي إنشاء الحساب والتوكنات معاً.

`POST /student/login` أو `/auth/login`: phone وpassword وdevice_uuid وplatform، وبقية بيانات الجهاز اختيارية. الاستجابة200 بنفس بنية جلسة الطالب. `POST /auth/refresh`: refresh_token وdevice_uuid. خروج الطالب `POST /auth/logout` يحافظ على ربط الجهاز ويبطل الجلسة الحالية. نقل الأجهزة ما زال متاحاً عبر مسارات auth/device/transfer الحالية.

### المدرس

`POST /teacher/register`: name،phone،password،password_confirmation،region_id، والاختياري email/bio/specialization. يعيد201 مع التوكنات وuser.teacher_status=pending_review وuser.can_teach=false.

`POST /teacher/login`: phone+password. الأستاذ غير الموثق يدخل لمتابعة هويته وحالة التوثيق والخروج، وتُرفض أعمال التدريس بـ403 مع code=TEACHER_NOT_VERIFIED. `GET /teacher/me` يعيد User مع teacher وcan_teach على المستوى الأعلى. اعتماد الإدارة عبر `PATCH /teacher/{teacher}/activate` يفعّل ملف المدرس.

كل أستاذ يدعم online وphysical؛ لا يوجد teacher_modes أو خيار تفعيل مستقل. حقل modes في دليل الأساتذة يعيد النوعين. group_enabled القديم محفوظ للتوافق لكنه ليس شرط تفعيل نمط التدريس الفيزيائي.

## 3. البروفايل والصورة

`GET /student/profile`: الاستجابة status,user,student. لتعديل الاسم فقط `PATCH /student/profile` أو `/profile`.

لتعديل الاسم والصورة معاً: `POST /student/profile` بصيغة multipart، الحقول name وprofile_image. الصورة JPG/JPEG/PNG/WEBP، أقصى5MB وأبعاد4096×4096. API رفع الصورة القديم `/profile/avatar` يستخدم avatar؛ DELETE لنفس المسار يحذف الصورة.

الهاتف والبريد وكلمة المرور والمنطقة والمدرسة والصف ممنوعة في تعديل بروفايل الطالب. معرّف الصورة العام avatar_url؛ لا تطلب avatar_path.

## 4. المواد والأسعار ونطاق الفيزيائي

الأستاذ يحدد سعر المادة teacher_subject.price، والوحدة unit.price، والدرس lesson.price، ويمكن وجود محتوى منفرد content.price. subject في نطاق الفيزيائي يعني **teacher_subjects.id** لا subjects.id العام.

إدارة الأسعار:

| العملية | المسار | أهم الحقول |
|---|---|---|
| POST | /teacher/subjects | subject_id،price |
| PATCH | /teacher/subjects/{teacherSubject} | price أو بيانات المادة المسموحة |
| POST | /teacher/units | teacher_subject_id،title،price?،status?،order_no? |
| PATCH | /teacher/units/{unit} | title/price/status/order_no |
| POST | /teacher/lessons | unit_id،title،price،status?،order_no? |
| PATCH | /teacher/lessons/{lesson} | title/price/status/order_no |
| POST | /teacher/contents | lesson_id،type،title وباقي حقول المحتوى |
| PATCH | /teacher/contents/{content} | حقول المحتوى المسموحة ومنها price |

الموارد تخص الأستاذ صاحب التوكن فقط. النشر draft|published|archived حسب المورد. حقل teaching_scope_type=subject|unit|lesson وteaching_scope_id يحددان نطاق المجموعة/العرض، ويجب أن يتبعا teacher_subject_id نفسه. هذا الربط مختلف عن Session.type/type_id.

## 5. المجموعات الفيزيائية النقدية

إنشاء الأستاذ: `POST /teacher/groups`.

```json
{
  "teacher_subject_id":7,
  "name":"مجموعة الرياضيات",
  "capacity":12,
  "session_price":50000,
  "teaching_scope_type":"unit",
  "teaching_scope_id":11,
  "status":"forming",
  "starts_at":null,
  "ends_at":null
}
```

السعر لكل حصة، وليس سعراً لحزمة ذات عدد حصص محدد. currency=SYP. تاريخا البداية والنهاية اختياريان. السعر والنطاق لا يتغيران بعد وجود عضوية أو جلسات؛ تغييرهما يفسد سجل المجموعة. جدول الحصص في sessions ويمكن للأستاذ إضافة حصص لاحقاً حسب الشرح.

استكشاف الطالب: `GET /student/teachers/{teacher}/physical/groups`، قائمة paginated تتضمن id/name وcontent.type/id/title،members_count،max_members،session_price،currency،starts_at،ends_at،membership_status،members،sessions،payment_method=cash_to_teacher وpayment_required_on_platform=false.

الانضمام: `POST /student/groups/{group}/join` دون body. alias `/join-request` ينفذ الانضمام المباشر نفسه، ولا ينشئ مرحلة موافقة.

```json
{
  "status":true,
  "message":"تم تثبيت مقعدك بالمجموعة.",
  "data":{
    "membership":{"id":1,"group_id":3,"student_id":12,"status":"active","joined_at":"...","left_at":null},
    "payment_method":"cash_to_teacher",
    "requires_teacher_approval":false
  }
}
```

201 عند الإنشاء،200 عند إعادة الطلب لنفس العضوية الفعالة،409 مع GROUP_FULL عند امتلاء السعة. الباك يسلسل الطلبات بقفل المجموعة والأستاذ؛ لا يقبل أكثر من السعة. الرسالة: «نعتذر، اكتمل العدد المتاح لأن المقعد حُجز لطلب سابق. يمكنك اختيار مجموعة أو موعد آخر.» لا دفع شام كاش ولا Enrollment إلكتروني ينشآن للعضوية.

الأعضاء للطالب: `GET /student/groups/{group}/members` يعيد data.group_id،members_count وmembers:[{student_id,name}] فقط. للأستاذ المالك `GET /teacher/groups/{group}/students` يعيد عضويات paginated مع بيانات الطالب والاتصال والمدرسة اللازمة للتدريس؛ لا passwords ولا tokens.

`POST /student/groups/{group}/withdraw` مسموح قبل بداية المجموعة/أول جلسة غير ملغاة فقط، ويعيد membership_status=left وrefund_eligible=false. بعد البداية409 ويُوجّه الطالب للتواصل مع الأستاذ. القائمة الخاصة بالطالب ما زالت `/student/groups?scope=active|history|all`.

## 6. العروض والمواعيد الفردية النقدية

الأستاذ: `GET/POST /teacher/individual-offers` و`PATCH /teacher/individual-offers/{individualOffer}`.

```json
{
  "teacher_subject_id":7,
  "teaching_scope_type":"lesson",
  "teaching_scope_id":111,
  "title":"شرح الدرس",
  "session_price":25000
}
```

العرض عام لا يحتوي student_id. تعديله يسمح بالعنوان والحالة active|inactive، والسعر قبل بدء الحجز فقط. لا يُحدد عدد حصص شرح الوحدة مسبقاً.

الطالب: `GET /student/teachers/{teacher}/physical/individual-offers`. بعدها `GET /student/teachers/{teacher}/availability?offer_id=101&date=2026-11-03`.

```json
{
  "status":true,
  "data":{
    "date":"2026-11-03","offer_id":101,
    "slots":[{"availability_id":8,"starts_at":"2026-11-03T10:00:00+03:00","ends_at":"2026-11-03T11:00:00+03:00","timezone":"Asia/Damascus","status":"available"}]
  }
}
```

status للفتحة available|booked|unavailable. لا توجد slot rows جديدة أو slot_id وهمي؛ نرسل availability_id مع بداية ونهاية الموعد كما أعادهما الباك.

الحجز `POST /student/individual-bookings`:

```json
{"offer_id":101,"availability_id":8,"starts_at":"2026-11-03T10:00:00+03:00","ends_at":"2026-11-03T11:00:00+03:00"}
```

يجب اختيار الموعد الكامل المعروض. يعيد201 data:{session,individual,payment_method:cash_to_teacher,payment_required_on_platform:false}. يثبت الحجز فوراً، ولا يتطلب انتظار الأستاذ أو الدعم. يمنع تداخل موعد الطالب أو الأستاذ وتُلغى كل الكتابة إذا خسر طلب الحجز التنافس.

Individual هو ارتباط طالب بتدريس، وقد يتبعه أكثر من موعد. كل طلب حجز عرض عام ينشئ سجل الفردي الخاص به وجلسة له. يمكن إدارة الفردي الموجود عبر `/teacher/individuals` وإضافة حصص تالية له، دون فرض عدد حصص مسبق.

المساران الأقدمان لحجز Individual موجود: `/teacher-availabilities/{availability}/book` و`/teachers/{teacher}/bookings`، ويحتاجان individual_id مُنشأً مسبقاً. لا يحتاج الطالب ذلك عند استخدام حجز العرض الجديد.

## 7. Session والتقدم والحضور

`POST /teacher/sessions`: type=group|individual،type_id،starts_at،ends_at،availability_id?،capacity?،progress_notes?. teacher_id من التوكن. group_id وenrollment_id وindividual_id ممنوعة داخل هذا الطلب. الفردي سعته1.

`GET /teacher/sessions` قائمة. `PATCH /teacher/sessions/{session}` لتعديل الجدولة؛ تغيير type يتطلب type_id، وتغيير المشاركين ممنوع بعد تسجيل حضور. `/complete` و`/cancel` عمليتا POST منفصلتان.

`PATCH /teacher/sessions/{session}/progress`:

```json
{"progress_notes":"وصلنا للصفحة ٣٢ وحلّينا التمرين الرابع"}
```

الحد10000حرف، null يمسحها، وprogress_updated_at من الخادم. يعمل على completed، ولا يعمل على cancelled. لا teaching_progress ولا lesson_id/content_id لملاحظة الحصة.

`POST /teacher/sessions/{id}/attendance`:

```json
{"records":[{"student_id":12,"status":"present","note":null}]}
```

status=present|absent|late|excused، وأوقات check_in_at/check_out_at اختيارية. لا تكرار student_id داخل الطلب. إعادة الطلب تعدّل سجل الحضور نفسه. الحضور لمن ينتمي إلى هذه الجلسة فقط، ولا enrollment_id.

الطالب: `GET /student/bookings?scope=upcoming|history|all` يعيد `{data:[...]}` دون status/meta في المسار القديم. عنصر القائمة: id,type,type_id,starts_at,ends_at,status,progress_notes,progress_updated_at,teacher:{id,name},teacher_subject_id,subject_name. ليس فيه enrollment object. `/student/sessions/{session}` يعيد نفس العنصر داخل success wrapper. إلغاء موعد فردي مستقبلي `/sessions/{id}/booking` بطريقة DELETE.

## 8. المحتوى الإلكتروني والشراء

`GET /student/teachers/{teacher}/online-content`: teacher ثم subjects[].units[].lessons[].contents[] مع الأسعار والعدادات وhas_access، دون روابط ملفات مدفوعة.

`GET /student/lessons/{lesson}/content`: lesson وaccess.allowed/reason وcontents. المحتوى المدفوع يعرض assets_endpoint/playback_token_endpoint عندما يوجد حق وصول فقط. الاختبار البسيط يظهر دون شراء. روابط الفيديو/PDF تصدر من مسارات access-token الحالية، ولا تُركّب من storage_key.

شراء teacher_subject يفتح كامل المحتوى التابع،unit يفتح ما تحتها،lesson يفتح محتوياته،content عنصر منفرد. لا تخلط subjects.id العام مع teacher_subjects.id المعروض عند الشراء.

الخطوة1 `POST /payments/checkout`:

```json
{"service_type":"lesson","service_id":111}
```

service_type=teacher_subject|unit|lesson|content فقط. يعيد checkout_id/internal_reference/amount/currency/service/account_id/qr_url/expires_at. checkout صالح30دقيقة. يُحفظ توزيع السعر على الدروس هنا.

الخطوة2 `POST /student/payments` بصيغة multipart:

- checkout_id: UUID من الخطوة1.
- provider_reference: رقم الحوالة الأصلي، فريد.
- proof: PDF/JPG/JPEG/PNG، أقصى5MB.

السعر من الباك. الطلبات المكررة لنفس الخدمة المعلقة أو المملوكة تُرفض؛ إعادة نفس checkout/رقم التحويل تعيد الدفعة نفسها. تتطلب الخدمة ومحتوياتها النشر وأستاذاً فعالاً. إذا تغير السعر الإجمالي قبل تقديم الإثبات يُطلب checkout جديد.

إنشاء Enrollment بحالة pending يسبق تثبيت الدفع، ولا يفتح المحتوى. `POST /admin/payments/{payment}/review` decision=confirm|reject، مع rejection_reason إلزامي عند الرفض. الموافقة تفعّل Enrollment؛ لا يفتح الفرونت المحتوى اعتماداً على رفع الإثبات وحده.

`GET /payment-settings`،`GET /student/payments`،`GET /student/payments/{payment}` متاحة كما في الفهرس. Payment DTO يحتفظ بالحقول السابقة ويضيف financial_status وrefundable_amount وrefunded_amount. financial_status هو pending|confirmed|rejected|partially_refunded|refunded. payment_status القديم PENDING_REVIEW|APPROVED|REJECTED محفوظ للتوافق، وpayments.status الداخلي pending|succeeded|failed لا يُغيَّر لإخفاء الدفعة الأصلية عند الاسترداد.

## 9. الإكمال والاختبارات والتقييم

فتح الدرس أو تحميل صفحته لا يعني استهلاكه. `POST /student/contents/{content}/complete` هو إعلان إكمال المحتوى المصرّح به؛ عند إكمال كل محتويات الدرس التعليمية المنشورة غير الاختبارات، يسجل الباك LessonCompletion. يمكن أيضاً استدعاء `POST /student/lessons/{lesson}/complete` بعد إكمال جميع تلك المحتويات؛ يرفض الطلب المبكر422.

تعريف الإكمال هنا مبني على تسجيل إكمال المحتوى عبرAPI، وليس قياس مشاهدة الفيديو من مزود بث خارجي. لا تُرسل complete لمجرد فتح الصفحة. الدرس بلا محتوى تعليمي منشور لا يقبل إكمالاً وهمياً.

الأستاذ ينشئ/يضبط الاختبار عبر `PUT /teacher/contents/{content}/quiz`: quiz_type=simple|protected،attempts_allowed،duration_minutes?،status،questions[]. كل سؤال type=mcq|true_false|essay وbody وpoints وrubric_json. الخيارات في rubric_json.options والإجابة المرجعية correct_answer لا تظهر للطالب. لا يمكن تغيير اختبار بعد بدء محاولاته.

الاختبار simple مجاني وله محاولات محددة ويحفظ النتائج. protected يتبع صلاحية الوصول للمحتوى. لا ندّعي ضمان منع تصوير الشاشة من علم protected وحده.

- `GET /quizzes/{quiz}/data`: بيانات وأسئلة بدون مفاتيح التصحيح،protected وquiz_type وprogress_weight والمحاولات.
- `POST /quizzes/{quiz}/attempts`: بدء محاولة، يعيد attempt_id وattempts_remaining.
- `POST /quiz-attempts/{quizAttempt}/answers`: question_id وanswer_text، دون score.
- `POST /quiz-attempts/{quizAttempt}/submit`: يسجل التسليم؛ status=graded عند اكتمال التصحيح، أو submitted بانتظار المراجعة.
- `POST /teacher/answers/{answer}/grade`: score يحدده الأستاذ المالك، بحد أقصى points للسؤال، للمحاولات المسلمة. يحدث مجموع المحاولة.
- `/student/my-quizzes` و`/{quiz}/attempts` لقوائم الطالب وتاريخه.

`GET /student/teacher-subjects/{teacherSubject}/learning-summary`: total_lessons،completed_lessons،total_quizzes،completed_quizzes،lesson_weight=1،quiz_weight=0.30،earned_progress_points،total_progress_points،progress_percentage. الدرس المكتمل يمنح1، والاختبار يمنح0.30×نسبة أفضل علامة مصححة، ولا يُكرر وزن الاختبار بسبب تكرار المحاولات. لا نجوم أو تقييمات وهمية للأستاذ؛ صفحة الأستاذ تعرض عدادات الإكمال والمحاولات المصححة.

## 10. الاسترداد النسبي

التوزيع ثابت وقت checkout ويُنسخ إلى payment_lesson_allocations عند تقديم الدفع. مثال: أسعار الدروس60 و140، وسعر الحزمة100 → القيم المخصصة30 و70. تغييرات الأسعار لاحقاً لا تغير اللقطة. فروق التقريب تُوزع بحيث يساوي المجموع المبلغ المدفوع بالسنت/جزء العملة.

إذا كانت الحزمة متعددة الدروس وتوجد أسعار دروس غير محددة يُرفض طرحها للدفع حتى تضبط. للحزمة ذات درس واحد يمكن تخصيص المبلغ كله للدرس دون افتراض خصم موزع. الاختبارات المجانية لا تزيد قيمة الاستهلاك المالي.

`GET /student/refunds/eligibility?payment_id=1001`:

```json
{
  "status":true,
  "data":{
    "payment_id":1001,"currency":"SYP","eligible":true,
    "refundable_amount":"70.00","consumed_amount":"30.00",
    "refunded_amount":"0.00","reserved_amount":"0.00",
    "lesson_allocations":[{"allocation_id":1,"lesson_id":111,"lesson_title":"درس","allocated_amount":"30.00","consumed":true,"refundable_amount":"0.00"}],
    "code":"REFUND_AVAILABLE","message":"يوجد مبلغ قابل للاسترداد."
  }
}
```

دون payment_id يعيد قائمة paginated لدفعات الطالب المؤكدة. eligibility ليست تحويل أموال.

إنشاء الطلب `POST /student/refunds`:

```json
{"payment_id":1001,"account_id":"STUDENT-SHAM-CASH","reason":"استرداد المحتوى غير المستهلك","reason_code":"exceptional"}
```

reason_code الاختياري exceptional|service_unavailable|teacher_suspended. يمكن إرفاق original_transfer_date وoriginal_transfer_number وoriginal_proof اختياري بصيغة multipart؛ الإثبات الأصلي موجود أصلاً في Payment. لا يقبل الطالب amount أوstatus لتحديد الاسترداد.

يحجز الباك المبلغ ويجمّد وصول الاشتراك المرتبط مؤقتاً أثناء المراجعة. لا يسمح بطلبين فعالين لنفس الدفعة. الإكمال والحجز المالي يتشاركان ترتيب أقفال الأستاذ والطالب لمنع السباق. الرفض يعيد الوصول؛ المبالغ المعادة سابقاً لا تُدفع مرتين.

الإدارة `POST /admin/refunds/{refund}/review`: decision=approve|reject،review_note إلزامي؛ عند reject يجب rejection_code=rejected_no_balance|rejected_invalid_data|rejected. الموافقة لا تعني أن التحويل تم.

بعد تحويل الإدارة فعلياً: `POST /admin/refunds/{refund}/complete` multipart:

- refund_proof: **إلزامي**، PDF/JPG/JPEG/PNG حتى5MB.
- refund_transferred_at: **إلزامي**، تاريخ التحويل الفعلي، لا يتجاوز الآن.
- transfer_reference: **اختياري**، وفريد إن أُدخل، ولا يساوي الحوالة الأصلية.

لا يُرسل مبلغ من الفرونت؛ المبلغ هو المبلغ المعتمد. يُراجع الباك الرصيد مرة ثانية، ويحفظ الإثبات والتدقيق، وتصبح refund_status=refund_sent. الفشل يلغي تعديل DB ويحذف الملف الجديد. بعد استرداد جزئي تُحجب دروس اللقطة المعادة، وتبقى الدروس التي خُصمت قيمتها متاحة من الحق المتبقي، ما لم يكن الأستاذ/الحساب مجمداً.

حالات DTO: pending_review،approved،refund_sent،rejected_no_balance،rejected_invalid_data أوrejected. DB.status يبقى PENDING_REVIEW/APPROVED/REJECTED/COMPLETED، ولا تحوّل casing بالتخمين.

التاريخ: `GET /student/refunds` و`GET /student/refunds/{refund}`. تظهر amount/currency/review_note/rejection_code/refund_transferred_at/transfer_reference/refund_proof_url/refunded_amount. روابط الإثبات خاصة لصاحب الطلب والإدارة.

تجميد الأستاذ عبر مسار الإدارة يبدأ طلب مراجعة استرداد آلياً لكل رصيد أونلاين مؤهل، دون إعلان تحويل غير منفذ. إذا لا يوجد حساب شام كاش محفوظ، payout_details_required=true ويكمله الطالب عبر `PATCH /student/refunds/{refund}/payout-details` مع account_id. يبلغ الطالب والأستاذ، ويحتاج الإرسال الحقيقي مراجعة وإثبات الإدارة.

**الدفعات القديمة:** لا نختلق أسعاراً تاريخية غير محفوظة. eligibility يعيد PRICING_SNAPSHOT_REQUIRED. تُسجل الإدارة توزيعاً مؤيداً بالفاتورة عبر `POST /admin/payments/{payment}/pricing-allocation` مع review_note وlessons[{lesson_id,lesson_price_snapshot,allocated_amount,content_ids_snapshot}]. يجب أن يساوي المجموع الدفعة وأن تتبع الدروس الخدمة، ولا يمكن استبدال لقطة مثبتة. إذا يوجد طلب قديم pending/approved، يُرفض/يُسوّى أولاً قبل إعادة المراجعة. الحجز الفيزيائي المدفوع التاريخي يبقى للدعم ولا يتحول تلقائياً إلى شراء درس إلكتروني.

## 11. الإشعارات والأخطاء

`GET /student/notifications` أو `/teacher/notifications`: قائمة paginated بعناصر id/type/title/body/data/read/read_at/created_at/related_payment_id. `POST .../notifications/{notification}/read` يعلّم إشعار المستخدم نفسه فقط.

الأحداث تشمل الانضمام والحجز الفيزيائي، قرار الدفع، طلب/قرار/إتمام الاسترداد، تجميد الأستاذ، ودفعة قديمة تحتاج مراجعة. in-app منفذ. لا يوجد تكامل FCM مفعّل في هذا المشروع؛ لا يدعي الباك إرسال Push خارجي لم يُرسل.

الأخطاء المتوقعة: 401،403،404،409،422. حقول Laravel validation تحت errors. الأخطاء غير المتوقعة لمسارات API تعيد INTERNAL_ERROR ورسالة آمنة، دون SQL أوstacktrace أومسارات خادم.

## 12. ما أُلغي وما بقي للتاريخ

- لا موافقة أستاذ أو group_join_requests؛ الانضمام مباشر حسب السعة.
- لا دفع جديد للفيزيائي على المنصة. service_type=lesson_booking لم يعد مقبولاً في checkout.
- `/teachers/{teacher}/paid-slots` يعيد410 PHYSICAL_CASH_ONLY، وإعادة جدولة paid-bookings القديمة تعيد410 LEGACY_BOOKING_REQUIRES_SUPPORT.
- لم تُحذف جداول paid_bookings أودفعاتها أو ملفاتها. قوائمها القديمة محفوظة للتاريخ والدعم، ولا تستخدمها لحجز فيزيائي جديد.
- لا cash ledger أو عمولات نقدية مفترضة بهذا التعديل.
- group_enrollments حذف في Migration السابقة واستُبدل بـgroup_students. Session لا يرتبط بـEnrollment، وAttendance يحمل student_id.
- ContentAsset وVideoAsset وPdfAsset بقيت كما هي؛ لا جداول مكررة لملفات الدرس.

للتفاصيل الدقيقة لكل العمليات القديمة والجديدة، طرقHTTP وحقول التحقق وmiddleware، راجع APIS_FULL_AR.md وملف OpenAPI. الفهرس يشمل مسارات التوافق والملفات والإدارة ولا يقتصر على الأمثلة أعلاه.
