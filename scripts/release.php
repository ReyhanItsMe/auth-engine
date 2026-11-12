<?php

declare(strict_types=1);

/*
 * Release packager — builds the Google Drive backup structure:
 *
 *   <out>/auth-engine/
 *   ├── source-code-full/        full, clean copy of the source
 *   ├── auth-engine-full.zip     the same tree, zipped (top-level folder "auth-engine/")
 *   ├── patches/
 *   │   ├── patch-v<version>.zip     ONLY the files changed since the previous release
 *   │   └── manifest-v<version>.json file hashes of this release (base of the next patch)
 *   ├── docs/                    documentation / API reference
 *   └── CHANGELOG.md
 *
 * Usage:
 *   php scripts/release.php [--out=DIR] [--base=DIR|ZIP|MANIFEST] [--skip-tests] [--force]
 *
 *   --out         where the "auth-engine" release folder lives (default: ../drive-release)
 *   --base        what to diff against: a source directory, an auth-engine-full.zip or a
 *                 manifest-v*.json. Default: the newest older manifest in <out>/auth-engine/patches,
 *                 else the existing <out>/auth-engine/source-code-full.
 *   --skip-tests  do not run lint + PHPUnit first
 *   --force       rebuild a version whose patch zip already exists
 *
 * The version is read from src/Core/Version.php and must have a CHANGELOG.md entry.
 */

const EXCLUDED_DIRS = ['.git', 'vendor', 'node_modules', '.phpunit.cache', '.idea', '.vscode'];
const EXCLUDED_FILES = ['composer.lock', '.phpunit.result.cache', '.DS_Store', '.env'];

$root = dirname(__DIR__);
$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (preg_match('/^--([a-z-]+)(?:=(.*))?$/', $arg, $m) === 1) {
        $options[$m[1]] = $m[2] ?? true;
    } else {
        fail("Unknown argument: {$arg}");
    }
}

if (isset($options['help'])) {
    preg_match('#/\*(.*?)\*/#s', (string) file_get_contents(__FILE__), $doc);
    echo trim((string) preg_replace('/^ \* ?/m', '', $doc[1] ?? '')), PHP_EOL;
    exit(0);
}

if (!class_exists(ZipArchive::class)) {
    fail('The PHP zip extension is required (ext-zip).');
}

// ---------------------------------------------------------------- version + changelog
if (preg_match("/VERSION\s*=\s*'([^']+)'/", (string) file_get_contents($root . '/src/Core/Version.php'), $m) !== 1) {
    fail('Could not read the version from src/Core/Version.php.');
}
$version = $m[1];

$changelog = (string) @file_get_contents($root . '/CHANGELOG.md');
if (preg_match('/^## \[' . preg_quote($version, '/') . '\]/m', $changelog) !== 1) {
    fail("CHANGELOG.md has no \"## [{$version}]\" entry. Document the release before packaging it.");
}

$out = rtrim(is_string($options['out'] ?? null) ? $options['out'] : $root . '/../drive-release', '/\\') . '/auth-engine';
$patchZip = "{$out}/patches/patch-v{$version}.zip";

if (is_file($patchZip) && !isset($options['force'])) {
    fail("patch-v{$version}.zip already exists. Bump src/Core/Version.php for a new release, or pass --force to rebuild.");
}

info("Packaging auth-engine v{$version}");

// ---------------------------------------------------------------- quality gate
if (!isset($options['skip-tests'])) {
    step('Lint');
    foreach (array_keys(manifest($root)) as $file) {
        if (str_ends_with($file, '.php') && !str_ends_with($file, '.blade.php') || $file === 'bin/easy-auth') {
            exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($root . '/' . $file) . ' 2>&1', $lines, $code);
            if ($code !== 0) {
                fail("Syntax error in {$file}:\n" . implode("\n", $lines));
            }
        }
    }

    step('PHPUnit');
    if (!is_file($root . '/vendor/bin/phpunit')) {
        fail('vendor/bin/phpunit not found. Run "composer install" or pass --skip-tests.');
    }
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/vendor/bin/phpunit') . ' --no-progress', $code);
    if ($code !== 0) {
        fail('Tests failed — nothing was packaged.');
    }
}

// ---------------------------------------------------------------- base manifest (previous release)
$current = manifest($root);
[$base, $baseLabel] = baseManifest($options['base'] ?? null, $out, $version);

$changed = [];
foreach ($current as $file => $hash) {
    if (!isset($base[$file]) || $base[$file] !== $hash) {
        $changed[$file] = isset($base[$file]) ? 'modified' : 'added';
    }
}
$deleted = array_keys(array_diff_key($base, $current));

// ---------------------------------------------------------------- build
step('source-code-full/');
removeTree("{$out}/source-code-full");
foreach (array_keys($current) as $file) {
    copyFile("{$root}/{$file}", "{$out}/source-code-full/{$file}");
}

step('auth-engine-full.zip');
writeZip("{$out}/auth-engine-full.zip", array_map(static fn (string $f): string => "{$root}/{$f}", array_combine(
    array_map(static fn (string $f): string => "auth-engine/{$f}", array_keys($current)),
    array_keys($current),
)));

step('docs/ + CHANGELOG.md');
removeTree("{$out}/docs");
foreach (array_keys($current) as $file) {
    if (str_starts_with($file, 'docs/')) {
        copyFile("{$root}/{$file}", "{$out}/{$file}");
    }
}
copyFile("{$root}/CHANGELOG.md", "{$out}/CHANGELOG.md");

step("patches/patch-v{$version}.zip");
$notes = "auth-engine patch v{$version}\n"
    . 'Built: ' . gmdate('Y-m-d H:i:s') . " UTC\n"
    . "Compared with: {$baseLabel}\n\n"
    . "Apply: unzip -o patch-v{$version}.zip -d /path/to/auth-engine\n"
    . "       then delete the files listed under \"Deleted\" (if any).\n\n"
    . 'Changed files (' . count($changed) . "):\n";
foreach ($changed as $file => $kind) {
    $notes .= sprintf("  %-9s %s\n", $kind, $file);
}
$notes .= "\nDeleted files (" . count($deleted) . "):\n";
foreach ($deleted as $file) {
    $notes .= "  {$file}\n";
}

$entries = [];
foreach (array_keys($changed) as $file) {
    $entries[$file] = "{$root}/{$file}";
}
@mkdir("{$out}/patches", 0775, true);
writeZip($patchZip, $entries, ['PATCH-NOTES.txt' => $notes]);

file_put_contents(
    "{$out}/patches/manifest-v{$version}.json",
    json_encode(['version' => $version, 'files' => $current], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
);

// ---------------------------------------------------------------- summary
info('');
info("Done → {$out}");
info(sprintf('  full source : %d files', count($current)));
info(sprintf('  patch       : %d changed, %d deleted (vs %s)', count($changed), count($deleted), $baseLabel));
info('');
info('Upload to Google Drive (folder "auth-engine"):');
info('  1. Replace  auth-engine-full.zip, CHANGELOG.md, docs/, source-code-full/');
info("  2. Add      patches/patch-v{$version}.zip  and  patches/manifest-v{$version}.json");
info('  Tip: with rclone →  rclone sync ' . $out . ' gdrive:auth-engine --exclude "patches/**" \\');
info('                   && rclone copy ' . $out . '/patches gdrive:auth-engine/patches');

// ================================================================ helpers

/** @return array<string, string> relative path => sha256, sorted */
function manifest(string $dir): array
{
    $files = [];
    $walk = static function (string $path, string $prefix) use (&$walk, &$files): void {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "{$path}/{$entry}";
            if (is_link($full)) {
                continue;
            }
            if (is_dir($full)) {
                if (!in_array($entry, EXCLUDED_DIRS, true)) {
                    $walk($full, $prefix . $entry . '/');
                }
            } elseif (!in_array($entry, EXCLUDED_FILES, true)) {
                $files[$prefix . $entry] = (string) hash_file('sha256', $full);
            }
        }
    };
    $walk(rtrim($dir, '/\\'), '');
    ksort($files);

    return $files;
}

/**
 * @return array{0: array<string, string>, 1: string} manifest of the previous release and a label for it
 */
function baseManifest(mixed $base, string $out, string $version): array
{
    if (is_string($base) && $base !== '') {
        if (is_dir($base)) {
            return [manifest($base), $base];
        }
        if (is_file($base) && str_ends_with($base, '.json')) {
            return [readManifest($base), basename($base)];
        }
        if (is_file($base) && str_ends_with($base, '.zip')) {
            $zip = new ZipArchive();
            if ($zip->open($base) !== true) {
                fail("Cannot open {$base}.");
            }
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                $relative = (string) preg_replace('#^auth-engine/#', '', $name);
                $files[$relative] = hash('sha256', (string) $zip->getFromIndex($i));
            }
            $zip->close();
            ksort($files);

            return [$files, basename($base)];
        }
        fail("--base={$base} is not a directory, .zip or .json file.");
    }

    $best = null;
    foreach (glob("{$out}/patches/manifest-v*.json") ?: [] as $file) {
        $candidate = substr(basename($file, '.json'), strlen('manifest-v'));
        if (version_compare($candidate, $version, '<') && ($best === null || version_compare($candidate, $best, '>'))) {
            $best = $candidate;
        }
    }
    if ($best !== null) {
        return [readManifest("{$out}/patches/manifest-v{$best}.json"), "v{$best}"];
    }

    if (is_dir("{$out}/source-code-full")) {
        return [manifest("{$out}/source-code-full"), 'previous source-code-full'];
    }

    return [[], 'nothing (first release: the patch contains every file)'];
}

/** @return array<string, string> */
function readManifest(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true);
    if (!is_array($data) || !is_array($data['files'] ?? null)) {
        fail("{$file} is not a valid release manifest.");
    }

    return $data['files'];
}

/**
 * @param array<string, string> $files   name inside the zip => file on disk
 * @param array<string, string> $strings name inside the zip => literal content
 */
function writeZip(string $path, array $files, array $strings = []): void
{
    @unlink($path);
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail("Cannot write {$path}.");
    }
    foreach ($strings as $name => $content) {
        $zip->addFromString($name, $content);
    }
    foreach ($files as $name => $file) {
        $zip->addFile($file, $name);
        if (is_executable($file)) {
            $zip->setExternalAttributesName($name, ZipArchive::OPSYS_UNIX, 0o100755 << 16);
        }
    }
    if (!$zip->close()) {
        fail("Cannot finish {$path}.");
    }
}

function copyFile(string $from, string $to): void
{
    if (!is_dir(dirname($to)) && !@mkdir(dirname($to), 0775, true) && !is_dir(dirname($to))) {
        fail('Cannot create ' . dirname($to));
    }
    if (!copy($from, $to)) {
        fail("Cannot copy {$from}");
    }
    @chmod($to, fileperms($from) & 0o777);
}

function removeTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $full = "{$path}/{$entry}";
        is_dir($full) && !is_link($full) ? removeTree($full) : unlink($full);
    }
    rmdir($path);
}

function info(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step(string $message): void
{
    info("  • {$message}");
}

function fail(string $message): never
{
    fwrite(STDERR, "release: {$message}" . PHP_EOL);
    exit(1);
}
