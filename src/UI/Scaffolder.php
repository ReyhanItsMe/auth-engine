<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\UI;

use InvalidArgumentException;
use RuntimeException;

/**
 * Copies the UI component stubs of one frontend stack into a project.
 * Used by `vendor/bin/easy-auth install:ui`.
 */
final class Scaffolder
{
    public const COMPONENTS = ['AuthCard', 'CredentialForm', 'SocialLoginRow', 'MfaSetupForm', 'PasskeyButton'];

    private const STACKS = [
        'vanilla' => ['label' => 'Vanilla PHP (.php)', 'dir' => 'vanilla'],
        'blade' => ['label' => 'Laravel Blade (.blade.php)', 'dir' => 'blade'],
        'react-jsx' => ['label' => 'React (.jsx)', 'dir' => 'react-jsx'],
        'react-tsx' => ['label' => 'React + TypeScript (.tsx)', 'dir' => 'react-tsx'],
    ];

    private const ALIASES = [
        'php' => 'vanilla',
        'native' => 'vanilla',
        'laravel' => 'blade',
        'jsx' => 'react-jsx',
        'react' => 'react-jsx',
        'tsx' => 'react-tsx',
        'typescript' => 'react-tsx',
    ];

    private readonly string $stubsPath;

    public function __construct(?string $stubsPath = null)
    {
        $this->stubsPath = rtrim($stubsPath ?? dirname(__DIR__, 2) . '/stubs', '/\\');
    }

    /** @return array<string, string> stack key => human label */
    public function stacks(): array
    {
        return array_map(static fn (array $stack): string => $stack['label'], self::STACKS);
    }

    /** Resolves a key, an alias ("tsx", "laravel") or a 1-based menu number to a stack key. */
    public function resolveStack(string $input): ?string
    {
        $input = strtolower(trim($input));

        if (isset(self::STACKS[$input])) {
            return $input;
        }
        if (isset(self::ALIASES[$input])) {
            return self::ALIASES[$input];
        }
        if (ctype_digit($input)) {
            return array_keys(self::STACKS)[(int) $input - 1] ?? null;
        }

        return null;
    }

    /** Best guess of the stack a project uses, to preselect it in the prompt. */
    public function detectStack(string $projectRoot): string
    {
        $root = rtrim($projectRoot, '/\\');
        $package = is_file($root . '/package.json') ? (string) file_get_contents($root . '/package.json') : '';

        if (str_contains($package, '"react"')) {
            return is_file($root . '/tsconfig.json') || str_contains($package, '"typescript"') ? 'react-tsx' : 'react-jsx';
        }

        return is_file($root . '/artisan') ? 'blade' : 'vanilla';
    }

    /** Default destination (relative to the project root) of the component files. */
    public function defaultPath(string $stack, string $projectRoot): string
    {
        $root = rtrim($projectRoot, '/\\');

        return match ($this->assertStack($stack)) {
            'vanilla' => 'views/auth',
            'blade' => 'resources/views/components/auth',
            default => is_dir($root . '/resources/js') ? 'resources/js/components/auth' : 'src/components/auth',
        };
    }

    /**
     * Copies every stub of the stack into $targetDir (created when missing).
     * Existing files are kept unless $force is true.
     *
     * @return list<array{file: string, path: string, status: 'created'|'overwritten'|'skipped'}>
     */
    public function install(string $stack, string $targetDir, bool $force = false): array
    {
        $source = $this->stubsPath . '/' . self::STACKS[$this->assertStack($stack)]['dir'];
        $files = glob($source . '/*') ?: [];

        if ($files === []) {
            throw new RuntimeException(sprintf('No stubs found for stack "%s" in %s.', $stack, $source));
        }

        $targetDir = rtrim($targetDir, '/\\');
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new RuntimeException(sprintf('Unable to create directory %s.', $targetDir));
        }

        $results = [];

        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }

            $name = basename($file);
            $destination = $targetDir . '/' . $name;
            $exists = file_exists($destination);

            if ($exists && !$force) {
                $results[] = ['file' => $name, 'path' => $destination, 'status' => 'skipped'];
                continue;
            }

            if (!@copy($file, $destination)) {
                throw new RuntimeException(sprintf('Unable to write %s.', $destination));
            }

            $results[] = ['file' => $name, 'path' => $destination, 'status' => $exists ? 'overwritten' : 'created'];
        }

        return $results;
    }

    /** @return list<string> short "what now" hints for the given stack */
    public function nextSteps(string $stack, string $relativePath): array
    {
        return match ($this->assertStack($stack)) {
            'vanilla' => [
                "Include the components: require '{$relativePath}/AuthCard.php'; then echo AuthCard([...], \$html);",
                "See {$relativePath}/login-example.php for a complete sign-in page.",
                'Add this folder to the "content" paths of your Tailwind config.',
            ],
            'blade' => [
                'Use them as anonymous components: <x-auth.auth-card>, <x-auth.credential-form>, ...',
                "Move {$relativePath}/login-example.blade.php to resources/views/auth/ and point a route at it.",
                'Tailwind already scans resources/views in a default Laravel setup.',
            ],
            default => [
                "Import them: import { AuthCard } from './" . basename($relativePath) . "/AuthCard';",
                "See {$relativePath}/LoginExample." . ($stack === 'react-tsx' ? 'tsx' : 'jsx') . ' for a complete sign-in page.',
                'Pass Auth::getActiveProviders() from the backend to <SocialLoginRow providers={...} />.',
                'For the TOTP QR code: npm install qrcode.react (see the renderQr prop of MfaSetupForm).',
            ],
        };
    }

    private function assertStack(string $stack): string
    {
        if (!isset(self::STACKS[$stack])) {
            throw new InvalidArgumentException(sprintf(
                'Unknown stack "%s". Choose one of: %s.',
                $stack,
                implode(', ', array_keys(self::STACKS)),
            ));
        }

        return $stack;
    }
}
