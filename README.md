# Auth Engine

Paket autentikasi PHP *plug-and-play* untuk **PHP Native** dan **Laravel**: satu core, driver modular.
Driver yang tidak diaktifkan tidak pernah diinstansiasi.

```bash
composer require reyhanitsme/auth-engine
```

| Fitur | Driver | Bawaan |
|---|---|---|
| Login/registrasi password, multi-identifier (`email`, `username`, `phone_number`), Argon2id/Bcrypt, rate limiting, anti session fixation | `credentials` | aktif |
| Magic link (login tanpa password lewat e-mail) | `magic_link` | nonaktif |
| OTP e-mail/SMS 4–8 digit (login atau faktor kedua) | `otp` | nonaktif |
| TOTP (Google Authenticator, Authy) + recovery code | `totp` | nonaktif |
| WebAuthn / Passkeys (FaceID, TouchID, Windows Hello) | `webauthn` | nonaktif |
| OAuth: Google, GitHub, Discord, Apple, X, Facebook, Microsoft, GitLab, LinkedIn, Spotify + provider kustom, ikon SimpleIcons | `oauth` | nonaktif |
| Scaffolder UI Tailwind (Vanilla PHP, Blade, React JSX/TSX) | CLI `easy-auth install:ui` | — |

Kebutuhan: PHP ≥ 8.2 dengan `json`, `mbstring`, `openssl`, `pdo` (+ driver PDO database Anda: SQLite, MySQL, atau PostgreSQL).

## Mulai cepat — PHP Native

```bash
vendor/bin/easy-auth key:generate     # menulis AUTH_ENGINE_KEY ke .env (atau mencetaknya)
vendor/bin/easy-auth migrate          # membuat tabel (butuh AUTH_ENGINE_DB_DSN)
vendor/bin/easy-auth install:ui       # menyalin komponen UI
```

```php
use ReyhanItsMe\AuthEngine\Auth;

require __DIR__ . '/vendor/autoload.php';

Auth::boot([
    'app' => ['name' => 'Acme', 'url' => 'https://acme.test', 'key' => getenv('AUTH_ENGINE_KEY')],
    'database' => ['dsn' => 'mysql:host=127.0.0.1;dbname=acme', 'username' => 'root', 'password' => ''],
    'identifiers' => ['email', 'username'],
    'drivers' => ['magic_link' => ['enabled' => true]],
]);

Auth::register(['email' => 'jane@example.com', 'username' => 'jane', 'password' => 'rahasia-panjang']);

$result = Auth::attempt('jane', 'rahasia-panjang');   // email / username / nomor telepon

if ($result->ok()) {
    // sudah login: Auth::user(), Auth::id(), Auth::check()
} elseif ($result->requiresMfa()) {
    // minta kode, lalu: Auth::verifyMfa($kode)
} else {
    // $result->reason: invalid_credentials | rate_limited ($result->retryAfter detik)
}

Auth::sendMagicLink('jane@example.com');
Auth::getActiveProviders();   // metadata + SVG SimpleIcons provider OAuth yang aktif
Auth::logout();
```

## Mulai cepat — Laravel

Service provider dan alias `AuthEngine` terdaftar otomatis (nama `AuthEngine` dipakai agar tidak bentrok dengan facade `Auth` Laravel).

```bash
php artisan vendor:publish --tag=auth-engine-config
vendor/bin/easy-auth key:generate
php artisan auth-engine:install        # membuat tabel di koneksi database default
vendor/bin/easy-auth install:ui --stack=blade
```

```php
$result = AuthEngine::attempt($request->input('identifier'), $request->input('password'));
```

Paket memakai tabel `users` bawaan Laravel bila sudah ada. Kolom `username` dan `phone_number` hanya dibutuhkan
jika identifier tersebut Anda aktifkan — tambahkan lewat migration Anda sendiri.

## CLI

| Perintah | Fungsi |
|---|---|
| `easy-auth install:ui [--stack=vanilla\|blade\|react-jsx\|react-tsx] [--path=DIR] [--force]` | Menanyakan stack frontend lalu menyalin `AuthCard`, `CredentialForm`, `SocialLoginRow`, `MfaSetupForm`, `PasskeyButton` + satu halaman contoh. File yang sudah ada tidak ditimpa tanpa `--force`. |
| `easy-auth key:generate [--show] [--force]` | Membuat `AUTH_ENGINE_KEY`. |
| `easy-auth migrate [--config=FILE] [--pretend]` | Membuat tabel; membaca `.env` di folder kerja. Aman dijalankan berulang. |
| `easy-auth about` / `list` / `--version` | Informasi. |

## Dokumentasi

- [`docs/getting-started.md`](docs/getting-started.md) — instalasi, konfigurasi, dan contoh tiap driver.
- [`docs/api-reference.md`](docs/api-reference.md) — seluruh API publik.
- [`docs/ui-components.md`](docs/ui-components.md) — komponen UI dan endpoint yang dibutuhkannya.
- [`docs/release-guide.md`](docs/release-guide.md) — cara merilis: `CHANGELOG.md`, `patch-v[x].zip`, upload ke Google Drive.
- [`CHANGELOG.md`](CHANGELOG.md)

## Struktur

```
bin/easy-auth          CLI
config/auth-engine.php konfigurasi default
stubs/                 template UI: vanilla/ blade/ react-jsx/ react-tsx/
scripts/               release.php (paket rilis), update-icons.php (SimpleIcons)
src/
  Auth.php             entry point statis
  Contracts/ Core/ Database/ Exceptions/ Http/ Notifications/
  Methods/             Credentials/ MagicLink/ Otp/ Totp/ WebAuthn/
  OAuth/ UI/ Adapters/ Console/
tests/
```

## Pengembangan & rilis

```bash
composer install
composer test        # PHPUnit
composer lint
./release.sh         # membangun aset rilis untuk Google Drive (lihat docs/release-guide.md)
```

## Batasan yang perlu diketahui

- WebAuthn: algoritma ES256 dan RS256, attestation `none` (tanpa verifikasi sertifikat pabrikan authenticator).
- Alur reset password tidak disediakan sebagai satu perintah; gunakan magic link/OTP untuk memverifikasi pengguna lalu
  `Auth::credentials()->setPassword($user, $passwordBaru)`.
- SMS membutuhkan notifier Anda sendiri (`Auth::setNotifier(...)`); paket tidak menyertakan gateway SMS.
- OAuth diuji dengan HTTP client tiruan; uji tiap provider dengan kredensial asli sebelum produksi.

## Lisensi

MIT. Ikon merek berasal dari [Simple Icons](https://simpleicons.org) (CC0-1.0); logo tetap merupakan merek dagang
pemiliknya masing-masing — ikuti pedoman merek tiap provider.
