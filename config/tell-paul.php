<?php

return [
    'mailer' => env('TELL_PAUL_MAILER', 'resend'),
    'recipient_email' => env('TELL_PAUL_EMAIL', env('ADMIN_EMAIL', 'paul@filteragency.com')),
];
