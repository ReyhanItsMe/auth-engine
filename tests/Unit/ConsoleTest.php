<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Tests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use ReyhanItsMe\AuthEngine\Console\Application;
use ReyhanItsMe\AuthEngine\Core\Env;
use ReyhanItsMe\AuthEngine\UI\Scaffolder;

final class ConsoleTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/auth-engine-cli-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
        putenv('AUTH_ENGINE_DB_DSN');
        unset($_ENV['AUTH_ENGINE_DB_DSN'], $_ENV['AE_TEST_PLAIN'], $_ENV['AE_TEST_QUOTED'], $_ENV['AE_TEST_SINGLE']);
        putenv('AE_TEST_PLAIN');
        putenv('AE_TEST_QUOTED');
        putenv('AE_TEST_SINGLE');
    }

    /**
     * @param list<string> $args
     * @return array{0: int, 1: string}
     */
    private function run_(array $args, string $stdin = ''): array
    {
        $out = fopen('php://memory', 'w+');
        $in = fopen('php://memory', 'w+');
        fwrite($in, $stdin);
        rewind($in);

        $code = (new Application($out, false, $in, $this->dir))->run(['easy-auth', ...$args]);
        rewind($out);

        return [$code, (string) stream_get_contents($out)];
    }

    private function remove(string $path): void
    {
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->remove($path . '/' . $entry);
                }
            }
            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    public function testEveryStackShipsAllFiveComponents(): void
    {
        $scaffolder = new Scaffolder();
        $this->assertSame(['vanilla', 'blade', 'react-jsx', 'react-tsx'], array_keys($scaffolder->stacks()));

        $expected = [
            'vanilla' => fn (string $c): string => $c . '.php',
            'blade' => fn (string $c): string => strtolower((string) preg_replace('/(?<!^)[A-Z]/', '-$0', $c)) . '.blade.php',
            'react-jsx' => fn (string $c): string => $c . '.jsx',
            'react-tsx' => fn (string $c): string => $c . '.tsx',
        ];

        foreach ($expected as $stack => $name) {
            $results = $scaffolder->install($stack, $this->dir . '/' . $stack);
            $files = array_column($results, 'file');

            foreach (Scaffolder::COMPONENTS as $component) {
                $this->assertContains($name($component), $files, "{$stack} is missing {$component}");
            }
            foreach ($results as $result) {
                $this->assertSame('created', $result['status']);
                $this->assertFileExists($result['path']);
                $this->assertGreaterThan(200, filesize($result['path']));
            }
        }
    }

    public function testPhpStubsAreStrictAndEscapeOutput(): void
    {
        $scaffolder = new Scaffolder();
        $scaffolder->install('vanilla', $this->dir);

        foreach (Scaffolder::COMPONENTS as $component) {
            $source = (string) file_get_contents("{$this->dir}/{$component}.php");
            $this->assertStringContainsString('declare(strict_types=1);', $source);
            require_once "{$this->dir}/{$component}.php";
        }

        $form = \CredentialForm([
            'action' => '/login',
            'old' => ['identifier' => '"><script>alert(1)</script>'],
            'errors' => ['form' => '<b>bad</b>'],
            'csrfToken' => 'tok"en',
        ]);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $form);
        $this->assertStringNotContainsString('<b>bad</b>', $form);
        $this->assertStringContainsString('value="tok&quot;en"', $form);
        $this->assertStringContainsString('autocomplete="current-password"', $form);
        $this->assertStringContainsString('autocomplete="new-password"', \CredentialForm(['mode' => 'register']));

        $row = \SocialLoginRow(['providers' => [
            ['key' => 'x', 'name' => 'A & B', 'color' => 'red;background:url(evil)', 'icon' => '<svg></svg>', 'url' => '/go?a=1&b=2'],
        ]]);
        $this->assertStringContainsString('A &amp; B', $row);
        $this->assertStringContainsString('href="/go?a=1&amp;b=2"', $row);
        $this->assertStringContainsString('<svg></svg>', $row);
        $this->assertStringNotContainsString('evil', $row);
        $this->assertSame('', \SocialLoginRow(['providers' => []]));

        $card = \AuthCard(['title' => '<i>Hi</i>'], '<p>slot</p>');
        $this->assertStringContainsString('&lt;i&gt;Hi&lt;/i&gt;', $card);
        $this->assertStringContainsString('<p>slot</p>', $card);

        $mfa = \MfaSetupForm(['secret' => 'ABC', 'uri' => 'otpauth://totp/A?secret=ABC&issuer=A']);
        $this->assertStringContainsString('data-auth-qr="otpauth://totp/A?secret=ABC&amp;issuer=A"', $mfa);
        $this->assertStringContainsString('integrity="sha384-', $mfa);
        $codes = \MfaSetupForm(['recoveryCodes' => ['aaaaa-bbbbb']]);
        $this->assertStringContainsString('aaaaa-bbbbb', $codes);
        $this->assertStringNotContainsString('<form', $codes);

        $passkey = \PasskeyButton(['optionsUrl' => '/o', 'verifyUrl' => '/v', 'mode' => 'register']);
        $this->assertStringContainsString('data-mode="register"', $passkey);
        $this->assertStringContainsString('navigator.credentials.create', $passkey);
    }

    public function testInstallKeepsExistingFilesUnlessForced(): void
    {
        $scaffolder = new Scaffolder();
        $scaffolder->install('react-tsx', $this->dir);
        file_put_contents($this->dir . '/AuthCard.tsx', 'mine');

        $again = array_column($scaffolder->install('react-tsx', $this->dir), 'status', 'file');
        $this->assertSame('skipped', $again['AuthCard.tsx']);
        $this->assertSame('mine', file_get_contents($this->dir . '/AuthCard.tsx'));

        $forced = array_column($scaffolder->install('react-tsx', $this->dir, true), 'status', 'file');
        $this->assertSame('overwritten', $forced['AuthCard.tsx']);
        $this->assertStringContainsString('export function AuthCard', (string) file_get_contents($this->dir . '/AuthCard.tsx'));
    }

    public function testStackResolutionDetectionAndDefaults(): void
    {
        $scaffolder = new Scaffolder();

        $this->assertSame('vanilla', $scaffolder->resolveStack('1'));
        $this->assertSame('react-tsx', $scaffolder->resolveStack('4'));
        $this->assertSame('react-tsx', $scaffolder->resolveStack(' TSX '));
        $this->assertSame('blade', $scaffolder->resolveStack('laravel'));
        $this->assertNull($scaffolder->resolveStack('5'));
        $this->assertNull($scaffolder->resolveStack('vue'));

        $this->assertSame('vanilla', $scaffolder->detectStack($this->dir));
        $this->assertSame('src/components/auth', $scaffolder->defaultPath('react-jsx', $this->dir));

        touch($this->dir . '/artisan');
        mkdir($this->dir . '/resources/js', 0775, true);
        $this->assertSame('blade', $scaffolder->detectStack($this->dir));
        $this->assertSame('resources/views/components/auth', $scaffolder->defaultPath('blade', $this->dir));
        $this->assertSame('resources/js/components/auth', $scaffolder->defaultPath('react-tsx', $this->dir));

        file_put_contents($this->dir . '/package.json', '{"dependencies":{"react":"^18"}}');
        $this->assertSame('react-jsx', $scaffolder->detectStack($this->dir));
        touch($this->dir . '/tsconfig.json');
        $this->assertSame('react-tsx', $scaffolder->detectStack($this->dir));

        $this->expectException(InvalidArgumentException::class);
        $scaffolder->install('vue', $this->dir);
    }

    public function testInstallUiAsksForTheStackInteractively(): void
    {
        [$code, $output] = $this->run_(['install:ui'], "9\n4\n");

        $this->assertSame(0, $code);
        $this->assertStringContainsString('Which frontend stack do you use?', $output);
        $this->assertStringContainsString('[1] Vanilla PHP (.php)', $output);
        $this->assertStringContainsString('[2] Laravel Blade (.blade.php)', $output);
        $this->assertStringContainsString('[3] React (.jsx)', $output);
        $this->assertStringContainsString('[4] React + TypeScript (.tsx)', $output);
        $this->assertStringContainsString('Please answer with a number', $output);
        $this->assertFileExists($this->dir . '/src/components/auth/PasskeyButton.tsx');
    }

    public function testInstallUiUsesDetectedDefaultOnEmptyAnswerAndAcceptsOptions(): void
    {
        [$code] = $this->run_(['install:ui'], "\n");
        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/views/auth/AuthCard.php');

        [$code, $output] = $this->run_(['install:ui', '--stack=blade', '--path=ui/auth']);
        $this->assertSame(0, $code);
        $this->assertStringNotContainsString('Which frontend stack', $output);
        $this->assertFileExists($this->dir . '/ui/auth/social-login-row.blade.php');

        [$code, $output] = $this->run_(['install:ui', '--stack=blade', '--path=ui/auth']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('existing file(s) were kept', $output);

        [$code, $output] = $this->run_(['install:ui', '--stack=svelte']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('Unknown stack "svelte"', $output);
    }

    public function testInstallUiFailsCleanlyWithoutInput(): void
    {
        // No answer at all (closed stdin) falls back to the detected default instead of looping.
        [$code] = $this->run_(['install:ui'], '');
        $this->assertSame(0, $code);
        $this->assertFileExists($this->dir . '/views/auth/AuthCard.php');
    }

    public function testKeyGeneratePrintsOrWritesDotEnv(): void
    {
        [$code, $output] = $this->run_(['key:generate', '--show']);
        $this->assertSame(0, $code);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', trim($output));

        [$code, $output] = $this->run_(['key:generate']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('No .env file found', $output);

        file_put_contents($this->dir . '/.env', "APP_NAME=Demo\nAUTH_ENGINE_KEY=\nOTHER=1\n");
        [$code] = $this->run_(['key:generate']);
        $this->assertSame(0, $code);
        $env = (string) file_get_contents($this->dir . '/.env');
        $this->assertMatchesRegularExpression('/^APP_NAME=Demo\nAUTH_ENGINE_KEY=[0-9a-f]{64}\nOTHER=1\n$/', $env);

        [$code, $output] = $this->run_(['key:generate']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('already set', $output);
        $this->assertSame($env, file_get_contents($this->dir . '/.env'));

        [$code] = $this->run_(['key:generate', '--force']);
        $this->assertSame(0, $code);
        $this->assertNotSame($env, file_get_contents($this->dir . '/.env'));

        file_put_contents($this->dir . '/.env', 'APP_NAME=Demo');
        $this->run_(['key:generate']);
        $this->assertMatchesRegularExpression('/^APP_NAME=Demo\nAUTH_ENGINE_KEY=[0-9a-f]{64}\n$/', (string) file_get_contents($this->dir . '/.env'));
    }

    public function testMigrateCreatesTablesFromDotEnv(): void
    {
        [$code, $output] = $this->run_(['migrate']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No database configured', $output);

        $database = $this->dir . '/app.sqlite';
        file_put_contents($this->dir . '/.env', "AUTH_ENGINE_DB_DSN=\"sqlite:{$database}\"\n");

        [$code, $output] = $this->run_(['migrate', '--pretend']);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('CREATE TABLE IF NOT EXISTS users', $output);

        [$code, $output] = $this->run_(['migrate']);
        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Database ready (sqlite)', $output);

        $tables = (new PDO('sqlite:' . $database))->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'auth_engine_tokens', 'auth_engine_rate_limits', 'auth_engine_mfa', 'auth_engine_passkeys', 'auth_engine_oauth_accounts'] as $table) {
            $this->assertContains($table, $tables);
        }

        [$code] = $this->run_(['migrate']);
        $this->assertSame(0, $code, 'migrate must be idempotent');
    }

    public function testEnvLoadFileParsesQuotesCommentsAndKeepsExistingValues(): void
    {
        $file = $this->dir . '/.env';
        file_put_contents($file, implode("\n", [
            '# comment',
            'AE_TEST_PLAIN=hello # trailing comment',
            'export AE_TEST_QUOTED="a b#c"',
            "AE_TEST_SINGLE='x y'",
            'not a pair',
        ]));

        $this->assertSame(3, Env::loadFile($file));
        $this->assertSame('hello', Env::get('AE_TEST_PLAIN'));
        $this->assertSame('a b#c', Env::get('AE_TEST_QUOTED'));
        $this->assertSame('x y', Env::get('AE_TEST_SINGLE'));

        file_put_contents($file, 'AE_TEST_PLAIN=changed');
        $this->assertSame(0, Env::loadFile($file));
        $this->assertSame('hello', Env::get('AE_TEST_PLAIN'));
        $this->assertSame(0, Env::loadFile($this->dir . '/missing.env'));
    }

    public function testListShowsAllCommands(): void
    {
        [$code, $output] = $this->run_(['list']);

        $this->assertSame(0, $code);
        foreach (['install:ui', 'key:generate', 'migrate', 'about'] as $command) {
            $this->assertStringContainsString($command, $output);
        }
    }
}
