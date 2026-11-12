# Panduan Memulai

Versi: 1.0.0

## 1. Instalasi

```bash
composer require reyhanitsme/auth-engine
```

Kebutuhan: PHP ≥ 8.2, ekstensi `json`, `mbstring`, `openssl`, `pdo` dan driver PDO database Anda.

## 2. Kunci aplikasi dan database

```bash
vendor/bin/easy-auth key:generate
```

Perintah ini menulis `AUTH_ENGINE_KEY` ke `.env` di folder kerja; jika `.env` tidak ada, kunci dicetak dan Anda
menyimpannya sendiri. Kunci (minimal 32 karakter) dipakai untuk menandatangani token magic link/OTP dan mengenkripsi
secret TOTP. **Mengganti kunci membatalkan token yang beredar dan secret TOTP yang tersimpan.**

`easy-auth` tidak memuat `.env` ke aplikasi Anda. Di PHP native, muat sendiri (mis. `vlucas/phpdotenv`) atau isi
`app.key` langsung di konfigurasi.

Tabel:

| Lingkungan | Perintah |
|---|---|
| PHP Native | `vendor/bin/easy-auth migrate` (membaca `AUTH_ENGINE_DB_DSN`, `AUTH_ENGINE_DB_USERNAME`, `AUTH_ENGINE_DB_PASSWORD`) |
| Laravel | `php artisan auth-engine:install` (memakai koneksi default Laravel) |
| Dari kode | `Auth::installSchema();` |

Semuanya memakai `CREATE TABLE IF NOT EXISTS`: tabel yang sudah ada tidak diubah. Tabel yang dibuat: `users`,
`auth_engine_tokens`, `auth_engine_rate_limits`, `auth_engine_mfa`, `auth_engine_passkeys`, `auth_engine_oauth_accounts`
(nama dapat diganti di `database.tables`).

Jika Anda sudah punya tabel `users`, kolom yang dibaca paket: `id`, `email`, `password`, `name`, `email_verified_at`,
`created_at`, `updated_at`, serta `username` / `phone_number` bila identifier itu diaktifkan.
Punya penyimpanan user sendiri? Implementasikan `UserProviderInterface` (dan `MutableUserProviderInterface` untuk
registrasi) lalu `Auth::setUserProvider($provider)`.

## 3. Boot

### PHP Native

```php
use ReyhanItsMe\AuthEngine\Auth;

Auth::boot(__DIR__ . '/config/auth-engine.php');   // file konfigurasi
// atau
Auth::boot(['identifiers' => ['email', 'phone_number']]);   // override; sisanya memakai nilai default
// sudah punya PDO?
Auth::setPdo($pdo);
```

Salin `vendor/reyhanitsme/auth-engine/config/auth-engine.php` sebagai titik awal konfigurasi.

### Laravel

```bash
php artisan vendor:publish --tag=auth-engine-config
```

Tidak perlu `boot()`. Gunakan facade `AuthEngine::…` atau injeksi `ReyhanItsMe\AuthEngine\Core\AuthManager`.
Sesi, database, dan e-mail mengikuti konfigurasi Laravel.

## 4. Password (driver `credentials`)

```php
use ReyhanItsMe\AuthEngine\Exceptions\ValidationException;

try {
    $user = Auth::register([
        'email' => 'jane@example.com',
        'username' => 'jane',            // jika 'username' ada di identifiers
        'password' => 'rahasia-panjang',
        'name' => 'Jane',
    ]);
} catch (ValidationException $e) {
    $errors = $e->errors();              // ['email' => '...', 'password' => '...']
}

$result = Auth::attempt($identifier, $password);
```

`$result` adalah `AuthResult`:

| Kondisi | Arti |
|---|---|
| `$result->ok()` | Login berhasil. |
| `$result->requiresMfa()` | Password benar, faktor kedua dibutuhkan. `$result->mfaMethods` berisi `totp`, `recovery`, `otp`. |
| `$result->reason === 'rate_limited'` | Terlalu banyak percobaan; `$result->retryAfter` detik. |
| `$result->reason === 'invalid_credentials'` | Identifier atau password salah (pesan sengaja tidak dibedakan). |

Bawaan: 5 percobaan per 60 detik per identifier (`rate_limit`), hash Argon2id dengan *rehash* otomatis saat login,
ID sesi diganti saat login dan logout. `registration.auto_login` (bawaan `true`) langsung me-login-kan user baru.

Ganti password: `Auth::credentials()->setPassword($user, $baru)`.

## 5. Magic link

```php
// config: 'drivers' => ['magic_link' => ['enabled' => true]]
Auth::sendMagicLink('jane@example.com');

// route GET /auth/magic
$result = Auth::loginWithMagicLink($_GET['token'] ?? '');
```

`sendMagicLink()` tidak memberi tahu apakah e-mail terdaftar. Tautan berlaku 15 menit dan hanya sekali pakai.
Pengiriman dibatasi; jika terlampaui dilempar `TooManyAttemptsException` (`retryAfter()`).
Setel `auto_register` ke `true` untuk membuat akun bagi e-mail yang belum terdaftar.

## 6. OTP e-mail / SMS

```php
// config: 'drivers' => ['otp' => ['enabled' => true, 'digits' => 6, 'channels' => ['email', 'sms']]]
Auth::sendOtp('jane@example.com');
$result = Auth::loginWithOtp('jane@example.com', $kode);

Auth::sendOtp('+628123456789', 'sms');
Auth::verifyOtp('+628123456789', $kode, 'sms');   // hanya verifikasi, tanpa login
```

### Pengiriman pesan

| `notifications.driver` | Perilaku |
|---|---|
| `auto` (bawaan) | Laravel: Mail Laravel. Native: fungsi `mail()` PHP. |
| `mail` | `mail()` PHP dengan `notifications.from`. |
| `log` | Menulis ke error log (pengembangan). |
| `array` | Menyimpan di memori (testing). |

SMS (atau penyedia e-mail seperti Resend/Mailgun) lewat notifier Anda sendiri:

```php
Auth::setNotifier(function (string $channel, string $to, string $subject, string $text, array $data): void {
    $channel === 'sms' ? $sms->send($to, $text) : $mailer->send($to, $subject, $text);
});
```

## 7. TOTP (aplikasi authenticator)

```php
// config: 'drivers' => ['totp' => ['enabled' => true]]
$setup = Auth::totp()->beginSetup(Auth::user());   // ['secret' => 'BASE32…', 'uri' => 'otpauth://totp/…']
// tampilkan $setup['uri'] sebagai QR (komponen MfaSetupForm), lalu:
$recoveryCodes = Auth::totp()->confirm(Auth::user(), $kode);   // null jika kode salah
```

Recovery code hanya ditampilkan sekali. Setelah aktif, `Auth::attempt()` mengembalikan `requiresMfa()`:

```php
$result = Auth::verifyMfa($kode);                 // kode TOTP
$result = Auth::verifyMfa($kode, 'recovery');     // recovery code
```

OTP e-mail sebagai faktor kedua untuk semua user: `drivers.otp.second_factor = true`, lalu `Auth::sendMfaOtp()` dan
`Auth::verifyMfa($kode, 'otp')`.

## 8. Passkeys (WebAuthn)

```php
// config: 'drivers' => ['webauthn' => ['enabled' => true]]   (rp_id & origins diturunkan dari app.url)

// Mendaftarkan passkey (user sudah login)
echo json_encode(Auth::webauthn()->registrationOptions(Auth::user()));          // POST /passkey/register/options
Auth::webauthn()->register(Auth::user(), $jsonBody, 'MacBook');                 // POST /passkey/register/verify

// Login
echo json_encode(Auth::webauthn()->authenticationOptions());                    // POST /passkey/options
$result = Auth::webauthn()->authenticate($jsonBody);                            // POST /passkey/verify
```

`$jsonBody` adalah hasil `json_decode(file_get_contents('php://input'), true)` (Laravel: `$request->all()`).
Komponen `PasskeyButton` mengurus sisi browser. WebAuthn hanya berjalan di HTTPS atau `localhost`.
`register()` melempar `WebAuthnException` bila verifikasi gagal.

## 9. OAuth

```php
'drivers' => ['oauth' => ['enabled' => true, 'providers' => [
    'google' => ['client_id' => '…', 'client_secret' => '…'],
    'github' => ['client_id' => '…', 'client_secret' => '…'],
]]],
```

Daftarkan callback URL `{app.url}/auth/oauth/{provider}/callback` di konsol tiap provider.

```php
// GET /auth/oauth/{provider}
header('Location: ' . Auth::oauth()->redirectUrl($provider));

// GET /auth/oauth/{provider}/callback
$result = Auth::oauth()->callback($provider, $_GET);

// Tombol login
foreach (Auth::getActiveProviders() as $p) {
    // $p['key'], $p['name'], $p['color'] (#RRGGBB), $p['icon'] (SVG mentah SimpleIcons), $p['url']
}
```

Hanya provider yang memiliki `client_id` yang muncul. Akun ditautkan ke user dengan e-mail yang sama hanya jika
provider menyatakan e-mail tersebut terverifikasi. Apple memerlukan `team_id`, `key_id`, `private_key`.
Provider kustom: tambahkan definisi dengan `authorize_url`, `token_url`, `userinfo_url`, `scopes`, `map`
(lihat `docs/api-reference.md`).

## 10. UI

```bash
vendor/bin/easy-auth install:ui
```

Lihat [`ui-components.md`](ui-components.md).

## 11. Menambah driver sendiri

```php
Auth::extend('sso', fn (AuthManager $auth) => new MySsoDriver($auth));   // implements AuthMethodInterface
// config: 'drivers' => ['sso' => ['enabled' => true]]
Auth::driver('sso');
```
