# لقاء — ربط الإشعارات والحملات

**الحالة: مطبق ومختبر محلياً فقط. لم يُرفع هذا التحديث إلى السيرفر.**
تاريخ الإعداد: 2026-11-04 الساعة 05-16 بتوقيت دمشق. أمثلة الاستجابات مستخرجة من طلبات اختبار على SQLite مؤقتة؛ المعرفات توضيحية وليست حسابات موجودة. لم تُرسل أي رسالة لهاتف حقيقي.

## ما تم استكماله
- الاحتفاظ بالإشعارات الشخصية الموجودة، وربط إنشائها بإرسال Firebase من طابور مستقل بعد نجاح المعاملة.
- حفظ الحملة العامة مرة واحدة مهما كان عدد المستلمين، مع سجل قراءة يُنشأ فقط عند القراءة.
- حملات للجميع أو الطلاب أو الأساتذة أو حساب طالب/أستاذ محدد؛ حفظ مسودة وجدولة ونشر فوري وإلغاء قبل النشر.
- قائمة موحدة جديدة للرسائل الشخصية والحملات، دون تغيير صيغة المسارات القديمة.
- تسجيل عناوين FCM مشفرة؛ الطالب مرتبط بجهازه الحالي، والأستاذ حتى 5 تسجيلات محفوظة. توكن الحساب ليس عنوان FCM.
- التحقق مجدداً من الحساب والجهاز والتوجيه وقت الإرسال؛ احترام Retry-After وإعادة المحاولة للأخطاء المؤقتة وحذف التسجيل عند UNREGISTERED فقط.

## الاتصال والهوية
الأساس المحلي: `http://127.0.0.1:8098/api/v1`. هذا العنوان يعمل على الكمبيوتر نفسه؛ Flutter على هاتف يحتاج عنوان جهاز التطوير القابل للوصول ضمن شبكة التطوير. لا تستخدم رابط الإنتاج لهذه المسارات الجديدة قبل نشرها هناك.
أرسل `Accept: application/json` و`Content-Type: application/json` و`Authorization: Bearer <access_token>`.
مسارات الطالب تتطلب جلسة الجهاز الفعّال؛ مسارات الأستاذ تتطلب حساب أستاذ فعالاً؛ مسارات الإدارة تتطلب company_admin فعالاً. لا ترسل user_id أو device_id في تسجيل FCM.

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
      "body": "هذا مثال تحقق محلي.",
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
جميع المسارات التالية تتطلب Bearer لمدير company_admin فعال. هذه APIs؛ لا يوجد تبويب ويب جديد مضاف لتصميم لوحة الإدارة ضمن هذه الجولة.
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
{"title":"إعلان المنصة","body":"هذا مثال تحقق محلي.","target_scope":"all"}
```

```json
{
  "status": true,
  "message": "Campaign saved; dispatch is separate.",
  "data": {
    "title": "إعلان المنصة",
    "body": "هذا مثال تحقق محلي.",
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
    "body": "هذا مثال تحقق محلي.",
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

## 6. التشغيل المحلي للباقي من الفريق
المشروع: `C:\xampp\htdocs\tutoring-platform`.
- google/auth مثبت، واعتماد OAuth تحقق فعلياً للمشروع liqaa-eed99.
- الترحيل 2026_11_03_233509_create_notification_delivery_infrastructure منفذ مسبقاً محلياً؛ لم نستخدم migrate:fresh على قاعدة المشروع. العدد الحالي 58 ترحيلاً.
- FIREBASE_PUSH_ENABLED=true محلياً؛ لم يوجد أي عنوان FCM مسجل أو إرسال معلق وقت التفعيل. إعدادات الاعتماد الموجودة محفوظة.
- طابور الإرسال الافتراضي database واسم queue هو notifications، حتى لو كان QUEUE_CONNECTION العام sync. يمكن تخصيص الاتصال غير المتزامن عبر NOTIFICATIONS_QUEUE_CONNECTION.

نافذة العامل (يجب إبقاؤها عاملة أثناء التجربة):
```powershell
cd C:\xampp\htdocs\tutoring-platform
php artisan queue:work database --queue=notifications --tries=5 --timeout=45 --sleep=1
```
نافذة الجدولة:
```powershell
cd C:\xampp\htdocs\tutoring-platform
php artisan schedule:work
```
لتجربة نشر الحملات التي حان وقتها مرة واحدة: `php artisan notifications:dispatch-due`.
العامل لا يُشغَّل كخدمة دائمة تلقائياً في هذه الجولة؛ اختُبر مرة على الطابور المحلي الفارغ. قبل النشر على السيرفر لاحقاً يلزم تشغيل العامل بشكل مُدار وcron لـschedule:run وإعادة تشغيل العمال عند تحديث الكود، مع إعداد google/auth وملف اعتماد خاص خارج النشر العام. لا تنقل .env المحلي فوق .env السيرفر.

تنظيف التخزين:
- `php artisan notifications:prune` أو `--dry-run`: معاينة فقط؛ لا حذف.
- `php artisan notifications:prune --execute`: حذف الشخصي المقروء منذ 7 أيام، وغير المقروء منذ 30 يوماً، والحملات المنشورة منذ 30 يوماً وقراءاتها التابعة، على دفعات صغيرة.
- لا يوجد حذف دوري مُفعّل تلقائياً. المسودات والمجدولة لا تُحذف بهذا الأمر. قبل اعتماد التنفيذ الفعلي راجع سياسة الاحتفاظ والنسخة الاحتياطية؛ لم نشغل execute على قاعدة المشروع.

## 7. التحقق والحدود
- 241 اختباراً و4054 تحققاً ناجحاً على النسخة المحلية الفعلية.
- 15 اختبار إشعارات و97 تحققاً ناجحاً على MariaDB محلية مستقلة، دون استخدام قاعدة المشروع للاختبارات.
- اختبار إضافي لاستخراج أمثلة API، بما فيه مسار الطالب بجلسة جهاز حقيقية داخل SQLite، نجح بـ11 تحققاً.
- 277 مساراً سابقاً محفوظة؛ أصبح العدد 287، دون استبدال المسارات القديمة أو تغيير API الدفع والحجوزات.
- نجح OAuth الحقيقي لدى Google. إرسال FCM اختُبر بمحاكاة HTTP فقط: لا ادعاء بأن هاتفاً استلم الرسائل أو بأن APNs/أذونات Flutter مهيأة. اختبار الوصول النهائي يحتاج ربط تطبيق وهاتف حقيقيين.
- الطابور بعد commit فقط؛ rollback لا يترك إرسالاً. التوكنات مشفرة ولا تدخل نصاً في payload الوظائف. الإرسال يحترم ملكية الحملة وحالة الحساب والجهاز عند التنفيذ.
- توجد حماية قصيرة الأجل من تكرار التسليم؛ لا ضمان exactly-once عند انقطاع الشبكة بعد قبول Firebase للرسالة. FCM نفسه ليس إثبات قراءة.
- لا توجد لوحة ويب جديدة للحملات، ولا سجل دائم لكل محاولة إرسال، ولا Push قديم يعاد بثه تلقائياً عند تشغيل الميزة.

مراجع الخادم: [Firebase HTTP v1](https://firebase.google.com/docs/cloud-messaging/send/v1-api)، [أخطاء FCM](https://firebase.google.com/docs/cloud-messaging/error-codes)، [Laravel وإرسال الطوابير بعد المعاملة](https://laravel.com/docs/12.x/queues#jobs-and-database-transactions).
