<?php

return [
    'ui' => ['months' => [1 => 'كانون الثاني', 'شباط', 'آذار', 'نيسان', 'أيار', 'حزيران',
        'تموز', 'آب', 'أيلول', 'تشرين الأول', 'تشرين الثاني', 'كانون الأول']],
    'attendance' => [
        'dashboard_placeholder' => 'لوحة الحضور — قيد الإنشاء (المواصفة 505)',
        'title' => 'الحضور',
        'details_placeholder' => 'ستظهر تفاصيل الحضور هنا.',
        'welcome' => 'مطابقة الحضور والإجازات',
    ],
    'setup' => [
        'title' => 'أكمل إعداد شركتك',
        'languages' => 'لغات الواجهة',
        'done' => 'مكتمل',
        'fix' => 'أكمل الإعداد',
        'branch_missing' => 'أضف فرعاً واحداً على الأقل.',
        'jurisdiction_missing' => 'يحتاج فرع :branch إلى تحديد الاختصاص القضائي.',
        'languages_missing' => 'يجب تفعيل العربية والإنجليزية.',
    ],
    'search' => [
        'open_search' => 'البحث في النظام',
        'placeholder' => 'ابحث عن الموظفين',
        'shortcut' => 'Ctrl K',
        'employees' => 'الموظفون',
        'no_results' => 'لا توجد نتائج مطابقة.',
    ],
    'company' => [
        'arabic_english_required' => 'يجب تفعيل العربية والإنجليزية.',
    ],
    'employee' => [
        'gender_invalid' => 'يجب تحديد الجنس بذكر أو أنثى أو غير محدد.',
    ],
    'employee_history' => [
        'title' => 'التغييرات',
        'none' => 'لا توجد تغييرات مسجلة.',
        'empty' => 'فارغ',
        'system' => 'النظام',
        'rest_days_invalid' => 'يجب أن تكون أيام الراحة بين 1 و7.',
        'required_identity' => 'حقل الهوية هذا مطلوب.',
        'invalid_reference' => 'الموظف أو الفرع المحدد لا ينتمي إلى هذه الشركة.',
        'invalid_pattern' => 'اختر نمط عمل صالحاً.',
        'sources' => ['initial' => 'أولي', 'manual' => 'يدوي', 'import' => 'استيراد', 'chat' => 'محادثة'],
        'fields' => [
            'name' => 'الاسم', 'name_en' => 'الاسم بالإنجليزية', 'department' => 'القسم',
            'department_en' => 'القسم بالإنجليزية', 'position' => 'المنصب', 'position_en' => 'المنصب بالإنجليزية',
            'hire_date' => 'تاريخ التعيين', 'branch_id' => 'الفرع', 'email' => 'البريد الإلكتروني', 'gender' => 'الجنس',
            'work_pattern' => 'نمط العمل', 'rest_days' => 'أيام الراحة', 'schedule_start' => 'بداية الدوام',
            'schedule_end' => 'نهاية الدوام', 'manager_employee_id' => 'المدير', 'manager_name' => 'اسم المدير المبلغ عنه',
            'fallback_notification_email' => 'بريد الإشعارات البديل',
        ],
    ],
    'exit_review' => [
        'inactive' => 'يمكن إدخال الموظفين النشطين فقط في مراجعة المغادرة.',
        'already_confirmed' => 'لا يمكن إعادة فتح مغادرة مؤكدة.',
        'reason_required' => 'سبب الاستبعاد مطلوب.',
    ],
    'import' => [
        'file_empty_or_too_large' => 'الملف فارغ أو يتجاوز 2 ميغابايت.',
        'encoding_unreadable' => 'تعذر قراءة ترميز الملف.',
        'headers_or_separator_invalid' => 'ترويسات الملف ناقصة أو مكررة، أو تعذر تحديد الفاصل.',
        'row_limit' => 'الحد الأقصى 5,000 صف.',
        'no_employees' => 'الملف لا يحتوي على موظفين.',
    ],
    'employee_import' => [
        'wrong_column_count' => 'عدد الأعمدة غير صحيح',
        'field_required' => ':field مطلوب',
        'field_too_long' => ':field يتجاوز 255 حرفاً',
        'branch_unknown' => 'الفرع غير معروف',
        'branch_ambiguous' => 'اسم الفرع غير مميز',
        'hire_date_invalid' => 'تاريخ التعيين غير صالح',
        'email_invalid' => 'البريد الإلكتروني غير صالح',
        'gender_invalid' => 'الجنس غير صالح',
        'linked_user_permissions_unchanged' => 'صلاحيات المستخدم المرتبط لا تتغير',
        'duplicate_code' => 'رقم مكرر في الملف',
        'file_changed' => 'أعد المعاينة: الملف تغيّر.',
        'data_changed' => 'تغيّرت البيانات أثناء الاستيراد؛ أعد المعاينة',
        'fields' => [
            'name' => 'الاسم', 'department' => 'القسم', 'position' => 'المنصب',
            'hire_date' => 'تاريخ التعيين', 'branch' => 'الفرع',
        ],
    ],
    'locale' => [
        'arabic' => 'العربية',
    ],
    'user' => [
        'last_active_hr_required' => 'لا يمكن إزالة آخر مستخدم نشط للموارد البشرية في الشركة.',
    ],
];
