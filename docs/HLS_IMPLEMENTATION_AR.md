# تشغيل الفيديو تلقائياً عبر HLS — 2026-11-01

هذا هو قرار الفيديو الحالي: HLS مع تشفير AES-128 وروابط مؤقتة، دون Widevine أوFairPlay أوDRM. خطة VIDEO_HLS_DRM_PLAN.md السابقة مؤجلة بقرار صاحب المشروع.

## ما نُفذ

1. رفع فيديو عبر مسار الأستاذ أو إدارة المحتوى أوإنشاء ContentAsset فيديو يشغّل Observer تلقائياً.
2. إنشاء VideoAsset بحالة pending ثم جدولة ProcessHlsVideo بعد نجاح معاملة قاعدة البيانات؛ لا تحويل ضمن طلب الرفع.
3. Worker مستقل ينسخ الأصل الخاص إلى مساحة عمل محلية مؤقتة ويفحصه بـffprobe.
4. FFmpeg ينتج H.264/AAC بجودات 360p/720p/1080p المتناسبة مع المصدر، مع تغليف HLS وتقسيمه إلى مقاطع نحو6 ثوانٍ. FFmpeg هو المحوّل والـPackager هنا؛ لا حاجة لبرنامج Shaka Packager منفصل.
5. مفتاح عشوائي مستقل لكل جودة ولكل إصدار، وتشفير HLS AES-128. لا يعتمد هذا على مزوّد DRM أوحساب Google.
6. رفع كل النتائج إلى نفس القرص الخاص ثم جعل الفيديو ready بعد اكتمال الرفع فقط؛ الأصل يبقى خاصاً لإعادة المعالجة.
7. الطالب يحصل على master playlist مؤقت. الباك يعيد كتابة روابط قوائم الجودة والمقاطع والمفاتيح بروابط موقعة مرتبطة بالطالب وإصدار الفيديو وبنفس انتهاء الجلسة.
8. كل طلب يعيد فحص التوكن وجلسة الجهاز وحق الوصول؛ إلغاء الاشتراك أوتجميد الوصول يمنع الطلب التالي. لا رابط فيديو أصلي للطالب، حتى عبر مسار play القديم.

AES-128 هنا ليس DRM. المشغّل المخوّل يحصل على المفتاح، وبالتالي لا نَعِد بمنع نسخ الفيديو من طالب مخوّل بالمشاهدة. watermark_enabled القديم ليس علامة مائية مضافة فعلياً بهذا التنفيذ.

## تشغيل السيرفر

لم تُشغّل الهجرة أوالـWorker على قاعدة السيرفر الفعلية ضمن هذا التعديل.

المتطلبات: FFmpeg مع libx264/AAC، وffprobe، ومساحة عمل كافية للأصل والنتائج، وقرص media خاص. اختبارات التحويل الفعلية نُفذت محلياً. تشغيل عملية على ملفات مصدر غير موثوقة يحتاج Worker محدود الصلاحيات، ولا تضع مساحة عمله أوالمقاطع تحت public/.

بعد النسخ الاحتياطي وتحديث الكود وتهيئة البيئة:

```bash
php artisan migrate
php artisan config:cache
php artisan queue:restart
php artisan queue:work media --queue=media --timeout=3600 --tries=3 --sleep=3
```

يعمل الـWorker تحت Supervisor أومدير خدمات يعيد تشغيله؛ العملية طويلة المدى، وليست طلب HTTP. يجب أن يقرأ Worker وLaravel إعدادات DB/Storage/Cache نفسها. الحدث WithoutOverlapping يستعمل Cache lock مشتركاً عند توزيع عدة Workers؛ لا تستخدم array cache في الإنتاج. اتصال media مبني على database، وretry_after=3900 أكبر من timeout=3600. لا تشغل طابور media عبر اتصال database الافتراضي ذي retry_after=90، ولا عبر sync.

متغيرات .env.example الجديدة:

```dotenv
FFMPEG_BINARY=ffmpeg
FFPROBE_BINARY=ffprobe
HLS_QUEUE_CONNECTION=media
```

يمكن أن تكون قيم binary مسارات مطلقة للملفات التنفيذية. config/media.php يحدد الجودات وحد6 ثوانٍ للقطعة، وحد4 ساعات للمصدر، و900 ثانية لكل عملية تحويل، و30 دقيقة لروابط التشغيل. إن تغيرت الحدود يلزم إبقاء أقصى زمن المهمة أقل من retry_after.

استخدم HTTPS في الإنتاج؛ المفتاح يمر للمشغّل عبر الاتصال المحمي. التخزين الخاص مطلوب حتى لو كان خلف S3/CDN. التنفيذ الحالي يمرر المقاطع عبر Laravel ولا يعطي رابط bucket عام؛ عند زيادة عدد المشاهدين يمكن نقل التسليم إلى CDN بسياسة مصادقة مكافئة، فهذا غير منفّذ ضمن هذه النسخة.

### الفيديوهات الموجودة سابقاً

الهجرة لا تحوّل الملفات داخل migrate. بعد تشغيل Worker:

```bash
php artisan media:process-videos
php artisan media:process-videos --asset=123
```

الأمر يتجاوز ready/pending/processing ذات المهمة المعروفة. لإعادة بناء فيديو أوإعادة جدولة مهمة مفقودة بعد التحقق من عدم وجود معالجة عاملة:

```bash
php artisan media:process-videos --asset=123 --force
```

الفيديو القديم لا يعود للأصل كخيار بديل؛ يرجع VIDEO_PROCESSING حتى تحويله. ملفات PDF تستمر بعقدها السابق. لا تستخدم الإدخال المباشر لنفس storage_key لاستبدال بايتات المصدر؛ ارفع Asset جديداً أواستخدم force صراحة بعد تحديث checksum.

الفشل يحفظ كوداً عاماً، ويسجل Worker التفاصيل في سجل التشغيل ويعيد المحاولة وفق الطابور. بعد إيقاف قسري لنظام التشغيل قد تبقى مساحة عمل مؤقتة؛ ينظفها المشغّل بعد التأكد من عدم وجود مهمة تستعملها. لا ننفذ حذفاً عشوائياً لمساحات عمل مهمة جارية.

## APIs للفرونت

البادئة /api/v1. المسارات المحمية تحتاج Authorization: Bearer لتوكن الطالب المرتبط بجهازه. لا تضع Bearer في Query.

### رفع الأستاذ ومتابعة الحالة

`POST /teacher/contents/{content}/asset`، multipart/file كما سابقاً. فيديوهات MP4/MOV/WebM/MKV، حد512000KB. إنشاء الأصل يرجع201 مع video_asset.processing_status=pending. فحص النوع الفعلي يحصل عند الرفع وفحص الفيديو أثناء المعالجة؛ ليس كل ملف باسم .mp4 فيديو صالحاً.

`GET /teacher/content-assets/{contentAsset}` أوالقائمة `GET /teacher/content-assets?content_id=...` يعيدان video_asset:

```json
{
  "processing_status": "ready",
  "processing_error": null,
  "duration_seconds": 121,
  "renditions": [{"name":"360p","max_width":640,"max_height":360,"bandwidth":910800}],
  "processed_at": "2026-11-01T10:00:00.000000Z"
}
```

القيم المعتمدة pending/processing/ready/failed. processing_error إماnull أوHLS_PROCESSING_FAILED. مثال الوقت والمعرفات توضيحي. الحقول hls_manifest_key/hls_directory/source_fingerprint/key_reference مخفية من VideoAsset JSON.

`POST /teacher/content-assets/{contentAsset}/hls/retry` دونbody. للأستاذ المالك الموثق فقط؛202 عند الجدولة،409 إن كان pending/processing/ready، و404 عند محاولة الوصول لملف أستاذ آخر. يسمح بفيديو active فاشل أوبلا سجل معالجة. للإدارة أمرCLI لإعادة البناء.

حقول content_assets.encryption_type/encryption_status تشير للمعالجة المشتقة: hls-aes128 وpending/processing/done/failed. اعتمد video_asset.processing_status لواجهة الفيديو، ولا تعتبر وجود القيمة done التي أرسلها عميل دليلاً على جاهزية HLS؛ الجاهزية يقررها الخادم.

### عرض الملفات وطلب التشغيل

`GET /contents/{content}/assets` يضيف processing_status للفيديو؛null للـPDF. لا يرسلstorage_key.

`POST /contents/{id}/playback-token` يختار أحدث فيديوactive للمحتوى. أو `POST /content-assets/{contentAsset}/access-token` لملف محدد. دونbody.

إذا جاهز،200:

```json
{
  "status": true,
  "message": "Playback token created successfully.",
  "data": {
    "playback_url": "https://YOUR_HOST/api/v1/content-assets/123/hls/master.m3u8?student=45&version=GENERATED_ID&expires=...&signature=...",
    "expires_at": "2026-11-01T10:30:00+00:00",
    "format": "hls",
    "mime_type": "application/vnd.apple.mpegurl",
    "processing_status": "ready",
    "drm": false,
    "encryption": "aes-128",
    "renditions": [{"name":"360p","max_width":640,"max_height":360,"bandwidth":910800}]
  }
}
```

إذا لم يجهز،409:

```json
{"status":false,"code":"VIDEO_PROCESSING","message":"الفيديو قيد التجهيز، حاول لاحقاً.","data":{"processing_status":"pending"}}
```

إذا فشل،409 معVIDEO_PROCESSING_FAILED وprocessing_status=failed. الفرونت يعرض الحالة ولا ينتقل إلى رابطMP4. رسالة الفشل تطلب إعادة المحاولة من الأستاذ، ولا تدعي إرسال إشعار خارجي.

### تشغيل المقاطع والمفتاح

`GET /content-assets/{contentAsset}/hls/{file}` يستعمل حصراً الروابط الصادرة من الخادم. لا يركب الفرونت signature/student/version/expires بنفسه. ملف master يعيد روابط الجودة، وقائمة الجودة تعيد روابط المقاطع والمفتاح موقعة:

- .m3u8: application/vnd.apple.mpegurl.
- segment_000000.ts: video/mp2t.
- key.bin: application/octet-stream،16 بايت. المفتاح ليس حقلاً فيJSON.

كلها private/no-store. كل الروابط تحتاج التوكن والجهاز الصحيحين؛ الروابط وحدها لا تكفي. 401 للتوكن/الجهاز،403 للتوقيع المنتهي أوالطالب المختلف،404 لغياب الصلاحية/تبدل الإصدار/ملف غير مسموح.

المشغل يجب أن يرسل Authorization على **master وقوائم الجودة والمقاطع وطلبات AES key كلها**. في hls.js استخدم xhrSetup أوfetchSetup/loader لإضافة header. فتح رابط master مباشرة في تبويب أواستخدام video.src وحده لا يضيف Bearer تلقائياً. على iOS/المشغّل الأصلي يجب اختبار وصول headers لكل طلب؛ إذا تعذر ذلك يحتاج الفرونت/الباك مسار جلسة cookie آمنة بدلاً من تمرير التوكن بالعنوان. لم يُضف مسار cookie جديد هنا، ولم يُختبر جهاز iPhone فعلي ضمن اختبارات الباك.

للفيديو الطويل: جدّد access_token حسب عقد المصادقة الحالي (قد ينتهي قبل30 دقيقة)، وجدّد playback-token قبلexpires_at ثم أعد تحميل المصدر مع حفظ موضع التشغيل. روابط المقاطع والمفتاح تنتهي بنفس وقتmaster؛ لا تمدد نفسها. لا تجعل انتهاء الروابط يُكمل الدرس تلقائياً؛ تبقى APIs الإكمال منفصلة.

## ملفات التنفيذ

- الهجرة2026_11_01_000001_add_hls_processing_to_video_assets.php: processing_status،processing_error،hls_directory،source_fingerprint،renditions،processed_at فيvideo_assets.
- app/Services/HlsVideos.php وapp/Jobs/ProcessHlsVideo.php: التحويل والتشفير والتغليف والرفع وإعادة المحاولة.
- app/Observers/ContentAssetObserver.php: جدولة رفع/استبدال الفيديو وتنظيف المخرجات عند حذفAsset.
- app/Services/HlsPlayback.php وHlsPlaybackController.php: الروابط وإعادة كتابة القوائم والتحقق من المسارات.
- StudentContentAssetController.php: إيقاف تقديم الأصل وتحويل عقد التشغيل إلىHLS.
- TeacherContentAssetController.php: الحالة وإعادة المحاولة، ومنع رفعPDF كملف فيديو عبرmultipart.
- ProcessExistingHlsVideos.php: جدولة الملفات القديمة.
- config/media.php وconfig/queue.php و.env.example، والمساراتstudent.php وteacher.php، وVideoAsset.php وAppServiceProvider.php.
- tests/Feature/HlsVideoTest.php: تحويل حقيقي مع صوت وبدونه، جودات متعددة دونتكبير،AES،فشل،توقيع،صلاحية،جهاز،تبدل المصدر وملكية إعادة المحاولة. StudentFeaturesTest جُدد لعقد رفض تشغيل الأصل أثناءpending.

لا مزوّد DRM أوإرسال Google أوخدمة مدفوعة فُعّلت بهذا التعديل. لا تطبيق هجرة فعلية أونشر أوتشغيل Worker إنتاجي ضمن هذه الخطوة.

## التحقق

الفحص النهائي: 203 اختبارات ناجحة، و6 إخفاقات لوحة الإدارة المعروفة بسبب قوالب Blade المفقودة،3563 assertions. اختبارات HLS العشرة ناجحة، وتشمل رفع فيديو فعلي عبرAPI ثم تحويله وتشفيره وقراءة قائمة التشغيل وفكها بواسطةffprobe،وفيديو صامت بجودتين. التحقق البنيوي يشمل179 عمليةOpenAPI وPostman. لا يعني ذلك اختبار تطبيق الفرونت أوSafari/iPhone فعلياً.
