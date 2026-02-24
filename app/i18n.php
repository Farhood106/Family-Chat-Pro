<?php
// app/i18n.php

declare(strict_types=1);

function lang_dir(string $lang): string
{
    return $lang === 'fa' ? 'rtl' : 'ltr';
}

function t(string $key, string $lang): string
{
    static $fa = [
        'login' => 'ورود',
        'username' => 'نام کاربری',
        'password' => 'رمز عبور',
        'logout' => 'خروج',
        'direct' => 'چت خصوصی',
        'groups' => 'گروه‌ها',
        'send' => 'ارسال',
        'type_message' => 'پیام...',
        'settings' => 'تنظیمات',
        'admin_panel' => 'پنل ادمین',
        'profile' => 'پروفایل',
        'change_password' => 'تغییر رمز',
        'join' => 'عضویت',
        'leave' => 'خروج',
        'search' => 'جستجو',
        'deleted' => 'این پیام حذف شده است',
        'typing' => 'در حال تایپ...',
    ];
    static $en = [
        'login' => 'Login',
        'username' => 'Username',
        'password' => 'Password',
        'logout' => 'Logout',
        'direct' => 'Direct',
        'groups' => 'Groups',
        'send' => 'Send',
        'type_message' => 'Message...',
        'settings' => 'Settings',
        'admin_panel' => 'Admin Panel',
        'profile' => 'Profile',
        'change_password' => 'Change Password',
        'join' => 'Join',
        'leave' => 'Leave',
        'search' => 'Search',
        'deleted' => 'This message was deleted',
        'typing' => 'typing...',
    ];

    $dict = ($lang === 'fa') ? $fa : $en;
    return $dict[$key] ?? $key;
}
