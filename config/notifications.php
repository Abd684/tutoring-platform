<?php

return [
    'push_enabled' => env('FIREBASE_PUSH_ENABLED', false),
    // Never make a business HTTP request wait for Firebase, even with QUEUE_CONNECTION=sync.
    'connection' => env('NOTIFICATIONS_QUEUE_CONNECTION', 'database'),
    'queue' => 'notifications',
];
