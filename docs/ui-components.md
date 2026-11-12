# Komponen UI

```bash
vendor/bin/easy-auth install:ui
```

```
Which frontend stack do you use?
  [1] Vanilla PHP (.php)
  [2] Laravel Blade (.blade.php)
  [3] React (.jsx)
  [4] React + TypeScript (.tsx)
Choice [1]:
```

Pilihan bawaan ditebak dari proyek (`artisan` → Blade, `react` di `package.json` → React, `tsconfig.json` → TSX).
Tanpa interaksi: `--stack=vanilla|blade|react-jsx|react-tsx`, `--path=DIR`, `--force` (timpa file yang ada).

| Stack | Tujuan bawaan | Nama file |
|---|---|---|
| Vanilla PHP | `views/auth/` | `AuthCard.php`, … + `login-example.php` |
| Blade | `resources/views/components/auth/` | `auth-card.blade.php`, … + `login-example.blade.php` |
| React JSX / TSX | `resources/js/components/auth/` jika ada `resources/js`, selain itu `src/components/auth/` | `AuthCard.jsx|tsx`, … + `LoginExample.jsx|tsx` |

File hasil salinan adalah milik Anda — ubah sesukanya. Teks antarmuka berbahasa Inggris.

## Tailwind

Komponen hanya memakai kelas utilitas Tailwind (v3 atau v4), tanpa plugin. Pastikan folder tujuan termasuk dalam
`content` (v3) / `@source` (v4) Tailwind Anda. Halaman contoh Vanilla dan Blade memuat Tailwind Play CDN agar langsung
tampil; ganti dengan build Tailwind Anda untuk produksi.

## Komponen

| Komponen | Fungsi | Data dari backend |
|---|---|---|
| `AuthCard` | Kartu di tengah halaman: judul, subjudul, logo, footer. | — |
| `CredentialForm` | Form login/registrasi (`mode`), label identifier dapat diganti, pesan error per field. Mengirim `identifier`, `password`, (`name`). | Hasil `Auth::attempt()` / `ValidationException::errors()` |
| `SocialLoginRow` | Tombol provider OAuth dengan ikon SimpleIcons. Tidak merender apa pun jika daftar kosong. | `Auth::getActiveProviders()` |
| `MfaSetupForm` | QR code + setup key + input kode; menampilkan recovery code setelah konfirmasi. Mengirim `code`. | `Auth::totp()->beginSetup()` dan `->confirm()` |
| `PasskeyButton` | Seremoni WebAuthn di browser (`mode` `login` / `register`). Tersembunyi di browser tanpa dukungan passkey. | Dua endpoint POST (lihat bawah) |

### Contoh pemakaian

Vanilla PHP — tiap komponen adalah fungsi yang mengembalikan HTML:

```php
require 'views/auth/AuthCard.php';
require 'views/auth/CredentialForm.php';
require 'views/auth/SocialLoginRow.php';

echo AuthCard(
    ['title' => 'Welcome back', 'subtitle' => 'Sign in to continue'],
    CredentialForm(['action' => '/login', 'errors' => $errors, 'old' => $_POST, 'csrfToken' => $csrf])
    . SocialLoginRow(['providers' => Auth::getActiveProviders()])
);
```

Blade — komponen anonim:

```blade
<x-auth.auth-card title="Welcome back">
    <x-auth.credential-form :action="route('login')" />
    <x-auth.passkey-button :options-url="route('passkey.options')" :verify-url="route('passkey.verify')" redirect-to="/dashboard" />
    <x-auth.social-login-row :providers="AuthEngine::getActiveProviders()" />
</x-auth.auth-card>
```

`CredentialForm` membaca error dari key `identifier`, `password`, `name`, `form` pada `$errors`; `MfaSetupForm` dari key `code`.

React:

```tsx
<AuthCard title="Welcome back">
  <CredentialForm onSubmit={signIn} errors={{ form: error }} />
  <PasskeyButton optionsUrl="/auth/passkey/options" verifyUrl="/auth/passkey/verify" csrfToken={csrf} onSuccess={() => location.assign('/dashboard')} />
  <SocialLoginRow providers={providers} />
</AuthCard>
```

`providers` adalah `Auth::getActiveProviders()` yang dikirim backend (props Inertia atau JSON).

### Endpoint `PasskeyButton`

| | `mode="login"` | `mode="register"` (user sudah login) |
|---|---|---|
| `optionsUrl` (POST) | `json_encode(Auth::webauthn()->authenticationOptions())` | `json_encode(Auth::webauthn()->registrationOptions(Auth::user()))` |
| `verifyUrl` (POST, body JSON) | `Auth::webauthn()->authenticate($body)` → kembalikan `$result->toArray()`; status non-2xx bila gagal | `Auth::webauthn()->register(Auth::user(), $body)` → kembalikan JSON apa saja |

Token CSRF dikirim sebagai header `X-CSRF-TOKEN` (Blade mengisinya otomatis).

### QR code TOTP

- Vanilla dan Blade menggambar QR di browser dengan `qrcode-generator@1.4.4` dari jsDelivr (dengan hash SRI), sehingga
  secret tidak dikirim ke layanan QR pihak ketiga. Jika CDN diblokir, setup key tetap bisa diketik manual.
- React: berikan prop `renderQr`, misalnya `npm install qrcode.react` lalu
  `renderQr={(uri) => <QRCodeSVG value={uri} size={176} />}`.

### Keamanan

- Semua nilai di-escape, kecuali `icon` pada `SocialLoginRow` yang dicetak sebagai SVG mentah. Isinya berasal dari
  registry paket; jangan mengisinya dengan input pengguna.
- `AuthCard` Vanilla menerima `logo`, `footer`, dan isi kartu sebagai HTML tepercaya.
