<?php

return [
    // Largest attachment accepted, in kilobytes (25 MB).
    'max_upload_kb' => (int) env('DAYLIST_MAX_UPLOAD_KB', 25600),

    // Filesystem disk for attachments (see config/filesystems.php).
    'attachments_disk' => env('DAYLIST_ATTACHMENTS_DISK', 'local'),

    // Soft-deleted rows older than this are removed for good, and clients
    // whose sync cursor is older must do a full resync.
    'purge_after_days' => (int) env('DAYLIST_PURGE_AFTER_DAYS', 30),

    // Rows updated this many seconds before the client's cursor are sent
    // again, so a row committed during the previous sync is never missed.
    'sync_overlap_seconds' => 5,

    // Base of the share link; the iOS app handles it as a universal link.
    'invite_url' => env('DAYLIST_INVITE_URL', 'https://daylist.app/invite'),

    // Where password reset links point (the app or a small web page).
    'password_reset_url' => env('DAYLIST_PASSWORD_RESET_URL', 'https://daylist.app/reset-password'),

    'apple' => [
        // Bundle id(s) of the iOS app, comma separated. Apple puts it in "aud".
        'client_ids' => array_filter(explode(',', env('APPLE_CLIENT_IDS', 'com.attiquedev.daylist'))),
        'issuer' => 'https://appleid.apple.com',
        'jwks_url' => 'https://appleid.apple.com/auth/keys',
    ],

    'google' => [
        // OAuth client id(s) of the iOS (and later Android) app, comma separated.
        'client_ids' => array_filter(explode(',', env('GOOGLE_CLIENT_IDS', ''))),
        'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
        'jwks_url' => 'https://www.googleapis.com/oauth2/v3/certs',
    ],

    'default_settings' => [
        'add_to_top' => false,
        'starred_to_top' => true,
        'completion_sound' => true,
        'confirm_delete' => true,
        'link_previews' => true,
        'recognise_dates' => true,
        'remove_recognised_dates' => false,
        'week_start' => 'monday',
        'default_reminder_time' => '09:00',
        'hidden_smart_lists' => [],
        'carry_over_my_day' => false,
    ],
];
