# لقاء — البحث والفلاتر وربط Flutter

تاريخ التسليم: 2026-11-04. الحالة: منفّذ ومختبر محلياً فقط. لم يُنشر على السيرفر.

تحديث لاحق بنفس اليوم: أُلغيت مسارات IndividualOffer محلياً بطلب المستخدم. الحجز الفردي أصبح يعتمد على teacher_subject_id؛ راجع DIRECT_INDIVIDUAL_BOOKING_FRONTEND_LOCAL_2026-11-04.md قبل ربط شاشة المواعيد والحجز.

## 1. ما الذي نُفّذ؟

أُضيف بحث موحّد من خلال قوائم التطبيق الحالية، مع المحافظة على المسارات وشكل JSON والصلاحيات. لا يوجد مسار جديد افتراضي اسمه `/search`: استعمل مسار الشاشة نفسها وأضف `q` والفلاتر المناسبة لها.

الملفات المرفقة:

- `debounced_search.dart`: مساعد Dart مستقل، جاهز لنقله إلى مشروع Flutter وربطه بالشاشات وعميل HTTP الموجود.
- `debounced_search_test.dart`: اختبارات مستقلة يمكن تشغيلها بالأمر `dart debounced_search_test.dart`.

مشروع Flutter نفسه غير موجود ضمن مشروع Laravel الذي عُدّل؛ لذلك لم تُعدّل شاشاته تلقائياً. المساعد مختبر بشكل مستقل، والربط داخل التطبيق مطلوب من فريق Flutter.

## 2. الطلب الأساسي

العنوان المحلي: `http://127.0.0.1:8098/api/v1`. من محاكي Android استخدم عنوان الجهاز المضيف المناسب؛ `127.0.0.1` داخل الهاتف يشير للهاتف نفسه.

```http
GET /api/v1/student/teachers?q=هبة&region_id=1&per_page=20&page=1
Accept: application/json
Authorization: Bearer <student_access_token>
```

احتفظ بهيدرات الجهاز المطلوبة حالياً لمسارات الطالب. لا تغيّر تسجيل الدخول أو توكنات التطبيق. مسارات الفهرس العامة تبقى عامة، والمسارات المحمية تبقى محمية كما كانت.

| الحقل | النوع | السلوك |
|---|---|---|
| `q` | string اختياري، حتى 100 محرف | بحث جزئي؛ حرف واحد مسموح |
| `search` | string اختياري، حتى 100 محرف | اسم قديم مدعوم لنفس البحث |
| `page` | integer من 1 | للقوائم المقسمة إلى صفحات |
| `per_page` | integer بين 1 و100 | الافتراضي 20 في القوائم المقسمة |

إذا أُرسل `q` و`search` معاً، تُعتمد `q` حتى لو كانت فارغة. المسافات في بداية ونهاية النص تُحذف. النص الفارغ يعيد القائمة مع الفلاتر الأخرى. البحث يبحث عن عبارة جزئية متصلة؛ لا يضيف تصحيحاً إملائياً أو توحيداً بين الهمزات، ولا يحذف التشكيل. الأحرف اللاتينية غير حساسة لحالة الحرف عبر `LOWER`، والتفاصيل اللغوية الإضافية تتبع collation قاعدة البيانات.

تُعامل `%` و`_` و`!` كحروف عادية، وليست أوامر لتوسيع البحث. القيم مرتبطة بالاستعلام وليست SQL مُدمجاً من نص المستخدم.

## 3. خريطة الشاشات والمسارات

جميع المسارات التالية GET وتبدأ بـ`/api/v1`. كل الفلاتر المذكورة اختيارية، وتُجمع بـAND مع البحث ومع صلاحيات المسار. داخل البحث نفسه يكفي تطابق أحد حقول الشاشة.

### المدرّسون

| المسار | حقول البحث | الفلاتر |
|---|---|---|
| `/teachers` أو `/teachers/directory` أو `/student/teachers` | اسم المدرّس أو تخصصه | `specialization`, `subject_id`, `region_id`, `governorate_id` |
| `/teachers/featured` | اسم المدرّس أو تخصصه | `subject_id`, `region_id`, `governorate_id` |

النتائج تشمل الحسابات والمدرّسين النشطين فقط. `mode=online|physical` يبقى مقبولاً في دليل المدرّسين وفق العقد القديم، لكنه لا يفصل المدرّسين لأن النظام الحالي يعرض النمطين للمدرّس. `grade` غير مدعوم في دليل المدرّسين ويعيد 422، فلا ترسلوا فلتر الصف قبل وجود ربط فعلي بالمناهج.

`subject_id` هنا هو رقم المادة العامة. `teacher_subject_id` في المسارات الأخرى هو رقم مادة المدرّس، وهما مختلفان.

### المجموعات والطلاب والتدريس الفردي

| المسار | حقول البحث | الفلاتر الجديدة/المدعومة |
|---|---|---|
| `/teacher/groups` | اسم المجموعة أو المادة | `teacher_subject_id`, `subject_id`, `region_id`, `governorate_id`, `min_price`, `max_price`؛ ويبقى `status` القديم |
| `/student/groups` | المجموعة أو المادة أو المدرّس | الفلاتر أعلاه مع `teacher_id`؛ ويبقى `scope=active|history|all` |
| `/student/teachers/{teacher}/physical/groups` | المجموعة أو المادة أو المدرّس | `teacher_subject_id`, `subject_id`, `region_id`, `governorate_id`, `min_price`, `max_price` |
| `/teacher/groups/{group}/students` | اسم الطالب | `student_id`, `region_id`؛ ويبقى `status=active|left|removed|all` |
| `/teacher/groups/{group}/join-requests` | اسم الطالب | `student_id`, `region_id`؛ ويبقى `status=pending|approved|rejected|all`، الافتراضي pending |
| `/student/groups/{group}/join-requests` | اسم الطالب صاحب الطلب | الطلبات تبقى محصورة بالطالب الحالي |
| `/teacher/individuals` | اسم الطالب أو المادة | `student_id`, `teacher_subject_id`, `subject_id`, `region_id`, `min_price`, `max_price` |

`student_id` هو رقم جدول students، و`{teacher}` رقم teachers. لا تستعملوا users.id مكانهما. المجموعات المغلقة لا تظهر في مسارات الاستعراض الفيزيائي الحالية، ومسارات العروض أُلغيت بالتحديث اللاحق. البحث لا يغيّر قواعد الانضمام أو حجز الجلسات.

قيمة `session_price` للمجموعة هي سعر جلسة الطالب الواحد، ولا تُقسم على عدد أعضاء المجموعة. أسعار الاختبار على السيرفر ليست جزءاً من هذا التعديل المحلي.

للتوافق مع العقد القديم، تمرير `teacher_id` إلى `/teacher/groups` أو `/teacher/subjects` لا يغيّر صاحب القائمة؛ صاحبها يبقى الأستاذ المسجّل حصراً.

### الجلسات والتوافر

| المسار | البحث | الفلاتر |
|---|---|---|
| `/teacher/sessions` | اسم المدرّس أو المادة أو المجموعة أو طالب الجلسة الفردية | الموجود `type`, `type_id`, `status`, `scope=today`, `date`, `timezone`؛ أُضيف `date_from`, `date_to` |
| `/student/bookings` | اسم المدرّس أو المادة أو المجموعة أو طالب الفردي | الموجود `scope=upcoming|history|all`؛ أُضيف `date_from`, `date_to`, `timezone` |
| `/teacher/availability` | التاريخ أو وقت البداية أو النهاية | `date_from`, `date_to`, `status=active|disabled`, `recurrence_type=none|weekly|custom`, `min_price`, `max_price` |

تواريخ الفلترة بصيغة YYYY-MM-DD. حدود الجلسات تشمل يوم البداية والنهاية اعتماداً على **وقت بداية الجلسة** في `timezone`، الافتراضي `Asia/Damascus`. إذا `date_from` أكبر من `date_to` فالرد 422. فلترة تاريخ التوافر تستهدف الحقل `date` فقط، لذلك لا تُرجع التوافر الأسبوعي الذي date فيه null؛ عرض المواعيد الفعلية المتاحة يبقى عبر API التوافر الفيزيائي المخصص.

في مسار الأستاذ أرسلوا `X-Liqaa-Teaching-Contract: 2` للحصول على `type=group|individual` الحديث؛ توافق المسار القديم لم يتغير.

قائمة `/student/bookings` كانت وتبقى مصفوفة كاملة بلا meta؛ لا تطبقوا عليها تحميل صفحات وهمياً.

### المواد والوحدات والدروس والمحتوى والاختبارات

| المسار | البحث | الفلاتر |
|---|---|---|
| `/teacher/subject-catalog` | اسم المادة أو وصفها | `q`, `search`, pagination |
| `/teacher/subjects` | اسم المادة | `subject_id`, `min_price`, `max_price` |
| `/teachers/{teacher}/subjects` | اسم المادة أو المدرّس | `subject_id`, `min_price`, `max_price` |
| `/teacher/subjects/{teacherSubject}/units` | عنوان الوحدة أو وصفها | `min_price`, `max_price` |
| `/units/{unit}/lessons` | عنوان الدرس أو وصفه | `min_price`, `max_price` |
| `/lessons/{lesson}/contents` | عنوان المحتوى أو وصفه | `content_type=video|pdf|quiz`, `min_price`, `max_price` |
| `/student/lessons/{lesson}/content` | عنوان المحتوى أو وصفه | `content_type=video|pdf|quiz`, `min_price`, `max_price` |
| `/student/teachers/{teacher}/online-content` | المادة أو المدرّس أو عنوان وحدة/درس/محتوى منشور ضمنها | `subject_id`, `min_price`, `max_price` لسعر المادة |
| `/teacher/content-assets` | عنوان المحتوى المرتبط بالملف | `content_id`, `content_type=video|pdf|quiz` |
| `/student/my-quizzes` | عنوان المحتوى أو الدرس أو المادة أو المدرّس | `lesson_id`, `teacher_subject_id`, `teacher_id`؛ الفلاتر السابقة scope/status باقية |

البحث في شجرة online-content يعيد **المواد المطابقة مع شجرتها المنشورة كاملة**. لا يحذف العناصر غير المطابقة من داخل المادة المطابقة؛ هذا يحافظ على بنية العقد والعدادات. المحتوى غير المنشور لا يُستخدم لاختيار مادة مطابقة. فلاتر السعر في هذا المسار لسعر المادة وليست للأسعار داخل الشجرة.

لا يحوّل البحث وجود نتيجة إلى إذن تشغيل: قواعد الاشتراك والنشر والـPolicy وروابط تشغيل المحتوى تبقى كما كانت.

### الاشتراكات والمدفوعات والاسترداد والإشعارات

| المسار | البحث | الفلاتر |
|---|---|---|
| `/student/enrollments` و`/student/booking` | المدرّس أو عنوان المادة/الوحدة/الدرس/المحتوى المرتبط | `teacher_id`، وتبقى قيود الاشتراكات الحالية للمسار |
| `/teacher/subscription-plans` | اسم الخطة | `min_price`, `max_price`؛ ويبقى `billing_cycle=monthly|yearly` |
| `/teacher/subscriptions` | اسم الخطة | pagination |
| `/student/payments` و`/admin/payments` | المرجع `provider_reference` | `min_price`, `max_price`؛ ويبقى `payment_status=PENDING_REVIEW|APPROVED|REJECTED` |
| `/student/refunds/eligibility` | مرجع الدفعة | `min_price`, `max_price`؛ ويبقى `payment_id` |
| `/student/refunds` و`/refunds/my` و`/admin/refunds` | سبب الطلب أو رقم الحوالة الأصلية | `payment_id`, `min_price`, `max_price`؛ ويبقى status القديم |
| `/student/notifications` و`/teacher/notifications` | عنوان الإشعار أو نصه | pagination |
| `/student/notification-feed` و`/teacher/notification-feed` | عنوان الإشعار أو نصه | الموجود `unread_only`, `kind=all|personal|campaign` |
| `/admin/notification-campaigns` | عنوان الحملة أو نصها | الموجود `status=draft|scheduled|dispatched|cancelled` |

في feed يبقى `meta.unread_count` عدد غير المقروء ضمن نوع feed المختار **قبل البحث**؛ البحث لا يجعل عداد الجرس يختفي. أما `meta.total` فهو عدد النتائج بعد البحث والفلاتر.

المدفوعات والاسترداد محصورة بصاحب الحساب أو صلاحية المدير كما كانت. لم تُضف مشاركة أو تخزين مشترك لنتائج الحسابات.

### اختيار الموقع

`/governorates` و`/governorates/{id}/regions` و`/regions/{id}/schools` تدعم `q` أو `search` على الاسم، مع الإبقاء على نطاق المحافظة/المنطقة المختارة. هذه قوائم كاملة وليست مقسمة إلى صفحات، ومن المناسب تخزينها محلياً وفلترتها أثناء الاختيار.

## 4. الاستجابة والأخطاء

شكل الاستجابة القديم محفوظ. مثال توضيحي لدليل المدرّسين:

```json
{
  "status": true,
  "data": [{
    "id": 4,
    "name": "هبة الخطيب",
    "specialization": "اللغة العربية",
    "avatar_url": null,
    "modes": ["online", "physical"],
    "status": "active",
    "region_id": 1
  }],
  "meta": {"current_page": 1, "per_page": 20, "total": 1, "last_page": 1}
}
```

الأرقام توضيحية وليست بيانات أُنشئت بهذا التعديل. لا تفترضوا أن جميع المسارات تملك نفس حقول العنصر؛ كل مسار يحتفظ بعقده القديم.

- لا توجد نتائج: 200 مع `data: []` و`meta.total: 0` بالقوائم المقسمة.
- 401: توكن غير صالح أو غير موجود للمسار المحمي.
- 403 أو 404: منع الوصول وفق سياسة المسار الحالية.
- 422: نص/صفحة/فلتر غير صحيح، أو `min_price > max_price`، أو ترتيب تواريخ غير صحيح.

```json
{
  "message": "The maximum price must be at least the minimum price.",
  "errors": {"max_price": ["The maximum price must be at least the minimum price."]}
}
```

حقول الفلاتر الموثقة فقط مضمونة. وجود parameter غير موثق لا يعني أنه يُطبّق؛ لا تُبنَ الواجهة على فلتر لم يتم ربطه.

## 5. آلية Flutter بدون طلب لكل حرف

1. أنشئوا `DebouncedSearch` مستقلاً لكل حساب وشاشة، ثم نادوا `search('')` عند البداية.
2. اربطوا `TextField.onChanged` باستدعاء `search(text, filters: currentFilters)`.
3. يحصل `onState` فوراً على فلترة محلية للبيانات المحمّلة ضمن نفس الفلاتر، مع `preview=true` و`loading=true`.
4. بعد توقف الكتابة 350ms يُرسل طلب واحد. استمرار الكتابة يعيد تشغيل المؤقت.
5. الطلب المطابق المعلّق لا يتكرر، والنتيجة الحديثة المحفوظة تُعرض بلا HTTP.
6. نتائج كل كلمة/فلاتر/صفحة محفوظة 60 ثانية، وبحد أقصى 30 صفحة لكل controller. هذا حفظ داخل ذاكرة التطبيق وليس على القرص.
7. عند وصول رد قديم يتجاهله المساعد، حتى لو البحث الجديد ما زال بمهلة التأخير.
8. بعد الرد الصحيح تصبح `preview=false`؛ عندها فقط يجوز اعتماد رسالة «لا توجد نتائج» والعدد النهائي.
9. عند التمرير استعملوا `loadNextPage()` للقوائم المقسمة. يمنع المساعد الطلب المتزامن المكرر ويزيل تكرار العناصر عبر `keyOf`.
10. عند تعديل البيانات استدعوا `refresh()`، وعند الخروج أو تبديل الحساب أو إغلاق الشاشة استدعوا `dispose()` وأنشئوا instance جديداً عند الحاجة.

هذا الأسلوب يقلل الطلبات ولا يضمن طلباً واحداً لكل كلمة مهما طال التوقف؛ التوقف أكثر من 350ms بين الأحرف قد يرسل طلباً لكل توقف. ليس هناك WebSocket أو polling.

### ربط fetch بالمشروع

المساعد لا يفرض Dio أو http أو Riverpod/Bloc. مثال توضيحي باستخدام عميل المشروع الحالي:

```dart
final liveSearch = DebouncedSearch<TeacherView>(
  fetch: (request) async {
    final response = await api.get(
      '/student/teachers',
      queryParameters: request.queryParameters,
    );
    final body = response.data;
    final meta = body['meta'];
    return SearchPage(
      (body['data'] as List).map((x) => TeacherView.fromJson(x)).toList(),
      meta['current_page'] as int,
      meta['total'] as int,
      meta['last_page'] as int,
    );
  },
  matches: (teacher, q) {
    final text = q.toLowerCase();
    return teacher.name.toLowerCase().contains(text) ||
        (teacher.specialization ?? '').toLowerCase().contains(text);
  },
  keyOf: (teacher) => teacher.id.toString(),
  onState: (state) {
    // حدّث Bloc/Riverpod/State الخاص بالشاشة.
    // preview=true تعني نتائج محلية مؤقتة، وerror لا تعني عدم وجود نتائج.
  },
);

// initial load
liveSearch.search('');

// TextField.onChanged
liveSearch.search(text, filters: {'region_id': selectedRegionId});

// on scroll end
await liveSearch.loadNextPage();

// after a mutation
liveSearch.refresh();

// widget dispose / logout
liveSearch.dispose();
```

احذفوا الفلاتر الفارغة أو مرّروها null حسب عميل HTTP. أرسلوا IDs أرقاماً، ولا تضعوا access token ضمن filters. حساب المستخدم والمسار ثابتان داخل fetch الخاص بهذا instance؛ لا تعيدوا استخدام instance لحساب أو شاشة أخرى.

للمسارات التي ترجع مصفوفة كاملة بلا meta، حوّلوا الرد إلى `SearchPage(items, 1, items.length, 1)`، ولا تطلبوا صفحة ثانية. الشجرة online-content تحتاج parser لبيانات `data.subjects` ومطابقة محلية للعناوين المنشورة؛ ليست قائمة مدرسين.

الفلترة المحلية لا تُثبت اكتمال النتائج، ولا تمنح صلاحية فتح المحتوى. لا تمنعوا طلب الباك لمجرد أن النص الجديد يبدأ بالنص السابق؛ ربما النتائج المطابقة موجودة بصفحات لم تُحمّل.

## 6. الأداء والاختبارات

البحث ينفذ داخل قاعدة البيانات قبل pagination، وتُحفظ قيود المالك والنشر والحالة قبل إضافة شروط البحث المجمّعة. القوائم التي كانت غير مقسمة بقي شكلها كما هو لتجنب كسر الفرونت.

لم تُضف ترحيلات جديدة: فهارس المفاتيح والحالة والأب موجودة بالمشروع. فهرس B-tree عادي لا يجعل `LIKE '%term%'` بحثاً مفهرساً بالكامل؛ إذا تضخمت البيانات يلزم قياس فعلي ثم إضافة حل بحث مناسب. لم يُنفّذ اختبار حمل إنتاجي في هذا التسليم.

التحقق المحلي:

- 265 اختبار PHP إجمالاً: 263 ناجحاً، 2 متجاوزان مرتبطان بـFFmpeg، 4,250 تحققاً.
- منها 12 اختبار بحث جديد: حرف عربي واحد، الرموز الحرفية، alias القديم، validation، الترقيم، نطاق الأستاذ والطالب، العلاقات polymorphic، المحتوى المنشور، الإشعارات، وتغيّر البيانات.
- تشغيل مباشر على MySQL محلية: العربية وحالة الحروف و`%` و`_` و`!` ومحاولة SQL كنص؛ بلا تعديل بيانات.
- استجابات HTTP محلية ناجحة من دليل المدرّسين. لم يُنفذ ربط جهاز Flutter فعلي في هذه الخطوة.
- تحليل Dart: No issues found. اختبارات المساعد نجحت للتأخير والنتائج المحلية والتخزين المؤقت والفلاتر والصفحات والأخطاء والردود القديمة وانتهاء مدة التخزين.

أُبقي تعديل StudentAuthController الموجود قبل هذه المهمة كما هو. لا يوجد نشر أو تعديل على السيرفر ضمن هذا العمل.
