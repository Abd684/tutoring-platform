# لقاء — عقد ربط الإشعارات للفرونت (Flutter)

تاريخ التوثيق: 2026-11-04 06:08 بتوقيت دمشق.

## حالة التسليم
تم نشر الكود والتحقق على السيرفر. تفاصيل التشغيل والفحوص أدناه. لم يُختبر وصول رسالة لهاتف Flutter حقيقي بعد.

## عناوين الاتصال
- عنوان API الإنتاج الكامل: `https://mandobe.net/liqaa/api/v1/`.
- لوحة الحملات: `https://mandobe.net/liqaa/admin/notifications`، بحساب المدير الحالي وتسجيل الدخول المعتاد.
- المسارات أدناه مكتوبة من `/api/v1`؛ عند تكوين Dio بالعنوان الكامل أعلاه استخدم `student/notification-feed` مثلاً، دون تكرار `/api/v1` أو إسقاط `/liqaa`.
- كل عمليات الإشعارات تتطلب `Authorization: Bearer <access_token>` و`Accept: application/json`. طلبات JSON تستخدم `Content-Type: application/json`.
- هذا هو توكن لقاء المعتاد، ولا تحتاج Firebase Authentication أو JWT من Firebase لتسجيل الدخول إلى هذه APIs.
- عنوان FCM مستقل عن Access Token وعن refresh_token وعن device_uuid. استخدم عنوان تسجيل FCM الذي يدعمه SDK الحالي، وليس Firebase Installation ID أو توكن APNs مباشرة.
- الطالب يحتاج جلسة جهازه الفعال الناتجة عن تسجيل الدخول؛ لا يمكن تجاوز هذا الشرط بإرسال user_id أو device_id.

## ملخص المسارات الكاملة لتطبيق الطالب والمدرّس
| الطريقة | الرابط الكامل | الاستخدام |
|---|---|---|
| POST | `https://mandobe.net/liqaa/api/v1/push-tokens` | تسجيل عنوان FCM، لكل من الطالب والمدرّس |
| DELETE | `https://mandobe.net/liqaa/api/v1/push-tokens` | إلغاء عنوان التثبيت، مع JSON في body |
| GET | `https://mandobe.net/liqaa/api/v1/student/notification-feed` | صندوق الطالب والعداد |
| GET | `https://mandobe.net/liqaa/api/v1/teacher/notification-feed` | صندوق المدرّس والعداد |
| POST | `https://mandobe.net/liqaa/api/v1/student/notifications/{id}/read` | قراءة إشعار شخصي للطالب |
| POST | `https://mandobe.net/liqaa/api/v1/teacher/notifications/{id}/read` | قراءة إشعار شخصي للمدرّس |
| POST | `https://mandobe.net/liqaa/api/v1/student/notification-campaigns/{id}/read` | قراءة حملة للطالب |
| POST | `https://mandobe.net/liqaa/api/v1/teacher/notification-campaigns/{id}/read` | قراءة حملة للمدرّس |

أمثلة JSON في الأقسام التالية توضح شكل العقد؛ الأرقام ليست حسابات يُفترض استخدامها. أمثلة طلبات تحقق من السيرفر مرفقة بنهاية الملف بعد استكمال النشر.

## 1. تسجيل عنوان Firebase
`POST /api/v1/push-tokens` — طالب أو أستاذ.
```json
{"fcm_token":"<FCM_REGISTRATION_TOKEN>","platform":"android"}
```
| الحقل | النوع | القاعدة |
|---|---|---|
| fcm_token | string | مطلوب، 20 إلى 4096 محرفاً |
| platform | string | مطلوب: android أو ios أو web |
| user_id / device_id | ممنوعان | الهوية والجهاز يؤخذان من الجلسة |
نجاح 200؛ تكرار نفس العنوان لنفس الحساب يحدث التسجيل دون صف مكرر. الطالب يحتفظ بتسجيل واحد، والأستاذ بخمسة كحد أقصى. لا يمكن الاستيلاء على عنوان مربوط بحساب آخر؛ يرجع 409.

```json
{
  "status": true,
  "message": "Push token registered.",
  "data": {
    "registered": true
  }
}
```

الأخطاء: 401 لجلسة جهاز غير صالحة، 403 للدور/الحساب غير المسموح، 409 لتعارض ملكية العنوان، 422 للحقول، و429 لحد تسجيل العناوين. لا تحفظ العنوان في السجلات أو ترسله ضمن تقارير الأخطاء.

`DELETE /api/v1/push-tokens` — نفس Bearer وبجسم JSON:
```json
{"fcm_token":"<FCM_REGISTRATION_TOKEN>"}
```
يرجع 200 و`data.registered=false`، حتى إن كان العنوان غير موجود. لا يزيل عنوان حساب آخر. يجب أن يستدعي Flutter هذا قبل مسار logout، بينما توكن الحساب ما زال صالحاً. عند تبديل الحساب لا تعِد استخدام عنوان الحساب السابق دون إلغاء التسجيل؛ إذا ظهر 409، عالج دورة تسجيل Firebase للحساب القديم أو اطلب عنوان تثبيت جديد من SDK ولا تحاول تغيير user_id.

## 2. قائمة الإشعارات الموحدة
| الطريقة | المسار | المستخدم |
|---|---|---|
| GET | `/api/v1/student/notification-feed` | الطالب الحالي |
| GET | `/api/v1/teacher/notification-feed` | الأستاذ الحالي |

Query parameters: `page` رقم صفحة، `per_page` من 1 إلى 100 (20 افتراضياً)، `unread_only` قيمة 0 أو 1، و`kind` إحدى all أو personal أو campaign (all افتراضياً).
الترتيب بالأحدث زمنياً ثم النوع والمعرف لكسر التعادل؛ `created_at` و`read_at` بتوقيت UTC بصيغة ISO8601. `meta.unread_count` يشمل غير المقروء ضمن فلتر kind كله، وليس الصفحة الحالية فقط.

```json
{
  "status": true,
  "data": [
    {
      "kind": "campaign",
      "id": 1,
      "type": "campaign",
      "title": "إعلان المنصة",
      "body": "مثال توضيحي لشكل الاستجابة.",
      "data": null,
      "read_at": null,
      "created_at": "2026-11-04T02:13:26.000000Z",
      "related_payment_id": null,
      "key": "campaign:1",
      "read": false
    },
    {
      "kind": "personal",
      "id": 1,
      "type": "test",
      "title": "إشعار شخصي",
      "body": "مثال توضيحي.",
      "data": null,
      "read_at": null,
      "created_at": "2026-11-04T02:13:26.000000Z",
      "related_payment_id": null,
      "key": "personal:1",
      "read": false
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 2,
    "last_page": 1,
    "unread_count": 2
  }
}
```

**مهم:** قد توجد رسالة شخصية id=1 وحملة id=1. استخدم `key` للـListView/cache والتمييز، لا id وحده. `kind` يحدد مسار القراءة. ظهور الحملة أو انتقال حالتها إلى dispatched لا يعني أن كل هاتف استلم Push.
القائمة الجديدة تظهر الشخصي المقروء خلال 7 أيام من read_at، وغير المقروء خلال 30 يوماً من created_at، والحملات خلال 30 يوماً من dispatched_at. مسارات GET القديمة `/student/notifications` و`/teacher/notifications` ما زالت ترجع الشخصي فقط بصيغتها السابقة؛ انتقل إلى notification-feed لعرض الحملات.
الحملات الموجهة لا تظهر لغير صاحبها أو لفئة مختلفة. المسودات والمجدولة والملغاة لا تظهر للمستلمين. إذا غاب المستلم المحدد لاحقاً لا تتحول حملته إلى حملة عامة.

## 3. تعليم القراءة
- شخصي: `POST /api/v1/student/notifications/{notification}/read` أو مسار teacher المناظر؛ Body `{}`.
- حملة: `POST /api/v1/student/notification-campaigns/{campaign}/read` أو مسار teacher المناظر؛ Body `{}`.
تعاد 200 عند التكرار دون إضافة سجل قراءة آخر. الحملة المنتهية أو غير الموجهة للحساب تعيد 404. قراءة مستخدم لا تغيّر قراءة بقية المستلمين.

```json
{
  "status": true,
  "data": {
    "id": 1,
    "kind": "campaign",
    "key": "campaign:1",
    "read": true,
    "read_at": "2026-11-04T02:13:26.000000Z"
  }
}
```

بعد القراءة حدّث العنصر والعداد، أو أعد جلب القائمة. لا تعتبر وصول Push بمثابة قراءة.

## 4. APIs إدارة الحملات
جميع المسارات التالية تتطلب Bearer لمدير company_admin فعال. لوحة الويب تتوفر في /liqaa/admin/notifications؛ يمكن إنشاء الحملة واختيار المستلم بالاسم وجدولتها ونشرها وإلغاؤها. التطبيق الطلابي لا يحتاج إلى مسارات الإدارة.
| الطريقة | المسار | العمل |
|---|---|---|
| GET | `/api/v1/admin/notification-campaigns` | قائمة paginated؛ status اختياري |
| POST | `/api/v1/admin/notification-campaigns` | إنشاء مسودة أو حملة مجدولة |
| GET | `/api/v1/admin/notification-campaigns/{campaign}` | تفاصيل |
| PUT | `/api/v1/admin/notification-campaigns/{campaign}` | تعديل كامل قبل النشر/الإلغاء |
| POST | `/api/v1/admin/notification-campaigns/{campaign}/dispatch` | نشر الآن، Body `{}` |
| POST | `/api/v1/admin/notification-campaigns/{campaign}/cancel` | إلغاء قبل النشر، Body `{}` |

حقول POST وPUT:
| الحقل | النوع | القاعدة |
|---|---|---|
| title | string | مطلوب، حتى 180 محرفاً |
| body | string | مطلوب، حتى 2000 محرف |
| target_scope | string | مطلوب: all / students / teachers / student / teacher |
| target_user_id | integer | مطلوب فقط مع student أو teacher، من users.id وبالدور المطابق وحالة active؛ ممنوع مع الجمهور العام |
| scheduled_at | string أو null | اختياري؛ موعد مستقبلي مع timezone، مثال 2026-11-05T16:00:00+03:00 |
| data | object أو null | اختياري، حتى 20 قيمة نصية أو null، كل نص حتى 512 محرفاً |
| status / dispatched_at / created_by | ممنوعة | يحددها الخادم |

بدون scheduled_at ينشأ draft ولا يُرسل تلقائياً. مع تاريخ مستقبلي ينشأ scheduled. يمكن نشر مسودة أو مجدولة فوراً باستدعاء dispatch. النشر idempotent: التكرار يعيد 200 دون إعادة توزيع الحملة. PUT يعيد إرسال الحقول المطلوبة كاملة؛ حذف scheduled_at أو إرساله null يحولها إلى draft.
مثال إنشاء:
```json
{"title":"إعلان المنصة","body":"مثال توضيحي لشكل الاستجابة.","target_scope":"all"}
```

```json
{
  "status": true,
  "message": "Campaign saved; dispatch is separate.",
  "data": {
    "title": "إعلان المنصة",
    "body": "مثال توضيحي لشكل الاستجابة.",
    "target_scope": "all",
    "created_by": 1,
    "status": "draft",
    "updated_at": "2026-11-04T02:13:26.000000Z",
    "created_at": "2026-11-04T02:13:26.000000Z",
    "id": 1
  }
}
```

مثال نشر نفس الحملة:

```json
{
  "status": true,
  "message": "Campaign published in the inbox; push is queued when enabled.",
  "data": {
    "id": 1,
    "created_by": 1,
    "target_scope": "all",
    "target_user_id": null,
    "title": "إعلان المنصة",
    "body": "مثال توضيحي لشكل الاستجابة.",
    "data": null,
    "status": "dispatched",
    "scheduled_at": null,
    "dispatched_at": "2026-11-04T02:13:26.000000Z",
    "created_at": "2026-11-04T02:13:26.000000Z",
    "updated_at": "2026-11-04T02:13:26.000000Z"
  }
}
```

حالات status: draft، scheduled، dispatched، cancelled. بعد dispatched لا يمكن تعديل الرسالة أو إلغاء ما وصل؛ يرجع 409. لا يوجد DELETE عام للحملات. عمليات الإنشاء والتعديل والنشر والإلغاء مسجلة في audit_logs. النشر المجدول مسجل كعملية نظام.
التحقق 422 للهدف غير المطابق أو الموعد الماضي أو الحقول الممنوعة، 403 للدور غير الإداري أو المدير غير الفعال، 404 لمعرف غير موجود، و409 لتعديل حالة نهائية. مثال رفض دور غير إداري:

```json
{
  "message": "you do not have permission to access this resource"
}
```

## 5. ربط Flutter خطوة بخطوة
1. هيئ Firebase وفق إعدادات تطبيقكم ونسخة SDK المثبتة، واطلب إذن الإشعارات. لا تضع JSON حساب الخدمة داخل Flutter؛ هذا الملف خاص بالخادم.
2. بعد نجاح دخول الطالب أو الأستاذ احصل على عنوان تسجيل FCM وأرسله إلى POST push-tokens. أعد تسجيل العنوان عند تغيره، وبعد العودة للحساب/تبديله. الربط يستخدم fcm_token، وليس Access Token أو معرف هاتف من اختيار العميل.
3. شاشة الجرس تجلب notification-feed بحسب الدور، وتستخدم data قائمة وmeta للصفحات والعداد. عالج null في data/read_at/related_payment_id. لا تعرض campaign خاصة بالمستلم من قائمة الإدارة العامة.
4. رسالة Push تحمل notification.title/body وdata صغيرة: `kind=personal` مع `notification_id`، أو `kind=campaign` مع `campaign_id`. قيم data المرسلة من FCM نصية. لا تحمل إثباتات دفع أو أسرار نقل الجهاز.
5. عند الضغط على الرسالة تحقق أولاً من الحساب الحالي، ثم اجلب البيانات من API الموثق وافتح العنصر الموافق للنوع والمعرف. لا تتعامل مع JSON الحملة كأمر لتنفيذ رابط خارجي. مع pagination قد لا يكون العنصر في الصفحة الأولى؛ اجلب بقية الصفحات أو أظهر صندوق الإشعارات المحدث.
6. تعامل مع فتح التطبيق من الخلفية أو من حالة الإغلاق، ومع الرسائل في المقدمة. عرض شريط النظام في المقدمة يحتاج إعداد الواجهة حسب المنصة؛ الباك لا يرسم إشعار Flutter.
7. عند الفتح/القراءة استخدم مسار read حسب kind، وحدث badge. عند 401 اتبع تجديد توكن الحساب المعتاد؛ عند 404 لا تعرض تفاصيل من كاش حساب سابق.
8. عند logout استدعِ DELETE push-tokens أولاً ثم logout وامسح بيانات عرض الحساب. ربط الطالب بجهازه يعاد فحصه عند الإرسال؛ نقل الجهاز أو انتهاء جلسته يمنع التسليم للجهاز القديم.
9. تعطيل التنبيهات لهذا التثبيت يتم بإلغاء التسجيل. لا يوجد تفضيل حساب عام أو تصنيفات صوت/قنوات جديدة في API هذه الجولة.

مثال Dio مستقل عن نسخة Firebase SDK:
```dart
import 'package:dio/dio.dart';

class NotificationApi {
  NotificationApi(this.dio, this.role);
  final Dio dio; // baseUrl ends with /api/v1/ and has the current Bearer token.
  final String role; // student or teacher

  Future<void> register(String fcmToken, String platform) async {
    await dio.post('push-tokens', data: {'fcm_token': fcmToken, 'platform': platform});
  }
  Future<void> unregister(String fcmToken) async {
    await dio.delete('push-tokens', data: {'fcm_token': fcmToken});
  }
  Future<Map<String, dynamic>> inbox({int page = 1}) async {
    final r = await dio.get('$role/notification-feed',
      queryParameters: {'page': page, 'per_page': 20});
    return Map<String, dynamic>.from(r.data);
  }
  Future<void> markRead(Map<String, dynamic> item) async {
    final collection = item['kind'] == 'campaign'
      ? 'notification-campaigns' : 'notifications';
    await dio.post('$role/$collection/${item['id']}/read', data: <String, dynamic>{});
  }
}
```
المثال يفترض وجود Dio ضمن مشروعكم وإعداد Authorization وAccept. لا تُنشئ instance لطالب بتوكن الأستاذ. احتفظ بعنوان FCM في الذاكرة/التخزين المناسب للتثبيت لإلغائه عند الخروج، ولا تطبعه.
مراجع Flutter الرسمية: [تهيئة FCM وواجهة التسجيل حسب نسخة SDK](https://firebase.google.com/docs/cloud-messaging/flutter/get-started)، [الاستقبال وفتح الرسائل](https://firebase.google.com/docs/cloud-messaging/flutter/receive-messages).


## 6. سلوك الواجهة المطلوب
1. افتح صندوق الإشعارات من المسار الموحد، لا تدمج يدوياً قائمة الحملات الإدارية مع قائمة الطالب.
2. استخدم `meta.unread_count` لعداد الجرس؛ لا تعدّ عناصر الصفحة فقط. اجعل حالة العرض scoped للحساب الحالي وامسحها عند تبديله.
3. `read=false` يعني غير مقروء. لا ترسل read بمجرد تحميل الصفحة؛ أرسله عند فتح المستخدم للرسالة أو تعليمه القراءة صراحةً.
4. عند وصول Push والمستخدم داخل التطبيق، حدّث العداد والقائمة. عرض إشعار محلي أو banner من مسؤولية Flutter؛ امنع عرض نسختين لنفس الرسالة.
5. استعمل `kind` و`key` لتحديد النوع والمعرّف. لا تفترض أن `campaign:12` و`personal:12` نفس الإشعار.
6. بيانات `data` في قائمة API قد تكون null، أو object أو array بحسب الرسالة القديمة؛ لا تستخدم cast إجبارياً إلى Map دون تحقق. `related_payment_id` قد يكون null أو رقماً/قيمة رقمية حسب تعريف قاعدة البيانات القديمة.
7. لا يوجد مسار تفاصيل فردي جديد للمستلم أو mark-all-read أو حذف رسالة أو إعداد تفضيلات حساب عام في هذا التحديث. عند فتح Push اجلب الصندوق وابحث في الصفحات، أو اعرض الصندوق إذا لم تجد الرسالة.
8. عند عرض الأوقات حوّل ISO8601 UTC إلى توقيت الجهاز. أرسل أوقات الجدولة للإدارة مع offset صريح، مثل `+03:00`.
9. عند تسجيل الخروج ألغ تسجيل FCM بتوكن لقاء الحالي أولاً، ثم نفّذ logout المعتاد. إذا فشل الإلغاء بسبب انقطاع الشبكة أو تبديل الحساب، عالج دورة عنوان FCM قبل إعادة ربطه؛ لا تتجاهل تعارض 409 أو تحاول تغيير هوية المستلم.

## 7. استقبال Firebase وفتح التطبيق
هيّئ مشروع Firebase في Flutter ليطابق `liqaa-eed99` وتطبيق Android/iOS الصحيح. ملفات إعداد العميل مختلفة عن حساب الخدمة الخاص بالخادم؛ لا تطلب حساب الخدمة ولا تضعه ضمن التطبيق.

اتبع واجهة التسجيل المدعومة في نسخة firebase_messaging المثبتة. بعد الحصول على عنوان التسجيل أرسله إلى `POST push-tokens`، وأعد المزامنة عند تغيّره وعند تسجيل الدخول؛ لا ترسل عنواناً قبل وجود حساب لقاء فعّال.

عالج حالات المقدمة والخلفية والإغلاق. المرجع الرسمي يشرح `getInitialMessage()` لفتح التطبيق المغلق و`onMessageOpenedApp` لفتحه من الخلفية. سجّل المستمع مرة واحدة وتخلّص منه عند دورة حياة الخدمة المناسبة، وانتظر جاهزية التنقل وهوية المستخدم قبل فتح وجهة.

محتوى Push المتوقع من الباك:
```json
{"notification":{"title":"عنوان","body":"نص"},"data":{"kind":"campaign","campaign_id":"12"}}
```
أو:
```json
{"notification":{"title":"عنوان","body":"نص"},"data":{"kind":"personal","notification_id":"27"}}
```
هذه البنية تصف الجزء الذي تستقبله الواجهة، وليست طلباً يرسله Flutter إلى Google. المعرفات داخل data نصية؛ حوّلها بأمان. قد يُختصر نص Push الطويل إلى 2000 بايت؛ النص الكامل متاح بالقائمة.

إذن نظام التشغيل وإعدادات Android وقنواته، وتهيئة APNs وentitlements في iOS، مسؤولية مشروع Flutter وحساباته. رفض الإذن لا يمنع صندوق API من العمل. لا تحفظ token أو Authorization في سجلات التطبيق.

مراجع Firebase الرسمية: [بدء الربط](https://firebase.google.com/docs/cloud-messaging/flutter/get-started)، [الاستقبال وفتح التطبيق](https://firebase.google.com/docs/cloud-messaging/flutter/receive-messages). أمثلة SDK ليست تثبيتاً لنسخة حزمة محددة؛ راعوا pubspec.lock لديكم.

## 8. معالجة الأخطاء
| HTTP | التصرف |
|---|---|
| 401 | اتبع تجديد جلسة لقاء؛ إذا انتهت جلسة جهاز الطالب يلزم تسجيل الدخول أو إكمال نقل الجهاز |
| 403 | تحقق من الدور وحالة الحساب؛ لا تكرر الطلب بلا تغيير |
| 404 عند القراءة | العنصر غير متاح لهذا الحساب أو منتهي؛ أعد جلب القائمة |
| 409 عند تسجيل FCM | العنوان مملوك لحساب آخر؛ لا تحاول إعادة تعيين user_id |
| 409 في إدارة الحملات | الحالة لا تسمح بالتعديل أو الإلغاء؛ أعد جلب الحملة |
| 422 | اعرض errors حسب الحقول؛ تحقق من JSON والأنواع والموعد والهدف |
| 429 | احترم Retry-After إن وُجد، وأخّر الإعادة |
| 5xx/انقطاع الشبكة | رسالة فشل قابلة لإعادة المحاولة دون اعتبار العملية ناجحة |

بعض الأخطاء القديمة تعيد `message` فقط، وبعضها يحتوي `errors`؛ لا تفترض أن جميع الأخطاء مغلّفة بـstatus/data. عند إعادة تسجيل نفس العنوان أو تعليم نفس الحملة مقروءة لا تتولد سجلات مكررة.

## 9. خطة قبول ربط Flutter
- تسجيل دخول طالب بجهاز فعّال ثم POST push-tokens والتأكد من registered=true.
- إعادة تسجيل نفس العنوان دون تكرار الحساب؛ جلب الصندوق والعداد.
- إنشاء حملة موجّهة لحساب تجربة من لوحة الإدارة بالاسم، وحفظها ثم نشرها.
- التأكد من ظهورها في الصندوق؛ ثم اختبار الهاتف في المقدمة والخلفية والإغلاق.
- فتح الحملة وتعليمها القراءة ومقارنة العداد، وعدم ظهورها لحساب آخر.
- اختبار الشخصي والحملة مع تشابه id، وصفحة ثانية، وقائمة فارغة.
- تجربة إلغاء التسجيل قبل logout وتبديل حسابين دون تسريب حالة العرض أو Push سابق.
- تجربة حملة مجدولة في المستقبل ومتابعة نشرها بعد دورة الجدولة.
- اختبار iOS وAndroid كلٌ على جهازه وإذنه وإعداداته؛ نجاح Android لا يثبت تهيئة APNs.

## 10. إثبات النشر والتشغيل

```json
{
  "status": "DEPLOYED_VERIFIED",
  "files": 35,
  "routes": 295,
  "previous_routes": 264,
  "maintenance_and_checks_seconds": 4.5,
  "backup": "/home/mand/liqaa-notifications-backup-20261104",
  "real_push_sent": false,
  "firebase_configured": true,
  "internal_checks_verified": 25,
  "public_checks_verified": 6,
  "push_enabled": true
}
```

### تحقق Firebase دون إرسال حقيقي

```json
{
    "oauth_verified": true,
    "project_id": "liqaa-eed99",
    "fcm_endpoint_verified": true,
    "validate_only": true,
    "dummy_token_rejected": true,
    "http": 400,
    "push_enabled": true,
    "real_push_sent": false
}
```

### الجدولة وطابور الإرسال

```json
{
    "worker_cron_enabled": true,
    "interval": "every minute",
    "worker_queue": "notifications",
    "connection": "database",
    "overlap_prevented": true,
    "scheduled_dispatch_enabled": true,
    "uses_existing_scheduler": true,
    "old_cron_preserved": true,
    "automatic_retention_delete_enabled": false
}
```

### حالة التشغيل النهائية

```json
{
    "status": "FINAL_VERIFIED",
    "push_enabled": true,
    "firebase_project": "liqaa-eed99",
    "credential_mode": "0600",
    "cron_worker_probe_consumed": true,
    "no_new_failed_jobs": true,
    "notification_jobs": 0,
    "original_tables_preserved": 57,
    "migrations": 59,
    "pending_notification_jobs": 0,
    "maintenance": false,
    "real_push_sent": false,
    "verified_at": "2026-11-04T03:07:45+00:00"
}
```

## 11. أمثلة مستخرجة من تحقق السيرفر

هذه الأمثلة من حسابات تجربة ضمن معاملة تم التراجع عنها؛ لا تعِد استخدام المعرفات. لم تتغير الحسابات الحقيقية.

### POST /api/v1/push-tokens — 200

```json
{
  "status": true,
  "message": "Push token registered.",
  "data": {
    "registered": true
  }
}
```

### POST /api/v1/push-tokens — 409

```json
{
  "message": "This notification token is registered to a different account."
}
```

### POST /api/v1/push-tokens — 422

```json
{
  "message": "The user id field is prohibited.",
  "errors": {
    "user_id": [
      "The user id field is prohibited."
    ]
  }
}
```

### GET /api/v1/student/notification-feed — 200

```json
{
  "status": true,
  "data": [
    {
      "kind": "campaign",
      "id": 1,
      "type": "campaign",
      "title": "حملة اختبار",
      "body": "مثال للربط",
      "data": null,
      "read_at": null,
      "created_at": "2026-11-04T03:02:56.000000Z",
      "related_payment_id": null,
      "key": "campaign:1",
      "read": false
    },
    {
      "kind": "personal",
      "id": 15,
      "type": "qa",
      "title": "إشعار تجريبي",
      "body": "اختبار ضمن معاملة يتم التراجع عنها",
      "data": null,
      "read_at": null,
      "created_at": "2026-11-04T03:02:56.000000Z",
      "related_payment_id": null,
      "key": "personal:15",
      "read": false
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 2,
    "last_page": 1,
    "unread_count": 2
  }
}
```

### GET /api/v1/teacher/notification-feed — 200

```json
{
  "status": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 0,
    "last_page": 1,
    "unread_count": 0
  }
}
```

### POST /api/v1/teacher/notification-campaigns/1/read — 404

```json
{
  "message": ""
}
```

### POST /api/v1/student/notification-campaigns/1/read — 200

```json
{
  "status": true,
  "data": {
    "id": 1,
    "kind": "campaign",
    "key": "campaign:1",
    "read": true,
    "read_at": "2026-11-04T03:02:56.000000Z"
  }
}
```

### POST /api/v1/student/notifications/15/read — 200

```json
{
  "status": true,
  "data": {
    "id": 15,
    "read": true,
    "read_at": "2026-11-04T03:02:56.000000Z"
  }
}
```

### GET /api/v1/student/notification-feed?unread_only=1 — 200

```json
{
  "status": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 0,
    "last_page": 1,
    "unread_count": 0
  }
}
```

### GET /api/v1/student/notifications — 200

```json
{
  "status": true,
  "data": [
    {
      "id": 15,
      "type": "qa",
      "title": "إشعار تجريبي",
      "body": "اختبار ضمن معاملة يتم التراجع عنها",
      "data": null,
      "created_at": "2026-11-04T03:02:56.000000Z",
      "related_payment_id": null,
      "read": true,
      "read_at": "2026-11-04T03:02:56.000000Z"
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

### GET /api/v1/teacher/notifications — 200

```json
{
  "status": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 0,
    "last_page": 1
  }
}
```

### GET /api/v1/student/notification-feed — 401

```json
{
  "message": "Unauthenticated."
}
```

### DELETE /api/v1/push-tokens — 200

```json
{
  "status": true,
  "message": "Push token unregistered.",
  "data": {
    "registered": false
  }
}
```

عدد فحوص السيرفر الداخلية الناجحة: 25. اختبارات النسخة المجمعة: 253 اختباراً، 4169 تحققاً، واختبارا ffmpeg الفعليان متجاوزان في البيئة المحلية. الاختبار على هاتف حقيقي بقي مهمة قبول مع Flutter.

## 12. تشغيل السيرفر والحدود العملية
- عامل الإشعارات يلتقط طابور `notifications` عبر cron كل دقيقة، مع قفل يمنع التداخل. قد يتأخر بدء الإرسال حتى دورة العامل التالية، ثم بحسب حجم الطابور وإعادة المحاولة. هذا ليس ضمان تسليم خلال دقيقة.
- الجدولة تستخدم مشغّل Laravel الموجود على السيرفر؛ جرى الحفاظ على مهامه الأخرى. الحملة المنشورة تظهر بصندوق API حتى إذا رفض الهاتف الإذن أو فشل Push.
- فحص Google تم بطلب validate_only وعنوان تجريبي غير صالح عمداً؛ أكد الاعتماد وإمكانية الوصول إلى FCM دون تسليم رسالة. هذا لا يثبت إعداد APNs أو استلام هاتف حقيقي.
- إعادة المحاولة للأخطاء المؤقتة محدودة، وعناوين UNREGISTERED تزال، مع إعادة فحص الحساب والجهاز قبل كل تسليم. لا يوجد سجل تسليم دائم لكل مستخدم أو ضمان exactly-once عند انقطاع الشبكة بعد قبول Google.
- عرض الصندوق يطبق نافذة 7 أيام للشخصي المقروء، و30 يوماً لغير المقروء والحملات المنشورة. الحذف الفعلي الدوري غير مفعّل؛ الأمر `notifications:prune` يعاين فقط، و`--execute` عملية منفصلة لا يحتاجها الفرونت.
- لم يتغير APP_KEY أو إعداد قاعدة البيانات أو كلمة مرور أي حساب. اعتماد الخادم محفوظ خارج public_html، ولا يُشارك مع Flutter أو يُضمّن في هذا الملف.
- أثناء التحقق استُخدمت حسابات تجربة ضمن معاملة وتم التراجع عنها؛ لم تُترك حسابات أو حملات تجربة. المعرفات داخل أمثلة هذا المستند لا تمثل بيانات يجب إدخالها بالتطبيق.
