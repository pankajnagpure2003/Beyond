<?php

return [
    'name' => 'مشخصات محصول',
    'product_specification' => 'مشخصات محصول',
    'import' => [
        'name' => 'درون‌ریزی مشخصات محصول',
        'description' => 'درون‌ریزی گروهی مشخصات محصول با آپلود یک فایل CSV/اکسل.',
        'done_message' => 'مشخصات :count محصول با موفقیت به‌روزرسانی شد.',
        'rules' => [
            'name' => 'نام محصول الزامی است و باید با یک محصول والد موجود مطابقت داشته باشد.',
            'specification_table' => 'نام جدول مشخصات باید با یک جدول مشخصات موجود مطابقت داشته باشد.',
            'specifications' => 'مشخصات باید به فرمت «نام:مقدار» و جدا‌شده با «|» باشد.',
            'specifications_locale' => 'مشخصات برای :locale باید به فرمت «نام:مقدار» و جدا‌شده با «|» باشد.',
        ],
    ],
    'export' => [
        'description' => 'خروجی گرفتن مشخصات محصول به یک فایل CSV/اکسل.',
    ],
    'specification_groups' => [
        'title' => 'گروه‌های مشخصات',
        'menu_name' => 'گروه‌ها',
        'create' => [
            'title' => 'ایجاد گروه مشخصات',
        ],
        'edit' => [
            'title' => 'ویرایش گروه مشخصات ":name"',
        ],
    ],
    'specification_attributes' => [
        'title' => 'ویژگی‌های مشخصات',
        'menu_name' => 'ویژگی‌ها',
        'group' => 'گروه مرتبط',
        'group_placeholder' => 'یک گروه انتخاب کنید',
        'name_placeholder' => 'نام ویژگی را وارد کنید',
        'type' => 'نوع فیلد',
        'type_placeholder' => 'نوع فیلد را انتخاب کنید',
        'default_value' => 'مقدار پیش‌فرض',
        'default_value_placeholder' => 'مقدار پیش‌فرض را وارد کنید (اختیاری)',
        'options' => [
            'heading' => 'گزینه‌ها',
            'add' => [
                'label' => 'افزودن گزینه جدید',
            ],
        ],
        'create' => [
            'title' => 'ایجاد ویژگی مشخصات',
        ],
        'edit' => [
            'title' => 'ویرایش ویژگی مشخصات ":name"',
        ],
    ],
    'specification_tables' => [
        'title' => 'جداول مشخصات',
        'menu_name' => 'جداول',
        'create' => [
            'title' => 'ایجاد جدول مشخصات',
        ],
        'edit' => [
            'title' => 'ویرایش جدول مشخصات ":name"',
        ],
        'fields' => [
            'groups' => 'گروه‌هایی را برای نمایش در این جدول انتخاب کنید',
            'name' => 'نام گروه',
            'assigned_groups' => 'گروه‌های تخصیص داده شده',
            'sorting' => 'مرتب‌سازی',
        ],
    ],
    'product' => [
        'specification_table' => [
            'options' => 'گزینه‌ها',
            'title' => 'جدول مشخصات',
            'select_none' => 'هیچ',
            'description' => 'جدول مشخصات را برای نمایش در این محصول انتخاب کنید',
            'group' => 'گروه',
            'attribute' => 'ویژگی',
            'value' => 'مقدار ویژگی',
            'hide' => 'مخفی',
            'sorting' => 'مرتب‌سازی',
            'enter_value' => 'مقدار را وارد کنید',
            'enter_translation' => 'ترجمه را وارد کنید',
            'not_set' => 'تنظیم نشده',
        ],
    ],
    'enums' => [
        'field_types' => [
            'text' => 'متن',
            'textarea' => 'متن بلند',
            'select' => 'انتخاب',
            'checkbox' => 'چک‌باکس',
            'radio' => 'رادیو',
        ],
    ],
];
