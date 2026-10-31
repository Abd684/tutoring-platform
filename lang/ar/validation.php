<?php

return [
    'required' => 'حقل :attribute مطلوب.', 'required_if' => 'حقل :attribute مطلوب عند اختيار :value.',
    'string' => 'حقل :attribute يجب أن يكون نصاً.', 'integer' => 'حقل :attribute يجب أن يكون عدداً صحيحاً.',
    'numeric' => 'حقل :attribute يجب أن يكون رقماً.', 'email' => 'أدخل بريداً إلكترونياً صالحاً في :attribute.',
    'unique' => 'قيمة :attribute مستخدمة من قبل.', 'exists' => 'القيمة المحددة في :attribute غير موجودة أو غير متاحة.',
    'confirmed' => 'تأكيد :attribute غير متطابق.', 'prohibited' => 'لا يمكن تعديل أو إرسال :attribute في هذه العملية.',
    'date' => 'حقل :attribute يجب أن يكون تاريخاً صالحاً.', 'after' => 'حقل :attribute يجب أن يكون بعد :date.',
    'after_or_equal' => 'حقل :attribute يجب أن يكون بعد :date أو مساوياً له.', 'in' => 'القيمة المحددة في :attribute غير صالحة.',
    'array' => 'حقل :attribute يجب أن يكون قائمة.', 'boolean' => 'قيمة :attribute غير صالحة.', 'timezone' => 'المنطقة الزمنية المحددة غير صالحة.',
    'min' => ['string' => ':attribute يجب ألا يقل عن :min أحرف.', 'numeric' => ':attribute يجب ألا يقل عن :min.', 'file' => 'حجم :attribute أقل من الحد المسموح.', 'array' => 'عدد عناصر :attribute أقل من المسموح.'],
    'max' => ['string' => ':attribute يجب ألا يتجاوز :max حرفاً.', 'numeric' => ':attribute يجب ألا يتجاوز :max.', 'file' => 'حجم :attribute يجب ألا يتجاوز :max كيلوبايت.', 'array' => 'عدد عناصر :attribute يجب ألا يتجاوز :max.'],
    'between' => ['numeric' => ':attribute يجب أن يكون بين :min و:max.', 'string' => 'عدد أحرف :attribute يجب أن يكون بين :min و:max.'],
    'image' => ':attribute يجب أن يكون صورة صالحة.', 'file' => ':attribute يجب أن يكون ملفاً صالحاً.', 'mimes' => 'صيغة :attribute غير مدعومة.', 'regex' => 'صيغة :attribute غير صالحة.',
    'attributes' => ['email' => 'البريد الإلكتروني', 'password' => 'كلمة المرور', 'phone' => 'الهاتف', 'name' => 'الاسم', 'region_id' => 'المنطقة', 'school_id' => 'المدرسة',
        'course_id' => 'عرض المادة', 'group_id' => 'المجموعة', 'enrollment_id' => 'الاشتراك', 'teacher_id' => 'المدرس', 'subject_id' => 'المادة', 'capacity' => 'السعة', 'starts_at' => 'وقت البداية',
        'ends_at' => 'وقت النهاية', 'title' => 'العنوان', 'file' => 'الملف', 'price' => 'السعر', 'status' => 'الحالة', 'rejection_reason' => 'سبب الرفض', 'review_note' => 'ملاحظة المراجعة', 'transfer_reference' => 'مرجع التحويل',
        'account_id' => 'حساب شام كاش', 'qr_image' => 'صورة QR', 'questions' => 'الأسئلة', 'grant_reason' => 'سبب منح الوصول', 'scheduled_publish_at' => 'موعد النشر', 'platform_name' => 'اسم المنصة'],
];
