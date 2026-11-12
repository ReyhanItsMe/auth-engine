<?php

declare(strict_types=1);

use ReyhanItsMe\AuthEngine\Core\Env;

/*
|--------------------------------------------------------------------------
| Auth Engine — Default Configuration
|--------------------------------------------------------------------------
| Native PHP : Auth::boot(__DIR__ . '/config/auth-engine.php') or Auth::boot([...overrides]);
| Laravel    : php artisan vendor:publish --tag=auth-engine-config
|
| Every value you omit falls back to the defaults in this file (deep merge).
*/

return [

    'app' => [
        'name' => Env::get('AUTH_ENGINE_APP_NAME', Env::get('APP_NAME', 'Auth Engine')),
        // Public base URL, used to build magic links / OAuth callbacks.
        'url' => Env::get('AUTH_ENGINE_URL', Env::get('APP_URL')),
        // Secret used to sign tokens and encrypt TOTP secrets. Min. 32 chars.
        // Generate one with: vendor/bin/easy-auth key:generate
        'key' => Env::get('AUTH_ENGINE_KEY'),
    ],

    // 'auto' detects Laravel when available, otherwise falls back to native PHP.
    'adapter' => Env::get('AUTH_ENGINE_ADAPTER', 'auto'), // auto | native | laravel

    // Columns a user may log in with. Allowed: email, username, phone_number.
    'identifiers' => ['email'],

    'password' => [
        'min_length' => 8,
    ],

    'registration' => [
        'auto_login' => true, // log the user in right after Auth::register()
    ],

    'hashing' => [
        'algo' => 'argon2id', // argon2id | bcrypt (falls back to bcrypt if Argon2id is unavailable)
        'argon2id' => ['memory_cost' => 65536, 'time_cost' => 4, 'threads' => 1],
        'bcrypt' => ['cost' => 12],
    ],

    'session' => [
        'key' => 'auth_engine',        // prefix of every session entry written by the package
        'lifetime' => 120,             // idle timeout in minutes, 0 = never expires
        'cookie' => [                  // native PHP only (Laravel uses config/session.php)
            'name' => 'auth_engine_session',
            'path' => '/',
            'domain' => null,
            'secure' => null,          // null = auto (true on HTTPS)
            'http_only' => true,
            'same_site' => 'Lax',
        ],
    ],

    // Brute-force protection for login, MFA and code/link delivery.
    'rate_limit' => [
        'enabled' => true,
        'max_attempts' => 5,
        'decay_seconds' => 60,
    ],

    'mfa' => [
        'challenge_ttl' => 600, // seconds a "second step" may stay pending after the first factor
    ],

    // How magic links and OTP codes are delivered.
    'notifications' => [
        // auto  = Laravel mailer inside Laravel, PHP mail() otherwise
        // mail  = PHP mail()      log = error_log() (development)
        // SMS always needs your own sender: Auth::setNotifier(fn ($channel, $to, $subject, $text, $data) => ...)
        'driver' => Env::get('AUTH_ENGINE_NOTIFIER', 'auto'),
        'from' => Env::get('AUTH_ENGINE_MAIL_FROM', Env::get('MAIL_FROM_ADDRESS')),
    ],

    'database' => [
        // Native PHP only. Laravel reuses the application's default connection.
        'dsn' => Env::get('AUTH_ENGINE_DB_DSN'),
        'username' => Env::get('AUTH_ENGINE_DB_USERNAME'),
        'password' => Env::get('AUTH_ENGINE_DB_PASSWORD'),
        'tables' => [
            'users' => 'users',
            'tokens' => 'auth_engine_tokens',
            'rate_limits' => 'auth_engine_rate_limits',
            'mfa' => 'auth_engine_mfa',
            'passkeys' => 'auth_engine_passkeys',
            'oauth_accounts' => 'auth_engine_oauth_accounts',
        ],
    ],

    /*
    | Modular drivers. A driver with 'enabled' => false is NEVER instantiated,
    | so unused features cost nothing at runtime.
    */
    'drivers' => [
        'credentials' => ['enabled' => true],

        'magic_link' => [
            'enabled' => false,
            'ttl' => 900,              // seconds
            'route' => '/auth/magic',  // the link becomes {app.url}{route}?token=...
            'auto_register' => false,  // create an account for unknown e-mail addresses
        ],

        'otp' => [
            'enabled' => false,
            'digits' => 6,             // 4-8
            'ttl' => 300,
            'max_attempts' => 5,
            'channels' => ['email'],   // email | sms
            'second_factor' => false,  // true = require an e-mail OTP after every first-factor login
        ],

        'totp' => [
            'enabled' => false,
            'digits' => 6,
            'period' => 30,
            'window' => 1,             // accepted clock drift, in periods
            'issuer' => null,          // null = app.name
            'recovery_codes' => 8,
        ],

        'webauthn' => [
            'enabled' => false,
            'rp_id' => null,           // null = host of app.url
            'rp_name' => null,         // null = app.name
            'origins' => [],           // [] = origin of app.url
            'user_verification' => 'preferred', // required | preferred | discouraged
            'timeout' => 60000,        // milliseconds
        ],

        'oauth' => [
            'enabled' => false,
            'redirect_route' => '/auth/oauth/{provider}',
            'callback' => '/auth/oauth/{provider}/callback',
            'require_email' => true,   // reject providers that return no e-mail address
            'providers' => [
                // Built in: google, github, discord, apple, x, facebook, microsoft, gitlab, linkedin, spotify
                // 'google' => ['client_id' => '...', 'client_secret' => '...'],
                // 'github' => ['client_id' => '...', 'client_secret' => '...', 'scopes' => ['read:user', 'user:email']],
                // 'apple'  => ['client_id' => 'com.example.web', 'team_id' => '...', 'key_id' => '...', 'private_key' => '-----BEGIN PRIVATE KEY-----...'],
            ],
        ],
    ],
];
