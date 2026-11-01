# تسليم تعديلات لقاء — 2026-10-31

> تحديث 2026-11-01: تعديلات الباك الأساسية رُفعت إلى GitHub بالـcommit02625b3. أُضيف بعدها محلياً خط HLS/AES-128 الآلي دونDRM؛ تفاصيله وحقول هجرته وعقد الفرونت في [HLS_IMPLEMENTATION_AR.md](HLS_IMPLEMENTATION_AR.md). الفحص الأحدث203 ناجح و6 إخفاقات قوالب إدارة مفقودة. لم تُطبّق هجرات السيرفر.

## حالة التنفيذ

التعديلات موجودة محلياً بالكود. الهجرات جُرّبت على قواعد اختبار مؤقتة، ولم تُطبّق على قاعدة البيانات الفعلية. لم يُنفّذ نشر أو commit. ملف DESIGN.md كان معدلاً مسبقاً ولم نعدله ضمن هذا العمل.

## ماذا تغير

- تسجيل الدخول بالهاتف وكلمة المرور. إنشاء الحساب يأخذ بياناته كاملة ويصدر التوكن مباشرة؛ الطالب يحتاج بيانات الجهاز. الأستاذ الجديد يستطيع الدخول وعرض حسابه والخروج، وتُمنع إجراءات التدريس حتى توثيقه.
- الفصل بين الاشتراك الإلكتروني enrollment والتدريس الفعلي: عضوية المجموعة في group_students، والفردي في individuals، والجلسة الفعلية في sessions بعلاقة type/type_id.
- إضافة عروض individual_offers حتى يختار الطالب النطاق والسعر ثم يحجز فترة الأستاذ. الحجز الفيزيائي كاش لدى الأستاذ دون payment أو enrollment جديد.
- انضمام المجموعة مباشر دون موافقة الأستاذ؛ السعة تُفحص داخل معاملة مع قفل. الطالب يرى أسماء الأعضاء وعددهم، والأستاذ المالك يرى ملفاتهم التعليمية والتواصلية.
- نطاق التدريس subject/unit/lesson، مع سعر الحصة وعملة. لا عدد حصص إلزامياً للمجموعة. تسجيل «لوين وصلنا» في progress_notes/progress_updated_at ضمن sessions.
- شراء أونلاين على مستوى المادة أو الوحدة أو الدرس أو المحتوى. إضافة سعر الدرس، وتثبيت توزيع السعر الفعلي عند الشراء كي لا تؤثر الأسعار المستقبلية على الاسترداد.
- الاختبار البسيط مجاني ومحاولاته محدودة، تُحفظ نتيجته ويؤثر بوزن 0.30 مقابل 1 للدرس المكتمل. الأسئلة الموضوعية تصحح آلياً والمقالية يراجعها الأستاذ.
- فتح المحتوى لا يستهلك قيمته؛ إكمال محتويات الدرس التعليمية يثبت اكتماله. الاسترداد يحسب غير المكتمل من التوزيع المثبت، ويجمّد الوصول أثناء المراجعة.
- الاسترداد يحتاج موافقة ثم إثبات تحويل صادر وتاريخ التحويل، ورقم العملية اختياري. الاسترداد الجزئي محفوظ بعناصر مستقلة ويمنع إعادة دفع المبلغ نفسه.
- تعليق الأستاذ يبدأ مراجعة استرداد المبالغ المؤهلة ويخطر الطرفين. الإشعارات داخل التطبيق؛ لم يُضف إرسال Push خارجي.

## الهجرات بالتفصيل

### 000001_use_phone_for_authentication

تجهيز الهاتف للمصادقة والتمييز الفريد، وجعل البريد اختيارياً. راجع PHONE_AUTH.md وملف الهجرة لشروط التعامل مع السجلات القائمة.

### 000002_separate_teaching_sessions_from_enrollments

- إنشاء individuals وgroup_students ونقل العضويات والعلاقات القديمة بعد فحص صلاحية البيانات.
- sessions: إزالة enrollment_id وgroup_id، واستبدالهما بـtype وtype_id بقيم group/individual؛ إضافة ملاحظات التقدم ووقت تحديثها.
- attendance: ربط الحضور بالطالب عوض الاشتراك.
- حذف جدول group_enrollments بعد النقل؛ حذف موديله وController وPolicy الخاصين به، واستبدال مساراته بعقود العضوية الجديدة.
- إن تعذر تحديد مادة اشتراك قديم بشكل وحيد، تتوقف الهجرة بدلاً من التخمين. يوجد إعداد teaching.legacy_enrollment_subjects لمعالجة الربط صراحة.

### 000003_complete_online_and_cash_teaching

- lessons.price؛ quizzes.quiz_type/progress_weight.
- groups وindividuals: teaching_scope_type/teaching_scope_id/session_price/currency، مع تعبئة نطاق المادة للسجلات السابقة؛ groups.starts_at/ends_at اختياريان.
- إنشاء individual_offers وربطه اختيارياً بالفردي عبر individual_offer_id.
- enrollments.access_frozen_at؛ إنشاء lesson_completions.
- إنشاء payment_lesson_allocations: سعر الدرس وقت الشراء، المبلغ المخصص له، اسم الدرس ومعرفات المحتويات حينها. payment_checkouts.pricing_snapshot يحفظ الحساب قبل الدفع.
- refund_requests: سبب رفض منظم، إثبات وتاريخ التحويل الصادر، بيانات إثبات الدفع الأصلي الاختيارية، وعلامة طلب بيانات حساب الاسترداد.
- إنشاء refund_lesson_items لمنع تكرار الاسترداد وربطه بحصة الدرس المالية. تحرير active_payment_id للطلبات المكتملة القديمة.
- notifications.data لتفاصيل التنبيه.

الهجرتان 000002 و000003 لا تملكان rollback آلياً آمناً؛ الرجوع يتطلب استعادة نسخة احتياطية. ملفات الهجرات هي المرجع التنفيذي للقيود وأنواع الحقول.

## البيانات القديمة وما لم يُحذف

- content_assets موجود أصلاً وبقي للملفات المرتبطة بالمحتوى؛ لم ننشئ بديلاً مكرراً.
- paid_bookings والمدفوعات والسجلات المالية القديمة محفوظة للتاريخ والمراجعة. شراء موعد فيزيائي مدفوع من المنصة أُوقف؛ المسارات المتقاعدة تعيد 410 أو ترفض نوع الخدمة حسب المسار.
- المدفوعات القديمة بلا توزيع أسعار مثبت لا يُحسب استردادها بأسعار اليوم. تعيد PRICING_SNAPSHOT_REQUIRED، ويوجد مسار إداري pricing-allocation لإدخال توزيع موثق ومدقق يطابق مبلغ الدفع، مرة واحدة. يجب تسوية طلب الاسترداد القديم النشط قبل ذلك.
- حقول التفعيل القديمة المحفوظة للتوافق لا تُستخدم لفرض تفعيل منفصل لنوعي تدريس الأستاذ.
- لم يُنشأ دفتر تحصيل كاش أو أرباح جديد للفيزيائي.

## أين تعدّل الكود

القائمة الكاملة بالملفات موجودة في CHANGED_FILES_CURRENT.json، باستثناء DESIGN.md السابق. أهم أماكن التنفيذ:

| المجال | الملفات تحت app/ |
|---|---|
| الحسابات | Http/Controllers/Api/V1/StudentAuthController.php، TeacherAuthController.php، AuthController.php، StudentProfileController.php؛ Http/Middleware/RoleMiddleware.php |
| الجلسات والعضويات | Models/Session.php، Individual.php، GroupStudent.php؛ Controllers الخاصة بها؛ Services/SessionRules.php، SessionEligibility.php، IndividualBooking.php، GroupMembershipRules.php |
| الفيزيائي | Http/Controllers/Api/V1/PhysicalTeachingController.php، PhysicalAvailabilityController.php، IndividualOfferController.php، TeacherGroupMembersController.php؛ Services/TeachingScope.php |
| الأونلاين والتقدم | Http/Controllers/Api/V1/OnlineLearningController.php، TeachingContentController.php، StudentLearningController.php؛ Services/ContentAccess.php، OnlinePricing.php |
| الدفع والاسترداد | Http/Controllers/Api/V1/ShamCashPaymentController.php، RefundController.php، LegacyPricingAllocationController.php؛ Services/ShamCashPayments.php، OnlineRefunds.php |
| الاختبارات | Http/Controllers/Api/V1/QuizController.php، QuizAttemptController.php، AnswerController.php، StudentQuizReadController.php؛ Policies/QuizPolicy.php |
| تعليق الأستاذ | Http/Controllers/Api/V1/AdminController.php وHttp/Controllers/Admin/AccountsController.php |

التسجيل والصلاحيات والسياسات في Providers/AppServiceProvider.php وPolicies، والمسارات في routes/api.php وstudent.php وteacher.php. config/teaching.php لإعداد نقل البيانات القديمة، وbootstrap/app.php لمعالجة أخطاء API غير المتوقعة دون كشف تفاصيل داخلية.

## وثائق الفرونت

1. FRONTEND_CONTRACT_FINAL_AR.md: التدفقات المعتمدة وأمثلة الحقول والاستجابات.
2. APIS_FULL_AR.md: جميع عمليات HTTP المسجلة مع الحقول والصلاحيات ومراجع التنفيذ.
3. LIQAA_OPENAPI_2026-10-31.json وLIQAA_POSTMAN_2026-10-31.json للاستيراد؛ يجب تعبئة التوكن والمعرفات والملفات الفعلية. مخططات ردود المسارات القديمة عامة حيث تختلف DTOs.
4. DATABASE_REFERENCE_AR.md: الجداول والحقول والعلاقات من الصفر، وDATABASE_SCHEMA_CURRENT.json للقراءة البرمجية. مخطط DB لا يعني أن كل عمود متاح في API.

## تطبيق الهجرات على بيئة فعلية

خذ نسخة احتياطية قابلة للاستعادة، وجرّب نقل نسخة من البيانات أولاً، وعالج تعارضات الهاتف وربط المواد القديمة قبل التطبيق. أمر التطبيق هو php artisan migrate بعد إعداد البيئة. لا تستخدم migrate:fresh على بيانات قائمة. لم ننفذ هذه الخطوة على القاعدة الفعلية ضمن هذا التسليم.

## نتيجة التحقق النهائية

- الفحص الشامل: 193 اختباراً ناجحاً، و6 إخفاقات بسبب قوالب Blade المفقودة أصلاً للوحة الإدارة، بإجمالي 3472 assertion. لا يمكن اعتبار الفحص الشامل ناجحاً بالكامل قبل استعادة تلك الواجهات.
- الفحص المخصص للحسابات والهجرات والتدفقات الجديدة: 23 اختباراً ناجحاً، 204 assertions.
- تنسيق PHP بواسطة Pint. تحقق بنيوي من 177 عملية OpenAPI، وعدم تكرار operationId، وتطابق معاملات المسار، وعدد طلبات Postman.
- مرجع القاعدة يوثّق 60 جدولاً و463 حقلاً و51 موديل؛ الفهرس يغطي 171 مسار Laravel و177 عملية HTTP دون HEAD المكرر.
