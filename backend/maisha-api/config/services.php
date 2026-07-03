<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth
    |--------------------------------------------------------------------------
    */
    'google' => [
        'client_id'     => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect'      => env('GOOGLE_REDIRECT_URI'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Flask AI Engine
    |--------------------------------------------------------------------------
    */
    'flask' => [
        'url'    => env('FLASK_AI_URL', 'http://localhost:5000'),
        'secret' => env('MAISHA_INTERNAL_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Maisha Internal Services
    |--------------------------------------------------------------------------
    */
    'maisha' => [
        'internal_secret' => env('MAISHA_INTERNAL_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Business API (Meta)
    |--------------------------------------------------------------------------
    */
    'whatsapp' => [
        'phone_number_id'     => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token'        => env('WHATSAPP_ACCESS_TOKEN'),
        'verify_token'        => env('WHATSAPP_VERIFY_TOKEN'),
        'api_version'         => env('WHATSAPP_API_VERSION', 'v25.0'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio (for WhatsApp inbound)
    |--------------------------------------------------------------------------
    */
    'twilio' => [
        'account_sid'      => env('TWILIO_ACCOUNT_SID'),
        'auth_token'       => env('TWILIO_AUTH_TOKEN'),
        'whatsapp_number'  => env('TWILIO_WHATSAPP_NUMBER'),
    ],

];