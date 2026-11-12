# Panduan Rilis & Backup Google Drive

Setiap perubahan (fitur, patch, perbaikan bug) dirilis dengan urutan yang sama.

## 1. Naikkan versi

`src/Core/Version.php`:

```php
public const VERSION = '1.0.1';
```

Perbaikan bug → patch (`1.0.1`), fitur baru yang kompatibel → minor (`1.1.0`), perubahan yang memutus kompatibilitas → major.

## 2. Catat di `CHANGELOG.md`

Tambahkan entri di paling atas (di bawah pengantar):

```markdown
## [1.0.1] - 2026-12-01

### Diperbaiki
- Uraian singkat perubahan, dari sudut pandang pengguna paket.
```

Skrip rilis menolak berjalan bila entri `## [<versi>]` belum ada.

## 3. Jalankan skrip rilis

```bash
./release.sh                      # sama dengan: php scripts/release.php  /  composer release
./release.sh --out=/path/lain     # folder keluaran (bawaan: ../drive-release)
```

Skrip ini:

1. menjalankan lint dan PHPUnit (berhenti bila gagal; lewati dengan `--skip-tests`);
2. membandingkan hash SHA-256 setiap file dengan rilis sebelumnya (`patches/manifest-v*.json` terbaru);
3. menghasilkan:

```
drive-release/auth-engine/
├── source-code-full/            salinan lengkap source terbaru
├── auth-engine-full.zip         source lengkap (folder teratas: auth-engine/)
├── patches/
│   ├── patch-v1.0.1.zip         HANYA file yang berubah + PATCH-NOTES.txt
│   └── manifest-v1.0.1.json     hash file rilis ini (dasar patch berikutnya)
├── docs/
└── CHANGELOG.md
```

`vendor/`, `.git/`, `composer.lock`, `.env`, dan cache tidak ikut dipaketkan.

Opsi lain: `--base=DIR|ZIP|JSON` (bandingkan dengan folder source, `auth-engine-full.zip`, atau manifest tertentu),
`--force` (bangun ulang versi yang patch-nya sudah ada), `--help`.

## 4. Membuat `patch-v[x].zip` secara manual

Bila tidak bisa menjalankan skrip, dengan Git:

```bash
git diff --name-only --diff-filter=ACMR v1.0.0 HEAD > changed.txt
zip patches/patch-v1.0.1.zip -@ < changed.txt
git diff --name-only --diff-filter=D v1.0.0 HEAD      # file yang dihapus: catat di CHANGELOG
```

Tanpa Git: salin file yang Anda ubah ke folder kosong dengan struktur path yang sama, lalu `zip -r patch-v1.0.1.zip .`

## 5. Unggah ke Google Drive

Folder: `auth-engine` di https://drive.google.com/drive/folders/1dG46z0jfiknVnKXM6DFGyRsbA9lS88tF

| Aset | Tindakan |
|---|---|
| `auth-engine-full.zip`, `CHANGELOG.md`, `docs/`, `source-code-full/` | Ganti dengan yang baru |
| `patches/patch-v<versi>.zip`, `patches/manifest-v<versi>.json` | Tambahkan; patch lama dibiarkan |

Dengan [rclone](https://rclone.org/drive/) (sekali `rclone config`, remote bernama `gdrive`):

```bash
rclone sync drive-release/auth-engine gdrive:auth-engine --exclude "patches/**"
rclone copy drive-release/auth-engine/patches gdrive:auth-engine/patches
```

## 6. Menerapkan patch

```bash
unzip -o patch-v1.0.1.zip -d /path/ke/auth-engine
```

Lalu hapus file yang tercantum di bagian "Deleted files" pada `PATCH-NOTES.txt` (jika ada). Patch bersifat berurutan:
terapkan sesuai urutan versi.
