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
	
	'xivauth' => [
		'client_id' => env('XIVAUTH_CLIENT_ID'),
		'client_secret' => env('XIVAUTH_CLIENT_SECRET'),
		'redirect' => env('XIVAUTH_REDIRECT_URI'),
	],
	
	'google' => [
		'client_id' => env('GOOGLE_CLIENT_ID'),
		'client_secret' => env('GOOGLE_CLIENT_SECRET'),
		'redirect' => env('GOOGLE_REDIRECT_URI'),
	],
	
	'discord' => [
		'client_id' => env('DISCORD_CLIENT_ID'),
		'client_secret' => env('DISCORD_CLIENT_SECRET'),
		'redirect' => env('DISCORD_REDIRECT_URI'),
	],

    'discord_notifications' => [
        'enabled' => env('DISCORD_NOTIFICATIONS_ENABLED', false),
    ],

    'ff_logs' => [
		'client_id' => env('FFLOGS_CLIENT_ID'),
		'client_secret' => env('FFLOGS_CLIENT_SECRET'),
		'token_url' => env('FFLOGS_TOKEN_URL', 'https://www.fflogs.com/oauth/token'),
		'graphql_url' => env('FFLOGS_GRAPHQL_URL', 'https://www.fflogs.com/api/v2/client'),
		'forked_tower_blood_zone_id' => env('FFLOGS_FORKED_TOWER_BLOOD_ZONE_ID'),
	],

    'project_links' => [
        'discord' => env('FULLPARTY_DISCORD_URL'),
        'github' => env('FULLPARTY_GITHUB_URL'),
    ],

    'legal' => [
        'controller_name' => env('FULLPARTY_LEGAL_CONTROLLER_NAME'),
        'contact_email' => env('FULLPARTY_LEGAL_CONTACT_EMAIL'),
    ],

];
