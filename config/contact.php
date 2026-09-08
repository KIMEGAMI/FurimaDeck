<?php

return [
    'to_address' => env('CONTACT_TO_ADDRESS'),
    'to_name' => env('CONTACT_TO_NAME', env('APP_NAME', 'FurimaDeck')),
    'subject_prefix' => env('CONTACT_SUBJECT_PREFIX', 'FurimaDeckお問い合わせ'),
    'max_name_length' => (int) env('CONTACT_MAX_NAME_LENGTH', 80),
    'max_subject_length' => (int) env('CONTACT_MAX_SUBJECT_LENGTH', 120),
    'max_message_length' => (int) env('CONTACT_MAX_MESSAGE_LENGTH', 3000),
];
