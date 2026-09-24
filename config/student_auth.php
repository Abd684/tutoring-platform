<?php

return [
    // Same short access-token window used by the teacher flow, while student
    // tokens also remain bound to an active device session.
    'access_token_minutes' => (int) env('STUDENT_ACCESS_TOKEN_MINUTES', 15),

    // Device refresh session lifetime. Refresh rotates the token but does not
    // extend the original session expiration indefinitely.
    'refresh_token_days' => (int) env('STUDENT_REFRESH_TOKEN_DAYS', 30),
];
