# لقاء — عقد ربط واجهات المدرس مع Flutter

تاريخ التسليم: 17 أكتوبر 2026. التعديلات مطبقة على النسخة المحلية والسيرفر. يغطي البنود 1–6 و 8–11 من الطلب؛ **تغيير/إعادة تعيين كلمة المرور (7) والإشعارات (12) مستثنيان ولم تتم إضافة مسارات لهما**. تأكيد كلمة المرور المذكور ضمن تسجيل الحساب أدناه هو الحقل الموجود أصلاً للتسجيل، وليس إضافة تدفق البند 7.

**مرجع التوافق:** ملف `FRONTEND_API_CONTRACT(4).md` لم يكن متوفراً، ولم نتمكن من مطابقته حرفياً. المرجع الذي فُحص وحُفظ هو المسارات والحقول الموجودة فعلياً في المشروع عند بدء العمل. لم تُحذف أو تُنقل مسارات الدخول/me/logout/plans/subscribe/profile، ولم يُستبدل شكل ردها القديم بغلاف جديد. المسارات الجديدة موضحة صراحة في هذا الملف؛ على Flutter اعتمادها بهذه الأسماء والحقول، وأي تغيير لاحق يحتاج تنسيقاً بين الفريقين.

## 1. العنوان والترويسات وأشكال الرد

```text
BASE_URL=https://mandobe.net/liqaa/api/v1
```

المشروع المنشور `/home/mand/liqaa_app`، والمحلي `C:\xampp\htdocs\tutoring-platform`. لا تحولوا الربط إلى تطبيق آخر على نفس الاستضافة.

```http
Accept: application/json
Content-Type: application/json
Authorization: Bearer <access_token>
```

Authorization للمسارات المحمية فقط. أرسلوا JSON حقيقياً وأرقاماً/booleans دون تحويلها لنصوص حين يمكن ذلك. التوكن الخاص بالمدرس يأتي من teacher/login أو teacher/refresh؛ لا تستخدموا توكن الطالب.

**الأشكال الموجودة مختلفة عمداً للحفاظ على التوافق:**

- الدخول والتجديد يعيدان التوكنات في المستوى الأعلى: `access_token`, `refresh_token`, `token_type`, `expires_in`؛ ليست داخل data.
- `/teacher/me` يعيد المستخدم مباشرة في أعلى JSON، والمدرس داخل `teacher`؛ لا يوجد غلاف `data`.
- التسجيل والخروج يعيدان `message` فقط في النجاح.
- الخطط والواجهات الأخرى أدناه تستخدم `status: true`, `data`، وقد يوجد `message`.
- القوائم المرقمة تعيد `meta.current_page/per_page/total/last_page`. الافتراضي 20، والحد الأعلى 100. catalog/history يتحققان من page>=1 و per_page ضمن 1..100.
- الأخطاء قد تكون `message/errors` فقط أو `status:false/message`. تعاملوا مع HTTP أولاً، ولا تشترطوا وجود status في كل رد.
- المعرفات أعداد، لكن **user.id يختلف عن teacher.id**. مبالغ decimal مثل price/commission_rate قد تعود كنص مثل `"10.00"`؛ حولوها للعرض بحذر. التواريخ ISO 8601، و null يعني عدم وجود قيمة.

## 2. المسارات الكاملة النهائية

| البند | Method والمسار الكامل | Bearer | الحالة |
|---|---|---|---|
| 1 | POST `https://mandobe.net/liqaa/api/v1/teacher/register` | لا | موجود وتم اختباره |
| 1 | POST `https://mandobe.net/liqaa/api/v1/teacher/login` | لا | موجود وتم اختباره |
| 1 | POST `https://mandobe.net/liqaa/api/v1/teacher/refresh` | لا | موجود وتم اختباره |
| 2 | GET `https://mandobe.net/liqaa/api/v1/teacher/me` | نعم | موجود، حافظنا على شكل الرد |
| 3 | POST `https://mandobe.net/liqaa/api/v1/teacher/logout` | نعم | موجود وتم اختبار الإبطال |
| 4 | GET `https://mandobe.net/liqaa/api/v1/teacher/subscription-plans` | نعم | موجود |
| 5 | POST `https://mandobe.net/liqaa/api/v1/teacher/subscription-plans/{subscriptionPlan}/subscribe` | نعم | موجود؛ تقوية قفل الاشتراك دون تغيير العقد |
| 6 | GET `https://mandobe.net/liqaa/api/v1/teacher/subject-catalog` | نعم | جديد: مواد الدليل لاختيار subject_id |
| 6 | POST `https://mandobe.net/liqaa/api/v1/teacher/subjects` | نعم | جديد: إضافة مادة الدليل إلى عروض المدرس |
| 8 | PATCH `https://mandobe.net/liqaa/api/v1/teacher/profile` | نعم | موجود وتم توسيع الحقول |
| 9 | GET `https://mandobe.net/liqaa/api/v1/teacher/subscription` | نعم | جديد: الاشتراك الساري حالياً |
| 10 | GET `https://mandobe.net/liqaa/api/v1/teacher/subscriptions` | نعم | جديد: سجل الاشتراكات |
| 11 | GET `https://mandobe.net/liqaa/api/v1/teacher/usage` | نعم | جديد: الأعداد الحقيقية وحدود البيانات المتوفرة |

الدخول/me/logout تحتاج دور teacher؛ الإضافة/profile/plans/subscribe/current/history/usage/catalog تحتاج مستخدماً فعالاً وملف مدرس فعالاً. الحساب pending_review يستطيع الدخول وفق السلوك السابق، لكن هذه الوظائف ترفضه 403 حتى التفعيل الإداري. تفاصيل حالات المسارات القديمة أدناه هي الموجودة فعلياً؛ لم نغير سياسة تفعيل المدرس.

## 3. التسجيل والدخول وتجديد التوكن

### POST /teacher/register — دون Bearer

| الحقل | النوع | الإلزام والتحقق |
|---|---|---|
| name | string | مطلوب، حتى 255 |
| email | string | مطلوب، بريد صالح حتى 255 وفريد في users |
| phone | string | مطلوب، حتى 20 وفريد في users؛ لا تحولوه لعدد يفقد + أو الصفر الأول |
| password | string | مطلوب، 8 أحرف على الأقل |
| password_confirmation | string | مطلوب عملياً لتأكيد تطابق password، وفق confirmed الموجود أصلاً |
| region_id | id | مطلوب، موجود في regions |
| specialization | string أو null | اختياري، حتى 255 |
| bio | string أو null | اختياري، حتى 1000 عند التسجيل |

```json
{
  "name": "مدرس تجريبي",
  "email": "teacher@example.invalid",
  "phone": "+963900000000",
  "password": "<entered_password>",
  "password_confirmation": "<same_password>",
  "region_id": 1,
  "specialization": "الرياضيات",
  "bio": "نبذة المدرس"
}
```

العينة توضيح حقول وليست بيانات دخول قابلة للاستخدام. النجاح 201 برسالة التسجيل فقط، **لا Access/Refresh Token عند التسجيل**. يُنشأ users.status=active و teachers.status=pending_review. بعده استعملوا login، واعرضوا حالة انتظار التفعيل بحسب teacher_status. التفعيل ليس صلاحية المدرس.

422 عند نقص حقل، بريد/هاتف مكرر، منطقة غير موجودة أو تأكيد غير مطابق؛ 500 برسالة Registration failed عند تعذر الحفظ. إنشاء المستخدم والمدرس بمعاملة واحدة.

### POST /teacher/login — دون Bearer

Body: `email` string مطلوب صالح، `password` string مطلوب. النجاح 200 ويعيد التوكنين في المستوى الأعلى مع user المصغّر: name/email/phone/status/role/teacher_status. لا تفترضوا أن user هنا يتضمن كامل ملف المدرس؛ اجلبوا me بعده.

401 `Invalid credentials`، 403 `Your account is inactive`، أو 403 `Teacher account not found` عند الحساب غير المدرس، و 422 عند حقول غير صالحة.

### POST /teacher/refresh — دون Bearer

```json
{"refresh_token":"<current_refresh_token>"}
```

refresh_token string مطلوب. Access Token صالح 15 دقيقة (`expires_in=900` بالثواني)، و Refresh Token يصدر بصلاحية 30 يوماً. التجديد يستهلك التوكن القديم ويصدر **زوجاً جديداً**؛ احفظوا الاثنين معاً في تخزين آمن. اجعلوا التجديد single-flight في Flutter لمنع طلبين متزامنين بنفس refresh token.

النجاح 200. إعادة استعمال القديم 401 `Invalid or revoked refresh token`؛ المنتهي 401 `Refresh token has expired`؛ الحساب غير المتاح 403. التجديد لا يعدل كلمة المرور ولا ينشئ جهاز طالب، ولا يلغي تلقائياً كل Access Tokens القديمة؛ تبقى حدود صلاحيتها الحالية. لا تسجلوا أي توكن في logs.

## 4. GET /teacher/me

لا body/query. يحتاج Bearer. النجاح 200: كائن المستخدم نفسه (id/name/phone/email/role/status/region_id/avatar_url) مع teacher (id/user_id/bio/specialization/status/experience_years). الحقول الاختيارية قد تكون null؛ avatar_url null يعني صورة بديلة. password_hash و avatar_path مخفيان.

اقرأوا `response.teacher.id` لمعرف المدرس و`response.id` لمعرف المستخدم؛ لا تستخدموا `response.data.teacher`. بعد تعديل profile يعكس me البيانات المحفوظة. 401 دون توكن صالح، 403 للدور الخاطئ. ملف مدرس pending_review يظهر كما هو؛ لا تعتبروا نجاح me تفعيل حساب.

## 5. POST /teacher/logout

يحتاج Bearer مع body JSON:

```json
{"refresh_token":"<current_refresh_token>"}
```

refresh_token string مطلوب. يحاول إبطال سجل refresh المطابق **لنفس المستخدم**، ويحذف Access Token الذي أُرسل في Authorization. النجاح 200 `Logged out successfully`. لا يلغي كل الأجهزة/كل توكنات المستخدم ولا توكنات الآخرين. refresh غير مطابق لا يلغى ويظل الرد 200 وفق السلوك الموجود؛ أرسلوا التوكن الصحيح الخاص بهذا الدخول. غياب الحقل 422؛ Access Token غير صالح 401. بعد الخروج امسحوا زوج التوكنات وبيانات الملف الحساسة من تخزين الواجهة. تم اختبار رفض access و refresh الصحيحين بعد الخروج.

## 6. الخطط والاشتراك بخطة

### GET /teacher/subscription-plans

Bearer مدرس فعال. Queries: `billing_cycle` اختياري monthly أو yearly، و page/per_page للتصفح (per_page1..100). الرد 200 قوائم الخطط active فقط، بالترتيب price ثم id تصاعدياً. كل خطة تعيد id/name/price/billing_cycle/commission_rate/max_students/max_storage/max_ai_usage/max_group_size/status. لا تفترضوا وجود max_groups أو max_sessions؛ غير موجودين في المخطط الحالي.

200 وقائمة فارغة إذا لا خطط مطابقة؛ 422 billing_cycle/per_page غير صالح، 401/403 للمصادقة/الصلاحية. المعرف المطلوب للاشتراك هو **plan.id** وليس teacher.id أو subscription.id.

### POST /teacher/subscription-plans/{subscriptionPlan}/subscribe

Body: `auto_renew` boolean اختياري، الافتراضي false. يمكن إرسال `{}`. لا ترسلوا teacher_id أو plan_id أو تواريخ؛ الخطة من URL والمدرس من التوكن.

```json
{"auto_renew":true}
```

- أول اشتراك:201 و`Subscription created successfully.`، data كائن teacher_subscription مع plan.
- تغيير الخطة:200 و`Subscription upgraded successfully.`، إلغاء السابق وإنشاء سجل جديد. عبارة upgraded قديمة في العقد وتستخدم لأي تغيير خطة، وليست تأكيداً أن سعرها أعلى.
- نفس الخطة السارية:409 `You are already subscribed to this plan.`؛ **لا يحدّث auto_renew** في هذه الحالة، ولا ينشئ سجلاً مكرراً.
- خطة غير active:422 `This subscription plan is not available.`؛ معرف غير موجود 404؛ auto_renew غير boolean422؛ مدرس غير فعال 403، وقد يرفض Policy قبل الوصول لرسالة Controller.

البداية من وقت التنفيذ، والنهاية بعد شهر أو سنة بحسب billing_cycle مع معالجة نهاية الشهر/السنة. الاشتراك يعاد data.id/data.plan_id/data.teacher_id/starts_at/ends_at/auto_renew/status/plan. المبالغ والتواريخ يصدرها الخادم. أُضيف قفل صف المدرس والخطة لتسلسل إنشاء أول اشتراك وتغيير الخطة؛ لم تُجرَ اختبارات ضغط متزامن.

**auto_renew قيمة محفوظة فقط في هذا المسار؛ لم يثبت وجود محرك تجديد/تحصيل تلقائي أو دفع ضمن هذا الطلب.** نجاح الاشتراك هنا يفعّل الخطة وفق التدفق القديم ولا يثبت حصول دفعة. لا تعرضوا «تم الخصم» من هذا الرد.

## 7. إضافة مادة للمدرس — البند 6

المنفذ هو إنشاء **عرض مادة teacher_subject** للمدرس من Subject موجودة في الدليل العام. إنشاء اسم مادة عالمية جديدة يبقى تحت إدارة المنصة. لم نفتح CRUD عاماً ولم نسمح للمدرس بتعديل عروض غيره.

### GET /teacher/subject-catalog

Bearer لمدرس فعال. يدعم `page` و`per_page` الاختياريين؛ الافتراضي 20 والحد 100. يعيد 200 مع عناصر `id, name, description, status` لمواد Subject الفعالة فقط، بترتيب id تصاعدي. description قد تكون null. القائمة الفارغة 200، والترقيم غير الصالح 422.

### POST /teacher/subjects

| الحقل | النوع | التحقق |
|---|---|---|
| subject_id | integer | مطلوب، مادة موجودة و active |
| price | number | مطلوب، من 0 إلى 9999999999.99 |
| group_enabled | boolean | اختياري، الافتراضي false |
| max_group_size | integer أو null | اختياري، من 1 إلى 100000؛ يُحفظ null إذا كانت المجموعات معطلة |
| teacher_id | — | ممنوع؛ هوية المدرس من التوكن |
| status | — | ممنوع من العميل؛ active عند الإنشاء |

```json
{"subject_id":1,"price":35,"group_enabled":true,"max_group_size":5}
```

النجاح 201 مع عرض المادة داخل `data`، وحقوله: id/teacher_id/subject_id/price/group_enabled/max_group_size/status، وعلاقة `subject` التي تتضمن id/name/description/status. **data.id هو teacher_subject_id، ويختلف عن Subject.id.** استخدموا teacher_subject_id للوحدات والمحتوى والاشتراك بعرض المادة.

إضافة نفس المادة لنفس المدرس مرة ثانية تعيد 422 ضمن `errors.subject_id`، برسالة `This subject is already attached to your teacher account.`، حتى لو كان العرض السابق معطلاً. لا ينشئ المسار نسخة ثانية ولا يعيد تفعيل العرض القديم ضمنياً. المادة المفقودة/غير الفعالة أو السعر غير الصالح أو teacher_id/status غير الفارغ تعيد 422. غياب التوكن 401، والدور أو الحساب غير المؤهل 403.

للقائمة الموجودة لعروض المدرس: `GET /teachers/{teacher_id}/subjects`، وللوحدات: `GET /teacher/subjects/{teacher_subject_id}/units`. لم ننشئ بدائل مكررة لهما. إنشاء Subject باسم جديد من حساب المدرس، أو تعديل وحذف عرض قديم، ليس جزءاً منفذاً من هذا المسار؛ يلزم تدفق إدارة أو عقد إضافي إذا احتاجته الواجهة.

## 8. PATCH /teacher/profile — البند 8

نفس المسار السابق، مع Bearer لمدرس فعال. الطلب جزئي؛ أرسلوا الحقول المعدلة فقط.

| الحقل | النوع | التحقق |
|---|---|---|
| name | string | اختياري، غير فارغ عند إرساله، حتى 255 |
| email | string | اختياري، بريد صالح غير فارغ حتى 255، فريد مع استثناء حسابك |
| phone | string | اختياري، غير فارغ حتى 20، فريد مع استثناء حسابك |
| region_id | integer | اختياري، غير null، وموجود في regions |
| specialization | string أو null | اختياري، حتى 255 |
| bio | string أو null | اختياري، حتى 10000؛ حد التسجيل السابق 1000 |
| experience_years | integer أو null | اختياري، من 0 إلى 100 |

الاسم والبريد والهاتف والمنطقة تُحفظ في users، والبقية في teachers، ضمن معاملة واحدة. الحقول غير المرسلة تبقى كما هي. role/status/user_id والتوكنات ليست حقول تعديل ولا تُحفظ. لا يوجد تغيير كلمة مرور هنا. فشل التحقق يعيد 422 ويمنع حفظ الطلب كله؛ لا يُحفظ الاسم إذا فشل البريد مثلاً.

```json
{"name":"اسم المدرس","email":"teacher@example.invalid","phone":"+963900000000","region_id":1,"specialization":"الرياضيات","bio":"نبذة","experience_years":10}
```

النجاح 200 مع `status:true,data`. حافظنا على حقول المدرس مباشرة داخل data، وأضفنا `data.user` لبيانات المستخدم المحدثة. لم ننقل data.id أو data.specialization إلى مستوى مختلف. التكرار يعيد 422 تحت email/phone، والمنطقة غير الموجودة 422، والحساب غير الفعال 403.

مصدر region_id الموجود: `GET /governorates` ثم `GET /governorates/{id}/regions`، وكلاهما عام. الصورة ليست نصاً في profile؛ المسار الموجود `POST /profile/avatar` يستخدم multipart بحقل avatar، و`DELETE /profile/avatar` يحذفها. التدفق متاح للمدرس المصادق ولم نُعد كتابته ضمن هذا الطلب.

## 9. الاشتراك الحالي — البند 9

`GET /teacher/subscription` مع Bearer، دون body أو queries مطلوبة. المدرس من التوكن، ولا يغيره teacher_id مرسل بالطلب. الاشتراك الحالي يحتاج status=active و starts_at<=now و ends_at>now. إذا وُجدت سجلات قديمة متداخلة يختار الأحدث بدايةً ثم معرفاً. طلب القراءة لا يعدل حالات قاعدة التشغيل.

النجاح 200 مع كائن الاشتراك داخل data، ومعه plan و`effective_status`. اعرضوا اسم الخطة من `data.plan.name`، والنهاية من `data.ends_at`، والتجديد من `data.auto_renew`. **إذا لم يوجد اشتراك سارٍ يعيد 200 مع `data:null` صريحة**؛ اعرضوا اختيار خطة، وليس خطأ خدمة. الاشتراك المستقبلي أو المنتهي لا يُعد حالياً ولو بقيت حالته المخزنة active. غياب المصادقة 401، وعدم أهلية المدرس 403.

## 10. سجل الاشتراكات — البند 10

`GET /teacher/subscriptions?page=1&per_page=20` مع Bearer. يعيد جميع سجلات المدرس الحالي، بما فيها الحالي والماضية والملغاة والمستقبلية، بترتيب starts_at تنازلياً ثم id. لا يعرض اشتراكات مدرس آخر حتى لو أُرسل teacher_id. النجاح 200 مع data ومعلومات meta، وقد تكون القائمة فارغة.

كل عنصر يعيد الحالة المخزنة `status` وإضافة `effective_status`: active للساري، و expired إذا انتهت ends_at وبقيت الحالة المخزنة active، و scheduled إذا كانت starts_at مستقبلاً والحالة المخزنة active، أو حالة السجل الأخرى مثل cancelled. اعتمدوا effective_status لعرض الصلاحية الزمنية. استخدموا المسار المخصص للاشتراك الحالي؛ لا تستنتجوه من أول عنصر بالتاريخ.

تبقى plan متاحة في التاريخ حتى إن أوقفت الإدارة عرضها للاشتراكات الجديدة. العلاقة تشير إلى **سجل الخطة الحالي**؛ ليست لقطة سعر تاريخية أو فاتورة. قاعدة البيانات السابقة لا تحفظ لقطة خطة وسعر لكل اشتراك، ولم نختلق بيانات دفع تاريخية.

## 11. إحصائيات الاستخدام — البند 11

`GET /teacher/usage` مع Bearer لمدرس فعال. لا يقبل تحديد مدرس آخر. يعيد 200 مع data:

| الحقل | المعنى وطريقة العرض |
|---|---|
| subscription_id | الاشتراك الساري، أو null |
| generated_at | وقت حساب الرد |
| groups.used | جميع المجموعات المحفوظة التابعة لعروض المدرس، بكل الحالات |
| groups.active | عدد active/forming |
| groups.limit / remaining | null؛ لا يوجد max_groups في الخطة |
| sessions.used | كل الجلسات المحفوظة للمدرس، بما فيها الملغاة |
| sessions.scheduled | عدد scheduled/ongoing |
| sessions.completed / cancelled | أعداد الحالتين |
| sessions.limit / remaining | null؛ لا يوجد max_sessions في الخطة |
| groups/sessions.scope | all_time؛ ليست أعداد دورة الاشتراك فقط |
| ai_reports.supported | false؛ تتبع استهلاك التقارير الدوري غير منفذ |
| ai_reports.used / remaining | null، وليس صفراً |
| ai_reports.limit | max_ai_usage من الخطة السارية؛ لا يكفي وحده لحساب المتبقي |
| students.used | الطلاب المميزون ذوو اشتراك سارٍ وحساب فعال؛ تعدد الاشتراكات لا يكرر الطالب |
| students.limit / remaining | max_students للخطة، و max(0,limit-used) عندما يكون الحد معلوماً |
| plan_limits | القيم الأصلية max_students/max_storage/max_ai_usage/max_group_size، أو null دون خطة |
| quota_enforcement | false؛ الرد للعرض ولا يضيف نظام منع استهلاك جديداً |

**حدود الدعم:** لا توجد حدود max_groups/max_sessions في مخطط الخطط، ولا سجل AI مؤرخ لدورة الاشتراك. جدول ai_evaluations ليس سجل تقارير وفوترة دورياً. لذلك لم نحسب متبقياً وهمياً أو نعتبر null «غير محدود»، ولم نفرض حصصاً جديدة. max_storage قيمة خام من الخطة؛ هذا المسار لا يقدم تقرير مساحة أو وحدة تحويل معتمدة. max_group_size حد لحجم المجموعة، وليس عدد المجموعات.

في Flutter: اعرضوا الأعداد الحقيقية للمجموعات والجلسات، وأخفوا شريط النسبة والمتبقي عندما تكون القيم غير معروفة. اعرضوا AI «الاستهلاك غير متاح حالياً» بدلاً من 0% أو «متبقي الحد كله». الصفر حد محدد ويختلف عن null. students.remaining معلومة عرض وليست ضمان قبول عملية إضافية.

ربط دورات AI، وتتبع المساحة، ووضع حدود لعدد الجلسات والمجموعات غير مدعوم حالياً؛ يحتاج تعريف المنتج وتخزيناً وفحوصاً إضافية. لا يوجد موعد إتاحة معتمد لهذه الأجزاء. بقية مسارات القراءة المذكورة منشورة الآن.

## 12. اختبارات النشر وخطوات Flutter

- تغيرت 7 ملفات محددة، مع نسخة تراجع للكود ونسخة SQL قبل النشر وفحص البصمات قبل الاستبدال. لم تُرفع vendor أو .env، ولا توجد ترحيلات جديدة لهذا الطلب.
- نجح **96 اختباراً و 1505 تحققات على المحلي**، منها 12 اختبار تكامل جديد. شملت حفظ الملف الشخصي، رفض التكرار، الملكية، التسجيل القديم، الاشتراك والحالات الزمنية والتاريخ والأعداد غير المختلقة.
- نجح **39 فحصاً على السيرفر و MySQL** عبر Laravel HTTP kernel وتوكنات حقيقية مؤقتة: التسجيل والدخول والتجديد ومنع إعادة استعمال refresh والخروج وإبطال التوكن، إنشاء المادة وحماية المالك، الملف الشخصي، تغيير الخطة والسجل والاستخدام.
- فحص HTTPS الخارجي على نفس العنوان: usage دون مصادقة 401، و login/register بطلب فارغ 422. لم تنشأ حسابات دائمة بهذه الطلبات.
- أمثلة القسم التالي لقطات فعلية من transaction تم rollback لها بكل حساباتها وتوكناتها واشتراكاتها. المعرفات والأسماء التجريبية ليست بيانات دخول متاحة الآن. حُجبت التوكنات في التوثيق، ولم تتغير كلمات مرور حسابات موجودة.
- لم تُختبر واجهات Flutter نفسها أو أحمال متزامنة أو PostgreSQL. لا يمكن تأكيد المطابقة الحرفية للعقد القديم غير المتوفر.

على Flutter: حافظوا على parsing الدخول/me، واربطوا حقول profile الموسعة، وأضيفوا اختيار Subject من catalog، ثم اربطوا current/history بمساريهما و usage مع التمييز بين 0 و null. اعتمدوا التوكن لتحديد الهوية، ومعرفات الاستجابات بدلاً من الأمثلة الثابتة. اختبروا الربط بحساب QA المصرح لكم به عبر التدفق الموجود.

## 13. عينات JSON حقيقية من فحص السيرفر

استُبدلت التوكنات السرية فقط بالقيمة `<redacted>`؛ بقية الردود عينات فعلية. مسار كل عينة نسبي إلى BASE_URL. جميع معرفات التجربة مؤقتة، وتم التراجع عن بياناتها بعد الاختبار.


### register — 201

`POST /teacher/register`

```json
{
  "message": "Teacher registered successfully"
}
```

### register_invalid — 422

`POST /teacher/register`

```json
{
  "message": "The name field is required. (and 4 more errors)",
  "errors": {
    "name": [
      "The name field is required."
    ],
    "email": [
      "The email field is required."
    ],
    "password": [
      "The password field is required."
    ],
    "phone": [
      "The phone field is required."
    ],
    "region_id": [
      "The region id field is required."
    ]
  }
}
```

### invalid_login — 401

`POST /teacher/login`

```json
{
  "message": "Invalid credentials"
}
```

### login — 200

`POST /teacher/login`

```json
{
  "message": "Login successful",
  "access_token": "<redacted>",
  "refresh_token": "<redacted>",
  "token_type": "Bearer",
  "expires_in": 900,
  "user": {
    "name": "teacher-front-f70c624961",
    "email": "teacher-front-f70c624961-ca2c17@example.invalid",
    "phone": "qaa7a9111506ab",
    "status": "active",
    "role": "teacher",
    "teacher_status": "active"
  }
}
```

### refresh — 200

`POST /teacher/refresh`

```json
{
  "message": "Token refreshed successfully",
  "access_token": "<redacted>",
  "refresh_token": "<redacted>",
  "token_type": "Bearer",
  "expires_in": 900
}
```

### refresh_replay — 401

`POST /teacher/refresh`

```json
{
  "message": "Invalid or revoked refresh token"
}
```

### me — 200

`GET /teacher/me`

```json
{
  "id": 31,
  "name": "teacher-front-f70c624961",
  "phone": "qaa7a9111506ab",
  "email": "teacher-front-f70c624961-ca2c17@example.invalid",
  "role": "teacher",
  "status": "active",
  "region_id": null,
  "avatar_url": null,
  "teacher": {
    "id": 11,
    "user_id": 31,
    "bio": null,
    "specialization": null,
    "status": "active",
    "experience_years": null
  }
}
```

### profile — 200

`PATCH /teacher/profile`

```json
{
  "status": true,
  "data": {
    "id": 11,
    "user_id": 31,
    "bio": "QA biography",
    "specialization": "Mathematics",
    "status": "active",
    "experience_years": 10,
    "user": {
      "id": 31,
      "name": "teacher-front-f70c624961 updated",
      "phone": "upd0e8024bd52",
      "email": "teacher-front-f70c624961-updated@example.invalid",
      "role": "teacher",
      "status": "active",
      "region_id": 1,
      "avatar_url": null
    }
  }
}
```

### updated_me — 200

`GET /teacher/me`

```json
{
  "id": 31,
  "name": "teacher-front-f70c624961 updated",
  "phone": "upd0e8024bd52",
  "email": "teacher-front-f70c624961-updated@example.invalid",
  "role": "teacher",
  "status": "active",
  "region_id": 1,
  "avatar_url": null,
  "teacher": {
    "id": 11,
    "user_id": 31,
    "bio": "QA biography",
    "specialization": "Mathematics",
    "status": "active",
    "experience_years": 10
  }
}
```

### profile_duplicate — 422

`PATCH /teacher/profile`

```json
{
  "message": "The email has already been taken.",
  "errors": {
    "email": [
      "The email has already been taken."
    ]
  }
}
```

### catalog — 200

`GET /teacher/subject-catalog?per_page=2`

```json
{
  "status": true,
  "data": [
    {
      "id": 1,
      "name": "الرياضيات",
      "description": "منهاج الرياضيات للثالث الثانوي.",
      "status": "active"
    },
    {
      "id": 2,
      "name": "الفيزياء",
      "description": "منهاج الفيزياء للثالث الثانوي.",
      "status": "active"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 5,
    "last_page": 3
  }
}
```

### add_subject — 201

`POST /teacher/subjects`

```json
{
  "status": true,
  "message": "Teacher subject created successfully.",
  "data": {
    "subject_id": 10,
    "price": "35.00",
    "group_enabled": true,
    "max_group_size": 5,
    "teacher_id": 11,
    "status": "active",
    "id": 10,
    "subject": {
      "id": 10,
      "name": "teacher-front-f70c624961 course",
      "description": null,
      "status": "active"
    }
  }
}
```

### subject_duplicate — 422

`POST /teacher/subjects`

```json
{
  "message": "This subject is already attached to your teacher account.",
  "errors": {
    "subject_id": [
      "This subject is already attached to your teacher account."
    ]
  }
}
```

### subject_foreign_owner — 422

`POST /teacher/subjects`

```json
{
  "message": "The teacher id field is prohibited.",
  "errors": {
    "teacher_id": [
      "The teacher id field is prohibited."
    ]
  }
}
```

### no_current_subscription — 200

`GET /teacher/subscription`

```json
{
  "status": true,
  "data": null
}
```

### plans — 200

`GET /teacher/subscription-plans?billing_cycle=monthly&per_page=2`

```json
{
  "status": true,
  "data": [
    {
      "id": 2,
      "name": "teacher-front-f70c624961 monthly",
      "price": "10.00",
      "billing_cycle": "monthly",
      "commission_rate": "0.00",
      "max_students": 10,
      "max_storage": null,
      "max_ai_usage": 20,
      "max_group_size": 5,
      "status": "active"
    },
    {
      "id": 1,
      "name": "باقة لقاء التعليمية التجريبية",
      "price": "150000.00",
      "billing_cycle": "monthly",
      "commission_rate": "10.00",
      "max_students": 100,
      "max_storage": 10240,
      "max_ai_usage": 200,
      "max_group_size": 12,
      "status": "active"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 2,
    "last_page": 1
  }
}
```

### subscribe — 201

`POST /teacher/subscription-plans/2/subscribe`

```json
{
  "status": true,
  "message": "Subscription created successfully.",
  "data": {
    "teacher_id": 11,
    "plan_id": 2,
    "starts_at": "2026-10-16T23:34:33.000000Z",
    "ends_at": "2026-11-16T23:34:33.000000Z",
    "auto_renew": true,
    "status": "active",
    "id": 5,
    "plan": {
      "id": 2,
      "name": "teacher-front-f70c624961 monthly",
      "price": "10.00",
      "billing_cycle": "monthly",
      "commission_rate": "0.00",
      "max_students": 10,
      "max_storage": null,
      "max_ai_usage": 20,
      "max_group_size": 5,
      "status": "active"
    }
  }
}
```

### same_plan — 409

`POST /teacher/subscription-plans/2/subscribe`

```json
{
  "status": false,
  "message": "You are already subscribed to this plan."
}
```

### invalid_auto_renew — 422

`POST /teacher/subscription-plans/3/subscribe`

```json
{
  "message": "The auto renew field must be true or false.",
  "errors": {
    "auto_renew": [
      "The auto renew field must be true or false."
    ]
  }
}
```

### current_subscription — 200

`GET /teacher/subscription`

```json
{
  "status": true,
  "data": {
    "id": 5,
    "teacher_id": 11,
    "plan_id": 2,
    "starts_at": "2026-10-16T23:34:33.000000Z",
    "ends_at": "2026-11-16T23:34:33.000000Z",
    "auto_renew": true,
    "status": "active",
    "plan": {
      "id": 2,
      "name": "teacher-front-f70c624961 monthly",
      "price": "10.00",
      "billing_cycle": "monthly",
      "commission_rate": "0.00",
      "max_students": 10,
      "max_storage": null,
      "max_ai_usage": 20,
      "max_group_size": 5,
      "status": "active"
    },
    "effective_status": "active"
  }
}
```

### upgrade — 200

`POST /teacher/subscription-plans/3/subscribe`

```json
{
  "status": true,
  "message": "Subscription upgraded successfully.",
  "data": {
    "teacher_id": 11,
    "plan_id": 3,
    "starts_at": "2026-10-16T23:34:33.000000Z",
    "ends_at": "2027-10-16T23:34:33.000000Z",
    "auto_renew": false,
    "status": "active",
    "id": 6,
    "plan": {
      "id": 3,
      "name": "teacher-front-f70c624961 yearly",
      "price": "100.00",
      "billing_cycle": "yearly",
      "commission_rate": "0.00",
      "max_students": 20,
      "max_storage": null,
      "max_ai_usage": 40,
      "max_group_size": null,
      "status": "active"
    }
  }
}
```

### subscription_history — 200

`GET /teacher/subscriptions?per_page=1&page=2`

```json
{
  "status": true,
  "data": [
    {
      "id": 5,
      "teacher_id": 11,
      "plan_id": 2,
      "starts_at": "2026-10-16T23:34:33.000000Z",
      "ends_at": "2026-11-16T23:34:33.000000Z",
      "auto_renew": true,
      "status": "cancelled",
      "plan": {
        "id": 2,
        "name": "teacher-front-f70c624961 monthly",
        "price": "10.00",
        "billing_cycle": "monthly",
        "commission_rate": "0.00",
        "max_students": 10,
        "max_storage": null,
        "max_ai_usage": 20,
        "max_group_size": 5,
        "status": "active"
      },
      "effective_status": "cancelled"
    }
  ],
  "meta": {
    "current_page": 2,
    "per_page": 1,
    "total": 2,
    "last_page": 2
  }
}
```

### usage — 200

`GET /teacher/usage`

```json
{
  "status": true,
  "data": {
    "subscription_id": 6,
    "generated_at": "2026-10-16T23:34:33+00:00",
    "groups": {
      "used": 1,
      "active": 1,
      "limit": null,
      "remaining": null,
      "limit_status": "not_configured",
      "scope": "all_time",
      "unit": "groups"
    },
    "sessions": {
      "used": 1,
      "scheduled": 1,
      "completed": 0,
      "cancelled": 0,
      "limit": null,
      "remaining": null,
      "limit_status": "not_configured",
      "scope": "all_time",
      "unit": "sessions"
    },
    "ai_reports": {
      "supported": false,
      "used": null,
      "limit": 40,
      "remaining": null,
      "limit_status": "usage_not_tracked",
      "scope": null,
      "unit": "reports",
      "reason": "No dated subscription usage ledger or report quota accounting is implemented."
    },
    "students": {
      "used": 1,
      "limit": 20,
      "remaining": 19,
      "scope": "active_enrollments",
      "unit": "distinct_students"
    },
    "plan_limits": {
      "max_students": 20,
      "max_storage": null,
      "max_ai_usage": 40,
      "max_group_size": null
    },
    "quota_enforcement": false
  }
}
```

### anonymous_usage — 401

`GET /teacher/usage`

```json
{
  "message": "Unauthenticated."
}
```

### student_denied — 403

`GET /teacher/usage`

```json
{
  "message": "you do not have permission to access this resource"
}
```

### pending_teacher — 403

`GET /teacher/subscription`

```json
{
  "message": "This action is unauthorized."
}
```

### logout_missing_refresh — 422

`POST /teacher/logout`

```json
{
  "message": "The refresh token field is required.",
  "errors": {
    "refresh_token": [
      "The refresh token field is required."
    ]
  }
}
```

### logout — 200

`POST /teacher/logout`

```json
{
  "message": "Logged out successfully"
}
```

### logged_out_access — 401

`GET /teacher/me`

```json
{
  "message": "Unauthenticated."
}
```

### logged_out_refresh — 401

`POST /teacher/refresh`

```json
{
  "message": "Invalid or revoked refresh token"
}
```

