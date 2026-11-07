# مطابقة المشروع الأساسي مع السيرفر - 2026-11-07

المشروع الأساسي الوحيد للعمل والتجهيز لـ Git هو:
`C:\xampp\htdocs\tutoring-platform`

تمت مقارنة نسخة حية من كود `/home/mand/liqaa_app` مع هذا المشروع، ونُقلت النواقص إليه مباشرة. لم يحصل commit أو push أو تغيير للفرع، ولم يُعد نشر أي كود على السيرفر أثناء هذه المطابقة.

## نتيجة المقارنة

- تمت مقارنة 432 ملفاً: جميع ملفات الكود والموارد الـ431 مطابقة للمحتوى على السيرفر بعد تجاهل اختلاف نهاية السطر فقط.
- ملف `.env.example` المحلي احتفظ بإعدادات HLS الأحدث الموجودة فيه، وأضيفت له أمثلة إعدادات PDF. لهذا يختلف عمداً عن مثال السيرفر الأقدم.
- لا توجد ملفات تشغيل من لقطة السيرفر ناقصة محلياً.
- نُقل 13 ملف تشغيل: أوامر المعالجة، الأقفال، منع تكرار الرفع، migration تتبّع المحاولات، تحديثات المحوّلات والموديل والكونترولرز والجدولة.
- ملفات العمل السابقة والملف غير المتتبع `and business rules` بقيت دون حذف أو تعديل.

## الملفات المنقولة

- `app/Console/Commands/ProcessOnePdf.php`
- `app/Console/Commands/ProcessPendingPdfs.php`
- `app/Services/PdfWorkerLock.php`
- `app/Services/PdfAssetRegistration.php`
- `app/Services/SecurePdfProcessor.php`
- `app/Services/ImagickPdfRenderer.php`
- `app/Services/PopplerPdfRenderer.php`
- `app/Models/ContentAsset.php`
- `app/Http/Controllers/Admin/ContentController.php`
- `app/Http/Controllers/Api/V1/TeacherContentAssetController.php`
- `config/pdf.php`
- `routes/console.php`
- `database/migrations/2026_11_07_000001_add_pdf_worker_attempts.php`

أضيفت الاختبارات وملف PDF مصغر داخل `tests` حتى تعمل من هذا المشروع دون الاعتماد على نسخة خارجية، وأضيف تجاهل ملفات أقفال التشغيل إلى `.gitignore`.

## التحقق من المشروع الأساسي

- الفحص الكامل: 299 اختباراً، 4498 assertion، صفر فشل؛ تجاوز اختبارين لمعالجة الفيديو الفعلية لأن FFmpeg غير متوفر في بيئة الفحص.
- بعد استكمال إعدادات البيئة المحلية: 22 اختبار PDF ورفع وعرض ومعالجة نجحوا، مع 97 assertion.
- اختبار التحويل الفعلي: عاملان متزامنان، عشرة ملفات سليمة وملف خربان؛ 40 صورة JPEG، دون تكرار العمل، ونجاح عرض الصفحة الرابعة بعلامة مائية.
- `git diff --check` نجح.
- جدولة PDF موجودة كل دقيقة وتعمل بالخلفية، مع قفل مستقل يمنع التزامن.

## إعداد النسخة المحلية

- قاعدة البيانات المضبوطة محلية على `127.0.0.1`؛ نُفّذ migration تتبّع معالجة PDF وحده. تم التحقق من بقاء قيم وعدد سجلات الملفات القديمة كما كانت.
- لم توجد ملفات PDF محلية؛ وُلد مفتاح PDF محلي مستقل داخل `.env` غير المتتبع بـ Git. لم يُنسخ مفتاح السيرفر، ولم تتغير إعدادات الحسابات أو قاعدة البيانات أو الفيديو السابقة.
- ضُبط مسارا Poppler المحليان داخل `.env` فقط. أمثلة الإعدادات في `.env.example` محمولة بلا مسارات خاصة بالجهاز أو مفاتيح فعلية.
- PHP CLI الموجود لا يحمّل GD افتراضياً؛ فحوص العرض شُغلت باستخدام `-d extension=gd`. لتجربة العرض على خادم التطوير يمكن تشغيل:

```powershell
C:\xampp\php\php.exe -d extension=gd artisan serve
```

فحوص المشروع:

```powershell
C:\xampp\php\php.exe -d extension=gd vendor/bin/phpunit
```

فحص عامل PDF الفعلي من المشروع، بعد ضبط Poppler في PATH أو متغيري البيئة `PDFTOPPM_BINARY` و`PDFINFO_BINARY`:

```powershell
C:\xampp\php\php.exe -d extension=gd tests/Integration/pdf-worker-smoke.php
```

الفحص الفعلي ينشئ قاعدة SQLite ومجلد تخزين مؤقتين داخل `storage/framework/testing`؛ لا يزرع بيانات في قاعدة المشروع الحقيقية. تقريره في `storage/framework/testing/pdf-worker-smoke-latest.json` غير المتتبع.

## Git

التغييرات موجودة في working tree للمشروع الأساسي لتراجعها وترفعها بنفسك. الفرع وHEAD بقيا كما كانا عند بدء المهمة. ملفات `.env` والمخرجات والنسخ الاحتياطية الحساسة ليست ضمن تغييرات Git.
