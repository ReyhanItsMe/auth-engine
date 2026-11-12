# API Reference — v1.0.0

Namespace akar: `ReyhanItsMe\AuthEngine`

## `Auth` (entry point statis)

| Method | Return | Keterangan |
|--------|--------|------------|
| `Auth::boot(array\|string\|Config\|null $config = null, ?FrameworkAdapterInterface $adapter = null)` | `AuthManager` | Boot engine. `$config`: array override, path file, objek `Config`, atau `null`. Adapter dideteksi otomatis bila tidak diberikan. |
| `Auth::isBooted()` | `bool` | Apakah engine sudah di-boot. |
| `Auth::manager()` | `AuthManager` | Instance aktif. Melempar `NotBootedException` bila belum di-boot (di Laravel di-resolve dari container). |
| `Auth::setManager(?AuthManager $manager)` | `void` | Ganti instance aktif. |
| `Auth::reset()` | `void` | Lupakan instance (test / long-running worker). |
| `Auth::version()` | `string` | Versi package. |

Method lain diteruskan ke `AuthManager` (`Auth::check()`, `Auth::login()`, …). Method yang tidak ada melempar `BadMethodCallException`.

## `Core\AuthManager`

| Method | Return | Keterangan |
|--------|--------|------------|
| `config()` | `Config` | Konfigurasi aktif. |
| `adapter()` | `FrameworkAdapterInterface` | Adapter aktif. |
| `session()` | `SessionInterface` | Session (dimulai otomatis). |
| `setUserProvider(UserProviderInterface $users)` | `static` | Sumber data user. |
| `userProvider()` | `?UserProviderInterface` | |
| `login(AuthenticatableInterface $user)` | `void` | Tandai user login, regenerasi session ID. |
| `logout()` | `void` | Hapus state login milik package, regenerasi session ID. Data session lain tidak disentuh. |
| `check()` / `guest()` | `bool` | Status login. |
| `id()` | `int\|string\|null` | ID user; menerapkan idle timeout. |
| `user()` | `?AuthenticatableInterface` | User aktif. Butuh user provider (kecuali user baru login di request yang sama); melempar `ConfigurationException` bila tidak ada. |
| `authenticatedAt()` | `?int` | Unix timestamp saat login. |
| `extend(string $name, callable $factory)` | `static` | Daftarkan factory driver: `fn (AuthManager $m): AuthMethodInterface`. |
| `driver(string $name)` | `AuthMethodInterface` | Ambil driver. `DriverNotEnabledException` bila nonaktif; `ConfigurationException` bila belum terdaftar. |
| `enabledDrivers()` | `list<string>` | Driver yang aktif di config. |
| `drivers()` | `DriverRegistry` | Registry driver. |
| `version()` | `string` | |

## `Core\ConfigLoader`

| Method | Keterangan |
|--------|------------|
| `ConfigLoader::load(array\|string\|null $source = null): Config` | Default + override → validasi → `Config`. |
| `ConfigLoader::defaults(): array` | Isi `config/auth-engine.php`. |
| `ConfigLoader::defaultPath(): string` | Path file default. |
| `ConfigLoader::merge(array $base, array $overrides): array` | Deep merge; array asosiatif digabung, list & skalar diganti. |
| `ConfigLoader::validate(array $items): void` | Melempar `ConfigurationException` bila tidak valid. |

Aturan validasi: `adapter` ∈ `auto|native|laravel`; `identifiers` list tidak kosong dari `email|username|phone_number`;
`hashing.algo` ∈ `argon2id|bcrypt`; `session.lifetime` integer ≥ 0; `session.key` string tidak kosong;
setiap driver wajib punya `enabled` boolean; `magic_link`/`otp`/`oauth` aktif ⇒ `app.key` ≥ 32 karakter.

## `Core\Config`

`get(string $key, mixed $default = null)`, `has(string $key)`, `all()`, `with(string $key, mixed $value): Config` (salinan baru),
`isDriverEnabled(string $name): bool`, `enabledDrivers(): list<string>`. Kunci memakai dot-notation (`drivers.totp.enabled`).

## `Core\Env`

`Env::get(string $key, mixed $default = null)` — membaca `$_ENV`, `$_SERVER`, `getenv()`; mengonversi `true/false/null/empty`.

## Contracts (`Contracts\`)

| Interface | Method |
|-----------|--------|
| `AuthenticatableInterface` | `getAuthIdentifier(): int\|string`, `getAuthPasswordHash(): ?string` |
| `UserProviderInterface` | `findById(int\|string $id)`, `findByIdentifier(string $field, string $value)` |
| `SessionInterface` | `start`, `getId`, `has`, `get`, `put`, `forget`, `regenerate(bool $destroyOld = true)`, `invalidate` |
| `FrameworkAdapterInterface` | `name`, `session`, `ipAddress`, `userAgent`, `baseUrl` |
| `AuthMethodInterface` | `name(): string` |
| `ConfigRepositoryInterface` | `get`, `has`, `all` |

## Adapters (`Adapters\`)

| Class | Keterangan |
|-------|------------|
| `AdapterResolver::resolve(Config $config)` | Memilih adapter dari `adapter` config (`auto` mendeteksi Laravel). |
| `Native\NativeAdapter` | PHP tanpa framework. |
| `Native\NativeSession` | Wrapper `$_SESSION` dengan cookie aman. |
| `Native\ArraySession` | Session in-memory (CLI, test). |
| `Laravel\LaravelAdapter` | Memakai `session.store` & `request` dari container. |
| `Laravel\LaravelSession` | Wrapper session store Laravel. |
| `Laravel\AuthEngineServiceProvider` | Registrasi singleton `AuthManager`, alias `auth-engine`, publish tag `auth-engine-config`. |

## Exceptions (`Exceptions\`)

Semua turunan `AuthEngineException` (`RuntimeException`): `ConfigurationException`, `DriverNotEnabledException`, `NotBootedException`.

## Kunci session

Dengan `session.key = auth_engine`: `auth_engine.user_id`, `auth_engine.authenticated_at`, `auth_engine.last_activity`.

## CLI `easy-auth`

| Perintah | Keterangan |
|----------|------------|
| `list` (default), `help` | Daftar perintah. |
| `about` | Versi, PHP, default config, driver default. |
| `--version`, `-V` | Versi. |
| `install:ui` | Placeholder sampai Fase 5 (exit code 1). |

---

# Tambahan v0.2.0 – v1.0.0

## `AuthManager` — layanan dan alur login

| Method | Return | Keterangan |
|---|---|---|
| `setPdo(PDO $pdo)` | `static` | Pakai koneksi PDO yang sudah ada. |
| `installSchema()` | `void` | Membuat tabel (idempoten). |
| `users()` | `UserProviderInterface` | Bawaan `PdoUserProvider`. |
| `setNotifier(NotifierInterface\|callable $notifier)` | `static` | Callable: `(string $channel, string $to, string $subject, string $text, array $data)`. |
| `setHttpClient(HttpClientInterface $http)` | `static` | HTTP client untuk OAuth. |
| `register(array $data)` | `AuthenticatableInterface` | Melempar `ValidationException`. |
| `attempt(string $identifier, string $password)` | `AuthResult` | |
| `sendMagicLink(string $email)` | `void` | Melempar `TooManyAttemptsException`. |
| `loginWithMagicLink(string $token)` | `AuthResult` | |
| `sendOtp(string $recipient, string $channel = 'email')` | `void` | |
| `verifyOtp(string $recipient, string $code, string $channel = 'email')` | `bool` | |
| `loginWithOtp(string $recipient, string $code, string $channel = 'email')` | `AuthResult` | |
| `pendingMfa()` | `?array` | Tantangan faktor kedua yang sedang menunggu. |
| `sendMfaOtp()` | `bool` | Kirim OTP untuk tantangan yang menunggu. |
| `verifyMfa(string $code, ?string $method = null)` | `AuthResult` | `$method`: `totp`, `recovery`, `otp`. |
| `getActiveProviders()` | `list<array{key,name,color,icon,url}>` | `[]` bila driver `oauth` nonaktif. |
| `credentials()`, `magicLink()`, `otp()`, `totp()`, `webauthn()`, `oauth()` | driver | Melempar `DriverNotEnabledException` bila nonaktif. |

## `Core\AuthResult`

Properti readonly: `status` (`AuthStatus`: `Success`, `Failed`, `RateLimited`, `MfaRequired`), `user`, `reason`,
`retryAfter` (detik), `mfaMethods`. Method: `ok()`, `requiresMfa()`, `toArray()`.

Nilai `reason`: `invalid_credentials`, `invalid_token`, `invalid_code`, `no_pending_challenge`, `invalid_method`,
`rate_limited`, `invalid_passkey`; OAuth: `invalid_state`, `access_denied`, `provider_error`, `account_already_linked`,
`email_not_verified`, `email_required`.

## Driver

### `Methods\Credentials\CredentialsDriver`
`register(array $data)`, `attempt(string $identifier, string $password)`, `setPassword($user, string $password)`.

### `Methods\MagicLink\MagicLinkDriver`
`send(string $email)`, `login(string $token): AuthResult`, `url(string $token): string`.

### `Methods\Otp\OtpDriver`
`send($recipient, $channel = 'email')`, `verify($recipient, $code, $channel = 'email'): bool`,
`login($recipient, $code, $channel = 'email'): AuthResult`.

### `Methods\Totp\TotpDriver`

| Method | Return |
|---|---|
| `beginSetup($user, ?string $accountLabel = null)` | `array{secret: string, uri: string}` |
| `confirm($user, string $code)` | `?list<string>` recovery code, `null` jika kode salah |
| `isEnabled($user)` | `bool` |
| `verify($user, string $code)` | `bool` — kode yang sama tidak bisa dipakai dua kali |
| `useRecoveryCode($user, string $code)` | `bool` |
| `regenerateRecoveryCodes($user)` | `list<string>` |
| `remainingRecoveryCodes($user)` | `int` |
| `disable($user)` | `void` |

Utilitas statis `Methods\Totp\Totp`: `generateSecret`, `code`, `atStep`, `matchingStep`, `uri`.

### `Methods\WebAuthn\WebAuthnDriver`

| Method | Return |
|---|---|
| `registrationOptions($user, ?string $displayName = null)` | `array` (PublicKeyCredentialCreationOptions, base64url) |
| `register($user, array $credential, ?string $name = null)` | `array{id, name, created_at}`; melempar `WebAuthnException` |
| `authenticationOptions(?$user = null)` | `array` |
| `authenticate(array $credential)` | `AuthResult` — verifikasi pengguna (UV) dihitung sebagai MFA terpenuhi |
| `passkeys($user)` | `list<array>` |
| `delete($user, int $passkeyId)` | `bool` |

### `OAuth\OAuthManager`

| Method | Return |
|---|---|
| `activeProviders()` | `list<array{key,name,color,icon,url}>` |
| `redirectUrl(string $provider)` | `string` — menyimpan `state` (dan PKCE) di sesi |
| `callback(string $provider, array $params)` | `AuthResult` |
| `accountsFor($user)` | `list<array>` |
| `unlink($user, string $provider)` | `bool` |

Kunci definisi provider: `client_id`, `client_secret`, `redirect_uri`, `name`, `authorize_url`, `token_url`,
`userinfo_url`, `emails_url`, `scopes`, `scope_separator`, `pkce`, `token_auth`, `authorize_params`, `map`, `icon`;
Apple: `team_id`, `key_id`, `private_key`.

## `UI\SimpleIconsRegistry` (statis)

`has($slug)`, `get($slug)` → `{slug,title,hex,path,svg}`, `svg($slug, array $attributes = [])`, `color($slug)`,
`slugs()`, `register($slug, $title, $hex, $path)`.
Ikon bawaan: google, github, discord, apple, x, facebook, microsoft, gitlab, linkedin, spotify, twitch, slack, bitbucket.
Memperbarui data: `php scripts/update-icons.php`.

## `UI\Scaffolder`

`stacks()`, `resolveStack(string $input)`, `detectStack(string $projectRoot)`, `defaultPath($stack, $projectRoot)`,
`install($stack, $targetDir, bool $force = false)` → `list<array{file, path, status}>`, `nextSteps($stack, $relativePath)`.

## `Core\Env`

`Env::get($key, $default = null)`, `Env::loadFile(string $path): int` (tidak menimpa variabel yang sudah ada).

## Contracts tambahan

| Interface | Method |
|---|---|
| `MutableUserProviderInterface` | `create(array $attributes)`, `updatePasswordHash(int\|string $id, string $hash)` |
| `NotifierInterface` | `send(string $channel, string $to, string $subject, string $text, array $data = [])` |
| `NotifiableInterface` | `routeNotificationFor(string $channel): ?string` |
| `HttpClientInterface` | HTTP client untuk OAuth (bawaan `Http\CurlHttpClient`: hanya HTTPS, tanpa redirect) |

## Exceptions tambahan

`ValidationException` (`errors()`), `TooManyAttemptsException` (`retryAfter()`), `WebAuthnException`, `OAuthException`.

## CLI & Artisan

`easy-auth install:ui | key:generate | migrate | about | list`; Laravel: `php artisan auth-engine:install`.
