# Changelog

Semua perubahan penting pada `reyhanitsme/auth-engine` dicatat di sini.
Format mengikuti [Keep a Changelog](https://keepachangelog.com/id-ID/1.1.0/) dan [Semantic Versioning](https://semver.org/lang/id/).

Skema versi: perbaikan bug menaikkan patch, fitur baru menaikkan minor. Cara merilis: `docs/release-guide.md`.

## [1.0.0] - 2026-11-12

Rilis stabil pertama. Fase 2–6 dikerjakan berurutan dan dirilis bersama, sehingga hanya ada satu patch
(`patches/patch-v1.0.0.zip`, dibandingkan dengan v0.1.0). Bagian di bawah dikelompokkan per fase.

### Fase 6 — Release Packaging
- `scripts/release.php` + `release.sh` + `composer release`: membangun `source-code-full/`, `auth-engine-full.zip`,
  `patches/patch-v<versi>.zip` (hanya file yang berubah, berdasarkan hash SHA-256), `patches/manifest-v<versi>.json`,
  `docs/`, `CHANGELOG.md`. Menjalankan lint + PHPUnit lebih dulu dan menolak rilis tanpa entri changelog.
- `docs/release-guide.md`.

### Fase 5 — Modern UI Scaffolder
- `easy-auth install:ui`: interaktif, memilih Vanilla PHP / Blade / React JSX / React TSX; opsi `--stack`, `--path`, `--force`.
- Stub Tailwind untuk keempat stack: `AuthCard`, `CredentialForm`, `SocialLoginRow`, `MfaSetupForm`, `PasskeyButton`,
  dan satu halaman login contoh.
- `UI\Scaffolder`.
- `easy-auth key:generate` dan `easy-auth migrate`; `Env::loadFile()`.
- Laravel: `php artisan auth-engine:install`.
- `docs/ui-components.md`.

### Fase 4 — OAuth & SimpleIcons
- `OAuth\OAuthManager`: alur authorization code dengan `state` sekali pakai dan PKCE; penautan akun berdasarkan e-mail
  terverifikasi; `accountsFor()`, `unlink()`.
- 10 provider bawaan (Google, GitHub, Discord, Apple, X, Facebook, Microsoft, GitLab, LinkedIn, Spotify) dan provider
  kustom lewat konfigurasi. Ikon juga tersedia untuk Twitch, Slack, dan Bitbucket.
- `UI\SimpleIconsRegistry` + `Auth::getActiveProviders()` (metadata, warna merek, SVG mentah).
- `Http\CurlHttpClient`, `HttpClientInterface`; `scripts/update-icons.php`.

### Fase 3 — Advanced MFA
- `Methods\Totp`: TOTP (RFC 6238), URI `otpauth://` untuk QR, perlindungan replay, recovery code, secret terenkripsi.
- `Methods\WebAuthn`: registrasi dan login passkey (ES256, RS256, attestation `none`), decoder CBOR/COSE bawaan.
- Alur faktor kedua: `AuthResult::requiresMfa()`, `Auth::verifyMfa()`, `Auth::sendMfaOtp()`.

### Fase 2 — Standard Auth & Magic Links
- `Methods\Credentials`: registrasi dan login multi-identifier, Argon2id/Bcrypt dengan rehash, validasi.
- `Methods\MagicLink`: `Auth::sendMagicLink()`, `Auth::loginWithMagicLink()`.
- `Methods\Otp`: OTP e-mail/SMS 4–8 digit.
- `Core\RateLimiter`, `TokenStore`, `Crypto`, `Hasher`, `Identifier`, `AuthResult`, `AuthStatus`.
- `Database\Connection`, `Schema` (SQLite, MySQL, PostgreSQL), `PdoUserProvider`, `GenericUser`.
- Notifier: `mail`, `log`, `array`, callback, Laravel Mail.

### Diubah
- `ext-pdo` kini wajib.
- `app.key` (≥ 32 karakter) wajib bila `magic_link`, `otp`, atau `totp` aktif.
- `easy-auth install:ui` tidak lagi placeholder.

### Dihapus
- `stubs/README.md` (digantikan stub sebenarnya).

### Pengujian
- 101 test PHPUnit. Selain itu diuji manual: seremoni passkey di Chromium (authenticator virtual) pada stack Vanilla,
  dan komponen Blade di aplikasi Laravel.

## [0.1.0] - 2026-11-12

Rilis awal — **Fase 1: Core Foundation**. Belum ada patch (`patches/` kosong) karena ini rilis pertama.

### Ditambahkan
- `composer.json` (PSR-4 `ReyhanItsMe\AuthEngine\`, PHP ^8.2, binary `easy-auth`, Laravel package discovery).
- `config/auth-engine.php`: konfigurasi default (app, adapter, identifiers, hashing, session, rate limit, database, drivers).
- `Core\ConfigLoader` + `Core\Config`: deep merge default ↔ override, validasi, akses dot-notation, immutable.
- `Core\Env`: pembaca environment variable tanpa dependensi.
- `Auth`: entry point statis (`boot`, `manager`, `reset`, `version`, dan forwarding ke `AuthManager`).
- `Core\AuthManager`: state user (`login`, `logout`, `check`, `guest`, `id`, `user`, `authenticatedAt`),
  regenerasi session ID saat login/logout (pencegahan session fixation), idle timeout.
- `Core\DriverRegistry`: registry driver lazy — driver nonaktif tidak pernah diinstansiasi.
- Contracts: `ConfigRepositoryInterface`, `SessionInterface`, `FrameworkAdapterInterface`,
  `AuthMethodInterface`, `AuthenticatableInterface`, `UserProviderInterface`.
- Adapter Native: `NativeAdapter`, `NativeSession` (cookie HttpOnly/SameSite/Secure, strict mode), `ArraySession`.
- Adapter Laravel: `LaravelAdapter`, `LaravelSession`, `AuthEngineServiceProvider` (alias facade `AuthEngine`).
- `Adapters\AdapterResolver`: deteksi otomatis Native vs Laravel.
- CLI `bin/easy-auth` dengan perintah `list`, `about`, `--version` (`install:ui` masih placeholder).
- Exceptions: `AuthEngineException`, `ConfigurationException`, `DriverNotEnabledException`, `NotBootedException`.
- Test suite PHPUnit (35 test) dan dokumentasi (`README.md`, `docs/`).
