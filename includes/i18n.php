<?php

/**
 * Minimal i18n: English / Persian translations with a user-selectable
 * language (stored in a cookie) and RTL support for Persian.
 */

const APP_LANGUAGES = ['en', 'fa'];

const TRANSLATIONS = [
    'en' => [
        // Nav / shared
        'nav_products' => 'Products',
        'nav_batch' => 'Batch',
        'nav_logs' => 'Logs',
        'nav_admin' => 'Admin',
        'nav_settings' => 'Settings',
        'nav_logout' => 'Logout',
        'site_label' => 'Site',
        'site_switch' => 'Switch',
        'loading' => 'Loading...',
        'cancel' => 'Cancel',
        'save' => 'Save',
        'delete' => 'Delete',
        'language' => 'Language',
        'lang_en' => 'English',
        'lang_fa' => 'Persian',

        // Login / verify
        'app_name' => 'Product Manager',
        'sign_in_title' => 'Sign in · Product Manager',
        'sign_in_heading' => 'Sign in with your mobile number',
        'mobile_number' => 'Mobile number',
        'send_code' => 'Send code',
        'sending' => 'Sending...',
        'access_limited' => 'Access is limited to authorized admins and shop managers.',
        'verify_title' => 'Verify code · Product Manager',
        'verify_heading' => 'Enter verification code',
        'verify_sent_to' => 'We sent a code via SMS to',
        'verification_code' => 'Verification code',
        'verify_and_sign_in' => 'Verify & sign in',
        'verifying' => 'Verifying...',
        'use_different_number' => 'Use a different number',

        // Install
        'install_title' => 'Initial setup · Product Manager',
        'install_heading' => 'Initial setup',
        'install_subheading' => 'Create the first superadmin account',
        'install_your_number' => 'Your mobile number',
        'install_number_help' => "You'll use this number to sign in with an SMS code.",
        'install_your_name' => 'Your name',
        'optional' => 'Optional',
        'install_kavenegar_heading' => 'Kavenegar SMS (optional)',
        'install_kavenegar_help' => 'Needed to send login codes. You can also set this later from Admin → Settings.',
        'kavenegar_api_key_label' => 'Kavenegar API key',
        'kavenegar_template_label' => 'Kavenegar template name',
        'install_submit' => 'Create superadmin account',
        'install_footer' => 'This page is only available until the first superadmin account is created.',

        // Products
        'products_title' => 'Products · Product Manager',
        'products_heading' => 'Products',
        'select' => 'Select',
        'search_placeholder' => 'Search by name or SKU',
        'filters' => 'Filters',
        'load_more' => 'Load more',
        'no_products_found' => 'No products found.',
        'category' => 'Category',
        'all_categories' => 'All categories',
        'stock_status' => 'Stock status',
        'all' => 'All',
        'in_stock' => 'In stock',
        'out_of_stock' => 'Out of stock',
        'on_backorder' => 'On backorder',
        'backorder' => 'Backorder',
        'unknown' => 'Unknown',
        'price_range' => 'Price range',
        'min' => 'Min',
        'max' => 'Max',
        'on_sale_only' => 'On sale only',
        'sort_by' => 'Sort by',
        'sort_newest' => 'Newest first',
        'sort_title_asc' => 'Name (A-Z)',
        'sort_title_desc' => 'Name (Z-A)',
        'sort_price_asc' => 'Price (low to high)',
        'sort_price_desc' => 'Price (high to low)',
        'reset' => 'Reset',
        'apply' => 'Apply',
        'selected_count' => 'selected',
        'batch_actions' => 'Batch actions',

        // Batch
        'batch_title' => 'Batch actions · Product Manager',
        'no_selection' => 'No products selected.',
        'select_products' => 'Select products',
        'products_selected' => 'products selected',
        'price_adjustment' => 'Price adjustment',
        'change_percent' => 'Change (%)',
        'price_change_help' => 'Increase or decrease price by a percentage.',
        'apply_to' => 'Apply to',
        'regular_price' => 'Regular price',
        'sale_price' => 'Sale price',
        'rounding' => 'Rounding',
        'rounding_none' => 'No rounding (2 decimals)',
        'rounding_nearest' => 'Round to nearest whole number',
        'rounding_up' => 'Round up',
        'rounding_down' => 'Round down',
        'rounding_step' => 'Round to nearest step...',
        'rounding_ending' => 'Round to ending (e.g. .99)...',
        'rounding_step_label' => 'Round to nearest (e.g. 1000)',
        'rounding_ending_label' => 'Ending decimal (e.g. 0.99)',
        'preview_changes' => 'Preview changes',
        'stock_adjustment' => 'Stock adjustment',
        'action' => 'Action',
        'stock_action_delta' => 'Increase / decrease by amount',
        'stock_action_set' => 'Set exact quantity',
        'stock_value_delta_label' => 'Amount (use negative to decrease)',
        'stock_value_set_label' => 'New stock quantity',
        'preview' => 'Preview',
        'apply_changes' => 'Apply changes',
        'applying' => 'Applying...',
        'batch_update_applied' => 'Batch update applied',
        'select_one_price_field' => 'Select at least one price field to update',
        'no_changes_to_apply' => 'No changes to apply.',

        // Product edit
        'edit_product' => 'Edit product',
        'new_product' => 'New product',
        'name' => 'Name',
        'sku' => 'SKU',
        'pricing' => 'Pricing',
        'inventory' => 'Inventory',
        'stock_quantity' => 'Stock quantity',
        'categories' => 'Categories',
        'images_urls' => 'Images (URLs)',
        'add_image_url' => '+ Add image URL',
        'description' => 'Description',
        'short_description' => 'Short description',
        'status' => 'Status',
        'status_publish' => 'Published',
        'status_draft' => 'Draft',
        'status_private' => 'Private',
        'saving' => 'Saving...',
        'delete_product_confirm' => 'Delete this product permanently?',
        'product_saved' => 'Product saved',
        'product_deleted' => 'Product deleted',

        // Sites
        'select_site_title' => 'Select site · Product Manager',
        'select_a_site' => 'Select a site',
        'no_sites_assigned' => 'No sites are assigned to your account yet.',
        'add_a_site' => 'Add a site',
        'ask_superadmin_for_site' => 'Ask a superadmin to assign you to a site.',
        'current' => 'Current',

        // Admin: users
        'users' => 'Users',
        'add_btn' => '+ Add',
        'name_optional' => 'Optional',
        'role' => 'Role',
        'role_admin' => 'Admin',
        'role_shop_manager' => 'Shop manager',
        'role_superadmin' => 'Superadmin',
        'assigned_sites' => 'Assigned sites',
        'superadmin_all_sites_note' => 'Superadmins automatically have access to all sites.',
        'add_user' => 'Add user',
        'edit_user' => 'Edit user',
        'user_saved' => 'User saved',
        'user_deleted' => 'User deleted',
        'delete_user_confirm' => 'Delete this user?',
        'no_sites_available' => 'No sites available yet.',
        'manage_users_title' => 'Manage users · Product Manager',

        // Admin: sites
        'site_name' => 'Site name',
        'store_url' => 'Store URL',
        'consumer_key' => 'Consumer key',
        'consumer_secret' => 'Consumer secret',
        'verify_ssl' => 'Verify SSL certificate',
        'add_site' => 'Add site',
        'edit_site' => 'Edit site',
        'site_saved' => 'Site saved',
        'site_deleted' => 'Site deleted',
        'delete_site_confirm' => 'Delete this site? Users assigned to it will lose access.',
        'current_value_leave_blank' => 'Current: %s. Leave blank to keep.',
        'manage_sites_title' => 'Manage sites · Product Manager',
        'sites' => 'Sites',
        'wp_api_heading' => 'WordPress REST API (optional)',
        'wp_api_help' => 'Needed for uploading images and for custom (ACF) taxonomies. Create an Application Password for a WordPress admin user under Users → Profile → Application Passwords.',
        'wp_username' => 'WordPress username',
        'wp_app_password' => 'Application password',

        // Admin: settings
        'settings' => 'Settings',
        'save_settings' => 'Save settings',
        'settings_saved' => 'Settings saved',
        'settings_title' => 'Settings · Product Manager',
        'leave_blank_to_keep' => 'Leave blank to keep current value',
        'not_set' => 'Not set',

        'group_kavenegar' => 'Kavenegar (SMS OTP)',
        'group_otp' => 'Login codes (OTP)',
        'group_session' => 'Session',
        'group_superadmins' => 'Superadmins',

        // Account settings (all users)
        'account_settings_title' => 'Settings · Product Manager',
        'account_settings_heading' => 'Settings',
        'account_section' => 'Account',
        'language_section' => 'Language',
        'language_section_help' => 'Choose the language used across the app.',

        'kavenegar_api_key_help' => 'API key from your Kavenegar account, used to send login codes.',
        'kavenegar_template_help' => 'Verify Lookup template name configured in your Kavenegar panel.',
        'otp_length_label' => 'OTP code length',
        'otp_length_help' => 'Number of digits in each login code.',
        'otp_expiry_label' => 'OTP expiry (seconds)',
        'otp_expiry_help' => 'How long a login code remains valid.',
        'otp_max_per_window_label' => 'Max OTP requests per window',
        'otp_max_per_window_help' => 'Rate limit: max codes a phone number can request within the window below.',
        'otp_window_label' => 'OTP rate limit window (seconds)',
        'otp_window_help' => 'Time window used for the rate limit above.',
        'otp_max_verify_attempts_label' => 'Max OTP verification attempts',
        'otp_max_verify_attempts_help' => 'Lockout: max failed code verification attempts a phone number gets within the rate limit window above before further attempts are blocked.',
        'session_name_label' => 'Session cookie name',
        'session_name_help' => 'Name of the PHP session cookie.',
        'session_lifetime_label' => 'Session lifetime (seconds)',
        'session_lifetime_help' => 'How long a login session lasts (default 28800 = 8 hours).',
        'superadmin_phones_label' => 'Bootstrap superadmin phone numbers',
        'superadmin_phones_help' => 'Comma-separated phone numbers (e.g. 09121234567, 09129876543) that are automatically granted the superadmin role the first time they log in.',
        'group_ai' => 'AI (ArvanCloud)',

        // Currency
        'currency_unit' => 'Toman',

        // AI & media
        'ai_not_configured' => 'AI is not configured. Set an API key in Admin → Settings.',
        'ai_invalid_response' => 'AI returned an unexpected response. Please try again.',
        'wp_credentials_missing' => 'This site has no WordPress API credentials configured. Ask a superadmin to add them in Admin → Sites.',
        'generate_with_ai' => 'Generate with AI',
        'generating' => 'Generating...',
        'ai_description_generated' => 'Description generated. Review and edit before saving.',
        'long_description' => 'Long description',
        'upload_image' => 'Upload image',
        'uploading' => 'Uploading...',
        'image_edit_options' => 'Image edit options (applied on upload)',
        'image_edit_white_bg' => 'Add white background',
        'image_edit_enhance' => 'Increase quality',
        'image_edit_resize' => 'Resize to frame 1080×1080',
        'custom_taxonomies' => 'Additional attributes',
        'no_custom_taxonomies' => 'No custom attributes available for this site.',

        // Product wizard
        'product_wizard_title' => 'Add product · Product Manager',
        'product_wizard_heading' => 'Add product',
        'add_product' => 'Add product',
        'wizard_step' => 'Step %s of %s',
        'wizard_step_basic' => 'Basic info',
        'wizard_step_pricing' => 'Pricing',
        'wizard_step_inventory' => 'Inventory',
        'wizard_step_categories' => 'Categories',
        'wizard_step_images' => 'Images',
        'wizard_step_description' => 'Description',
        'wizard_step_review' => 'Review',
        'wizard_next' => 'Next',
        'wizard_back' => 'Back',
        'wizard_publish' => 'Publish product',
        'wizard_publishing' => 'Publishing...',
        'wizard_review_help' => 'Review the details below, then publish.',
        'name_required' => 'Name is required.',

        // Activity logs
        'logs_title' => 'Activity logs · Product Manager',
        'logs_heading' => 'Activity logs',
        'my_logs' => 'My activity',
        'site_logs' => 'All users',
        'system_logs' => 'System',
        'no_logs' => 'No activity yet.',
        'undo' => 'Undo',
        'undo_applied' => 'Change undone.',
        'undo_failed' => 'Could not undo this change.',
        'undo_expired' => 'This change can no longer be undone.',
        'seconds_short' => 's',

        // Log message templates
        'log_stock_changed' => 'Updated stock for "%s" from %s to %s',
        'log_product_updated' => 'Updated product "%s"',
        'log_product_created' => 'Created product "%s"',
        'log_product_deleted' => 'Deleted product "%s"',
        'log_batch_price_applied' => 'Applied a price change to %s product(s)',
        'log_batch_stock_applied' => 'Applied a stock change to %s product(s)',
        'log_undo_applied' => 'Undid a previous change: %s',
        'log_user_created' => 'Created user "%s"',
        'log_user_updated' => 'Updated user "%s"',
        'log_user_deleted' => 'Deleted user "%s"',
        'log_site_created' => 'Added site "%s"',
        'log_site_updated' => 'Updated site "%s"',
        'log_site_deleted' => 'Deleted site "%s"',
        'log_settings_updated' => 'Updated app settings',
    ],

    'fa' => [
        // Nav / shared
        'nav_products' => 'محصولات',
        'nav_batch' => 'دسته‌ای',
        'nav_logs' => 'گزارش‌ها',
        'nav_admin' => 'مدیریت',
        'nav_settings' => 'تنظیمات',
        'nav_logout' => 'خروج',
        'site_label' => 'فروشگاه',
        'site_switch' => 'تغییر',
        'loading' => 'در حال بارگذاری...',
        'cancel' => 'انصراف',
        'save' => 'ذخیره',
        'delete' => 'حذف',
        'language' => 'زبان',
        'lang_en' => 'English',
        'lang_fa' => 'فارسی',

        // Login / verify
        'app_name' => 'مدیریت محصولات',
        'sign_in_title' => 'ورود · مدیریت محصولات',
        'sign_in_heading' => 'با شماره موبایل خود وارد شوید',
        'mobile_number' => 'شماره موبایل',
        'send_code' => 'ارسال کد',
        'sending' => 'در حال ارسال...',
        'access_limited' => 'دسترسی فقط برای مدیران و مسئولان مجاز فروشگاه است.',
        'verify_title' => 'تایید کد · مدیریت محصولات',
        'verify_heading' => 'کد تایید را وارد کنید',
        'verify_sent_to' => 'کد تایید برای این شماره ارسال شد:',
        'verification_code' => 'کد تایید',
        'verify_and_sign_in' => 'تایید و ورود',
        'verifying' => 'در حال بررسی...',
        'use_different_number' => 'استفاده از شماره دیگر',

        // Install
        'install_title' => 'راه‌اندازی اولیه · مدیریت محصولات',
        'install_heading' => 'راه‌اندازی اولیه',
        'install_subheading' => 'ایجاد حساب مدیر کل اول',
        'install_your_number' => 'شماره موبایل شما',
        'install_number_help' => 'برای ورود با کد پیامکی از این شماره استفاده خواهید کرد.',
        'install_your_name' => 'نام شما',
        'optional' => 'اختیاری',
        'install_kavenegar_heading' => 'پیامک کاوه‌نگار (اختیاری)',
        'install_kavenegar_help' => 'برای ارسال کد ورود لازم است. می‌توانید بعداً از مدیریت ← تنظیمات آن را تعیین کنید.',
        'kavenegar_api_key_label' => 'کلید API کاوه‌نگار',
        'kavenegar_template_label' => 'نام الگوی کاوه‌نگار',
        'install_submit' => 'ایجاد حساب مدیر کل',
        'install_footer' => 'این صفحه فقط تا زمان ایجاد اولین حساب مدیر کل در دسترس است.',

        // Products
        'products_title' => 'محصولات · مدیریت محصولات',
        'products_heading' => 'محصولات',
        'select' => 'انتخاب',
        'search_placeholder' => 'جستجو بر اساس نام یا SKU',
        'filters' => 'فیلترها',
        'load_more' => 'موارد بیشتر',
        'no_products_found' => 'محصولی یافت نشد.',
        'category' => 'دسته‌بندی',
        'all_categories' => 'همه دسته‌ها',
        'stock_status' => 'وضعیت انبار',
        'all' => 'همه',
        'in_stock' => 'موجود',
        'out_of_stock' => 'ناموجود',
        'on_backorder' => 'پیش‌سفارش',
        'backorder' => 'پیش‌سفارش',
        'unknown' => 'نامشخص',
        'price_range' => 'محدوده قیمت',
        'min' => 'حداقل',
        'max' => 'حداکثر',
        'on_sale_only' => 'فقط حراجی‌ها',
        'sort_by' => 'مرتب‌سازی بر اساس',
        'sort_newest' => 'جدیدترین',
        'sort_title_asc' => 'نام (الف تا ی)',
        'sort_title_desc' => 'نام (ی تا الف)',
        'sort_price_asc' => 'قیمت (کم به زیاد)',
        'sort_price_desc' => 'قیمت (زیاد به کم)',
        'reset' => 'بازنشانی',
        'apply' => 'اعمال',
        'selected_count' => 'انتخاب شده',
        'batch_actions' => 'عملیات دسته‌ای',

        // Batch
        'batch_title' => 'عملیات دسته‌ای · مدیریت محصولات',
        'no_selection' => 'هیچ محصولی انتخاب نشده است.',
        'select_products' => 'انتخاب محصولات',
        'products_selected' => 'محصول انتخاب شده',
        'price_adjustment' => 'تغییر قیمت',
        'change_percent' => 'تغییر (٪)',
        'price_change_help' => 'افزایش یا کاهش قیمت بر اساس درصد.',
        'apply_to' => 'اعمال روی',
        'regular_price' => 'قیمت اصلی',
        'sale_price' => 'قیمت حراجی',
        'rounding' => 'روش گرد کردن',
        'rounding_none' => 'بدون گرد کردن (۲ رقم اعشار)',
        'rounding_nearest' => 'گرد کردن به نزدیک‌ترین عدد صحیح',
        'rounding_up' => 'گرد کردن به بالا',
        'rounding_down' => 'گرد کردن به پایین',
        'rounding_step' => 'گرد کردن به نزدیک‌ترین پله...',
        'rounding_ending' => 'گرد کردن به پایانه (مثلاً .99)...',
        'rounding_step_label' => 'گرد کردن به نزدیک‌ترین (مثلاً ۱۰۰۰)',
        'rounding_ending_label' => 'اعشار پایانی (مثلاً ۰.۹۹)',
        'preview_changes' => 'پیش‌نمایش تغییرات',
        'stock_adjustment' => 'تغییر موجودی',
        'action' => 'عملیات',
        'stock_action_delta' => 'افزایش / کاهش به مقدار',
        'stock_action_set' => 'تعیین مقدار دقیق',
        'stock_value_delta_label' => 'مقدار (برای کاهش، عدد منفی وارد کنید)',
        'stock_value_set_label' => 'موجودی جدید',
        'preview' => 'پیش‌نمایش',
        'apply_changes' => 'اعمال تغییرات',
        'applying' => 'در حال اعمال...',
        'batch_update_applied' => 'به‌روزرسانی دسته‌ای اعمال شد',
        'select_one_price_field' => 'حداقل یک فیلد قیمت را برای به‌روزرسانی انتخاب کنید',
        'no_changes_to_apply' => 'تغییری برای اعمال وجود ندارد.',

        // Product edit
        'edit_product' => 'ویرایش محصول',
        'new_product' => 'محصول جدید',
        'name' => 'نام',
        'sku' => 'شناسه کالا (SKU)',
        'pricing' => 'قیمت‌گذاری',
        'inventory' => 'انبار',
        'stock_quantity' => 'موجودی انبار',
        'categories' => 'دسته‌بندی‌ها',
        'images_urls' => 'تصاویر (لینک)',
        'add_image_url' => '+ افزودن لینک تصویر',
        'description' => 'توضیحات',
        'short_description' => 'توضیح کوتاه',
        'status' => 'وضعیت',
        'status_publish' => 'منتشر شده',
        'status_draft' => 'پیش‌نویس',
        'status_private' => 'خصوصی',
        'saving' => 'در حال ذخیره...',
        'delete_product_confirm' => 'این محصول برای همیشه حذف شود؟',
        'product_saved' => 'محصول ذخیره شد',
        'product_deleted' => 'محصول حذف شد',

        // Sites
        'select_site_title' => 'انتخاب فروشگاه · مدیریت محصولات',
        'select_a_site' => 'انتخاب فروشگاه',
        'no_sites_assigned' => 'هنوز فروشگاهی به حساب شما اختصاص داده نشده است.',
        'add_a_site' => 'افزودن فروشگاه',
        'ask_superadmin_for_site' => 'از مدیر کل بخواهید فروشگاهی به شما اختصاص دهد.',
        'current' => 'فعلی',

        // Admin: users
        'users' => 'کاربران',
        'add_btn' => '+ افزودن',
        'name_optional' => 'اختیاری',
        'role' => 'نقش',
        'role_admin' => 'مدیر',
        'role_shop_manager' => 'مسئول فروشگاه',
        'role_superadmin' => 'مدیر کل',
        'assigned_sites' => 'فروشگاه‌های اختصاص‌یافته',
        'superadmin_all_sites_note' => 'مدیران کل به‌طور خودکار به همه فروشگاه‌ها دسترسی دارند.',
        'add_user' => 'افزودن کاربر',
        'edit_user' => 'ویرایش کاربر',
        'user_saved' => 'کاربر ذخیره شد',
        'user_deleted' => 'کاربر حذف شد',
        'delete_user_confirm' => 'این کاربر حذف شود؟',
        'no_sites_available' => 'هنوز فروشگاهی ثبت نشده است.',
        'manage_users_title' => 'مدیریت کاربران · مدیریت محصولات',

        // Admin: sites
        'site_name' => 'نام فروشگاه',
        'store_url' => 'آدرس فروشگاه',
        'consumer_key' => 'کلید مصرف‌کننده',
        'consumer_secret' => 'رمز مصرف‌کننده',
        'verify_ssl' => 'بررسی گواهی SSL',
        'add_site' => 'افزودن فروشگاه',
        'edit_site' => 'ویرایش فروشگاه',
        'site_saved' => 'فروشگاه ذخیره شد',
        'site_deleted' => 'فروشگاه حذف شد',
        'delete_site_confirm' => 'این فروشگاه حذف شود؟ کاربران مرتبط دسترسی خود را از دست می‌دهند.',
        'current_value_leave_blank' => 'مقدار فعلی: %s. برای حفظ مقدار، خالی بگذارید.',
        'manage_sites_title' => 'مدیریت فروشگاه‌ها · مدیریت محصولات',
        'sites' => 'فروشگاه‌ها',
        'wp_api_heading' => 'API وردپرس (اختیاری)',
        'wp_api_help' => 'برای آپلود تصاویر و ویژگی‌های سفارشی (ACF) لازم است. از مسیر کاربران ← پروفایل ← رمزهای عبور برنامه، یک رمز عبور برنامه برای یک کاربر مدیر وردپرس بسازید.',
        'wp_username' => 'نام کاربری وردپرس',
        'wp_app_password' => 'رمز عبور برنامه',

        // Admin: settings
        'settings' => 'تنظیمات',
        'save_settings' => 'ذخیره تنظیمات',
        'settings_saved' => 'تنظیمات ذخیره شد',
        'settings_title' => 'تنظیمات · مدیریت محصولات',
        'leave_blank_to_keep' => 'برای حفظ مقدار فعلی، خالی بگذارید',
        'not_set' => 'تعیین نشده',

        'group_kavenegar' => 'پیامک کاوه‌نگار (کد یکبار مصرف)',
        'group_otp' => 'کدهای ورود (OTP)',
        'group_session' => 'نشست',
        'group_superadmins' => 'مدیران کل',

        // Account settings (all users)
        'account_settings_title' => 'تنظیمات · مدیریت محصولات',
        'account_settings_heading' => 'تنظیمات',
        'account_section' => 'حساب کاربری',
        'language_section' => 'زبان',
        'language_section_help' => 'زبان نمایش برنامه را انتخاب کنید.',

        'kavenegar_api_key_help' => 'کلید API حساب کاوه‌نگار شما، برای ارسال کد ورود استفاده می‌شود.',
        'kavenegar_template_help' => 'نام الگوی Verify Lookup که در پنل کاوه‌نگار تنظیم کرده‌اید.',
        'otp_length_label' => 'طول کد یکبار مصرف',
        'otp_length_help' => 'تعداد ارقام هر کد ورود.',
        'otp_expiry_label' => 'انقضای کد (ثانیه)',
        'otp_expiry_help' => 'مدت زمان اعتبار کد ورود.',
        'otp_max_per_window_label' => 'حداکثر درخواست کد در هر بازه',
        'otp_max_per_window_help' => 'محدودیت نرخ: حداکثر تعداد کدهایی که یک شماره می‌تواند در بازه زیر درخواست کند.',
        'otp_window_label' => 'بازه محدودیت درخواست کد (ثانیه)',
        'otp_window_help' => 'بازه زمانی استفاده‌شده برای محدودیت بالا.',
        'otp_max_verify_attempts_label' => 'حداکثر تلاش برای تایید کد',
        'otp_max_verify_attempts_help' => 'محدودیت تلاش: حداکثر تعداد تلاش‌های ناموفق برای تایید کد که یک شماره در بازه محدودیت بالا می‌تواند داشته باشد، پیش از مسدود شدن تلاش‌های بعدی.',
        'session_name_label' => 'نام کوکی نشست',
        'session_name_help' => 'نام کوکی نشست PHP.',
        'session_lifetime_label' => 'مدت نشست (ثانیه)',
        'session_lifetime_help' => 'مدت زمان اعتبار نشست ورود (پیش‌فرض ۲۸۸۰۰ = ۸ ساعت).',
        'superadmin_phones_label' => 'شماره‌های مدیر کل اولیه',
        'superadmin_phones_help' => 'شماره‌های موبایل جداشده با کاما (مثلاً 09121234567, 09129876543) که در اولین ورود به‌طور خودکار نقش مدیر کل می‌گیرند.',
        'group_ai' => 'هوش مصنوعی (آروان کلاد)',

        // Currency
        'currency_unit' => 'تومان',

        // AI & media
        'ai_not_configured' => 'هوش مصنوعی تنظیم نشده است. کلید API را در مدیریت ← تنظیمات وارد کنید.',
        'ai_invalid_response' => 'پاسخ هوش مصنوعی نامعتبر بود. دوباره تلاش کنید.',
        'wp_credentials_missing' => 'برای این فروشگاه اطلاعات API وردپرس تنظیم نشده است. از مدیر کل بخواهید آن را در مدیریت ← فروشگاه‌ها اضافه کند.',
        'generate_with_ai' => 'تولید با هوش مصنوعی',
        'generating' => 'در حال تولید...',
        'ai_description_generated' => 'توضیحات تولید شد. پیش از ذخیره بازبینی کنید.',
        'long_description' => 'توضیحات کامل',
        'upload_image' => 'آپلود تصویر',
        'uploading' => 'در حال آپلود...',
        'image_edit_options' => 'گزینه‌های ویرایش تصویر (هنگام آپلود اعمال می‌شود)',
        'image_edit_white_bg' => 'افزودن پس‌زمینه سفید',
        'image_edit_enhance' => 'افزایش کیفیت',
        'image_edit_resize' => 'تغییر اندازه به قاب ۱۰۸۰×۱۰۸۰',
        'custom_taxonomies' => 'ویژگی‌های اضافی',
        'no_custom_taxonomies' => 'هیچ ویژگی سفارشی برای این فروشگاه موجود نیست.',

        // Product wizard
        'product_wizard_title' => 'افزودن محصول · مدیریت محصولات',
        'product_wizard_heading' => 'افزودن محصول',
        'add_product' => 'افزودن محصول',
        'wizard_step' => 'مرحله %s از %s',
        'wizard_step_basic' => 'اطلاعات پایه',
        'wizard_step_pricing' => 'قیمت‌گذاری',
        'wizard_step_inventory' => 'انبار',
        'wizard_step_categories' => 'دسته‌بندی‌ها',
        'wizard_step_images' => 'تصاویر',
        'wizard_step_description' => 'توضیحات',
        'wizard_step_review' => 'بازبینی',
        'wizard_next' => 'بعدی',
        'wizard_back' => 'قبلی',
        'wizard_publish' => 'انتشار محصول',
        'wizard_publishing' => 'در حال انتشار...',
        'wizard_review_help' => 'موارد زیر را بازبینی کرده و سپس منتشر کنید.',
        'name_required' => 'نام الزامی است.',

        // Activity logs
        'logs_title' => 'گزارش فعالیت‌ها · مدیریت محصولات',
        'logs_heading' => 'گزارش فعالیت‌ها',
        'my_logs' => 'فعالیت‌های من',
        'site_logs' => 'همه کاربران',
        'system_logs' => 'سیستم',
        'no_logs' => 'هنوز فعالیتی ثبت نشده است.',
        'undo' => 'واگرد',
        'undo_applied' => 'تغییر بازگردانده شد.',
        'undo_failed' => 'امکان بازگرداندن این تغییر وجود ندارد.',
        'undo_expired' => 'دیگر نمی‌توان این تغییر را بازگرداند.',
        'seconds_short' => 'ث',

        // Log message templates
        'log_stock_changed' => 'موجودی "%s" از %s به %s تغییر کرد',
        'log_product_updated' => 'محصول "%s" به‌روزرسانی شد',
        'log_product_created' => 'محصول "%s" ایجاد شد',
        'log_product_deleted' => 'محصول "%s" حذف شد',
        'log_batch_price_applied' => 'تغییر قیمت روی %s محصول اعمال شد',
        'log_batch_stock_applied' => 'تغییر موجودی روی %s محصول اعمال شد',
        'log_undo_applied' => 'یک تغییر قبلی بازگردانده شد: %s',
        'log_user_created' => 'کاربر "%s" ایجاد شد',
        'log_user_updated' => 'کاربر "%s" به‌روزرسانی شد',
        'log_user_deleted' => 'کاربر "%s" حذف شد',
        'log_site_created' => 'فروشگاه "%s" اضافه شد',
        'log_site_updated' => 'فروشگاه "%s" به‌روزرسانی شد',
        'log_site_deleted' => 'فروشگاه "%s" حذف شد',
        'log_settings_updated' => 'تنظیمات برنامه به‌روزرسانی شد',
    ],
];

/**
 * Returns the current UI language ('en' or 'fa'), based on the "lang" cookie.
 */
function current_lang(): string
{
    $lang = $_COOKIE['lang'] ?? 'en';
    return in_array($lang, APP_LANGUAGES, true) ? $lang : 'en';
}

/**
 * Sets the "lang" cookie for one year.
 */
function set_lang(string $lang): void
{
    if (!in_array($lang, APP_LANGUAGES, true)) {
        return;
    }
    setcookie('lang', $lang, [
        'expires' => time() + 365 * 24 * 60 * 60,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']),
        'httponly' => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Returns true if the current language is right-to-left.
 */
function is_rtl(): bool
{
    return current_lang() === 'fa';
}

/**
 * Translates $key for the current language, with optional sprintf-style
 * placeholders. Falls back to English, then to the key itself.
 */
function t(string $key, ...$args): string
{
    $lang = current_lang();
    $value = TRANSLATIONS[$lang][$key] ?? TRANSLATIONS['en'][$key] ?? $key;

    return $args ? vsprintf($value, $args) : $value;
}

/**
 * Returns the `lang="..." dir="..."` attribute string for the <html> tag.
 */
function html_attrs(): string
{
    $lang = current_lang();
    $dir = is_rtl() ? 'rtl' : 'ltr';
    return sprintf('lang="%s" dir="%s"', htmlspecialchars($lang), $dir);
}
