<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Console;

use ReyhanItsMe\AuthEngine\Core\ConfigLoader;
use ReyhanItsMe\AuthEngine\Core\Env;
use ReyhanItsMe\AuthEngine\Core\Version;
use ReyhanItsMe\AuthEngine\Database\Connection;
use ReyhanItsMe\AuthEngine\Database\Schema;
use ReyhanItsMe\AuthEngine\UI\Scaffolder;
use Throwable;

/**
 * Dependency-free console kernel behind `vendor/bin/easy-auth`.
 * Commands are simple callables: fn (list<string> $args): int.
 */
final class Application
{
    /** @var array<string, array{description: string, handler: callable(list<string>): int}> */
    private array $commands = [];

    /** @var resource */
    private $output;

    /** @var resource */
    private $input;

    private readonly string $cwd;

    /**
     * @param resource|null $output stream to write to (defaults to STDOUT)
     * @param resource|null $input  stream prompts read from (defaults to STDIN)
     * @param string|null   $cwd    project root the commands work in (defaults to getcwd())
     */
    public function __construct($output = null, private readonly bool $colors = true, $input = null, ?string $cwd = null)
    {
        $this->output = $output ?? STDOUT;
        $this->input = $input ?? STDIN;
        $this->cwd = rtrim($cwd ?? (getcwd() ?: '.'), '/\\');

        $this->register('list', 'Show all available commands', fn (): int => $this->showList());
        $this->register('about', 'Show version, defaults and enabled drivers', fn (): int => $this->showAbout());
        $this->register('install:ui', 'Scaffold the auth UI components [--stack=] [--path=] [--force]', $this->installUi(...));
        $this->register('key:generate', 'Generate AUTH_ENGINE_KEY [--show] [--force]', $this->generateKey(...));
        $this->register('migrate', 'Create the database tables [--config=] [--pretend]', $this->migrate(...));
    }

    /** @param callable(list<string>): int $handler */
    public function register(string $name, string $description, callable $handler): void
    {
        $this->commands[$name] = ['description' => $description, 'handler' => $handler];
    }

    /** @param list<string> $argv raw argv including the script name */
    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'list';
        $args = array_slice($argv, 2);

        if (in_array($command, ['-V', '--version'], true)) {
            $this->line('easy-auth <g>' . Version::VERSION . '</g>');

            return 0;
        }

        if (in_array($command, ['-h', '--help', 'help'], true)) {
            $command = 'list';
        }

        if (!isset($this->commands[$command])) {
            $this->line(sprintf('<r>Command "%s" is not defined.</r>', $command));
            $this->line('');
            $this->showList();

            return 1;
        }

        try {
            return ($this->commands[$command]['handler'])($args);
        } catch (Throwable $e) {
            $this->line('<r>Error:</r> ' . $e->getMessage());

            return 1;
        }
    }

    public function line(string $text): void
    {
        $codes = ['<g>' => "\033[32m", '<y>' => "\033[33m", '<r>' => "\033[31m", '<b>' => "\033[1m"];
        $closing = ['</g>', '</y>', '</r>', '</b>'];

        $text = $this->colors
            ? str_replace($closing, "\033[0m", strtr($text, $codes))
            : str_replace([...array_keys($codes), ...$closing], '', $text);

        fwrite($this->output, $text . PHP_EOL);
    }

    /** @param list<string> $args */
    private function installUi(array $args): int
    {
        $options = $this->options($args);
        $scaffolder = new Scaffolder();
        $stacks = $scaffolder->stacks();

        if (isset($options['stack'])) {
            $stack = $scaffolder->resolveStack((string) $options['stack']);
            if ($stack === null) {
                $this->line(sprintf('<r>Unknown stack "%s".</r> Choose one of: %s', $options['stack'], implode(', ', array_keys($stacks))));

                return 1;
            }
        } else {
            $default = $scaffolder->detectStack($this->cwd);
            $defaultNumber = (int) array_search($default, array_keys($stacks), true) + 1;

            $this->line('<b>Auth Engine — UI scaffolder</b>');
            $this->line('');
            $this->line('Which frontend stack do you use?');
            foreach (array_values($stacks) as $i => $label) {
                $this->line(sprintf('  <g>[%d]</g> %s', $i + 1, $label));
            }

            $stack = null;
            for ($try = 0; $try < 3 && $stack === null; $try++) {
                $answer = $this->ask(sprintf('Choice [%d]: ', $defaultNumber));
                $stack = $answer === null || $answer === '' ? $default : $scaffolder->resolveStack($answer);
                if ($stack === null) {
                    $this->line('<r>Please answer with a number from 1 to ' . count($stacks) . '.</r>');
                }
            }
            if ($stack === null) {
                return 1;
            }
        }

        $relative = isset($options['path']) && is_string($options['path']) && $options['path'] !== ''
            ? $options['path']
            : $scaffolder->defaultPath($stack, $this->cwd);
        $absolute = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $relative) === 1 ? $relative : $this->cwd . '/' . $relative;

        $results = $scaffolder->install($stack, $absolute, isset($options['force']));

        $this->line('');
        $this->line(sprintf('<b>%s</b> → %s', $stacks[$stack], $relative));
        $skipped = 0;
        foreach ($results as $result) {
            $color = $result['status'] === 'skipped' ? 'y' : 'g';
            $skipped += $result['status'] === 'skipped' ? 1 : 0;
            $this->line(sprintf('  <%1$s>%2$-11s</%1$s> %3$s', $color, $result['status'], $result['file']));
        }
        if ($skipped > 0) {
            $this->line('');
            $this->line(sprintf('<y>%d existing file(s) were kept.</y> Re-run with --force to overwrite them.', $skipped));
        }

        $this->line('');
        $this->line('<y>Next steps:</y>');
        foreach ($scaffolder->nextSteps($stack, rtrim($relative, '/\\')) as $step) {
            $this->line('  - ' . $step);
        }

        return 0;
    }

    /** @param list<string> $args */
    private function generateKey(array $args): int
    {
        $options = $this->options($args);
        $key = bin2hex(random_bytes(32));
        $envFile = $this->cwd . '/.env';

        if (isset($options['show']) || !is_file($envFile)) {
            $this->line($key);
            if (!isset($options['show'])) {
                $this->line('');
                $this->line('<y>No .env file found here.</y> Set the value above as AUTH_ENGINE_KEY (or "app.key" in the config).');
            }

            return 0;
        }

        $contents = (string) file_get_contents($envFile);

        if (preg_match('/^AUTH_ENGINE_KEY=(.*)$/m', $contents, $m) === 1) {
            if (trim($m[1], " \t\r\"'") !== '' && !isset($options['force'])) {
                $this->line('<y>AUTH_ENGINE_KEY is already set.</y> Replacing it invalidates pending magic links, OTP codes');
                $this->line('and stored TOTP secrets. Re-run with --force if that is what you want.');

                return 1;
            }
            $contents = (string) preg_replace('/^AUTH_ENGINE_KEY=.*$/m', 'AUTH_ENGINE_KEY=' . $key, $contents, 1);
        } else {
            $contents = rtrim($contents, "\r\n");
            $contents = ($contents === '' ? '' : $contents . PHP_EOL) . 'AUTH_ENGINE_KEY=' . $key . PHP_EOL;
        }

        file_put_contents($envFile, $contents, LOCK_EX);
        $this->line('<g>AUTH_ENGINE_KEY written to .env</g>');

        return 0;
    }

    /** @param list<string> $args */
    private function migrate(array $args): int
    {
        $options = $this->options($args);
        Env::loadFile($this->cwd . '/.env');

        $source = null;
        if (isset($options['config']) && is_string($options['config'])) {
            $source = preg_match('#^(/|[A-Za-z]:[\\\\/])#', $options['config']) === 1 ? $options['config'] : $this->cwd . '/' . $options['config'];
        } elseif (is_file($this->cwd . '/config/auth-engine.php') && !is_file($this->cwd . '/artisan')) {
            $source = $this->cwd . '/config/auth-engine.php';
        }

        $db = Connection::fromConfig(ConfigLoader::load($source));
        $statements = Schema::statements($db);

        if (isset($options['pretend'])) {
            foreach ($statements as $sql) {
                $this->line(trim((string) preg_replace('/\n\s{12}/', "\n", $sql)) . ';');
                $this->line('');
            }

            return 0;
        }

        Schema::install($db);
        $this->line(sprintf('<g>Database ready</g> (%s): %d statements executed, existing tables left untouched.', $db->driver(), count($statements)));

        return 0;
    }

    /**
     * Parses "--name" and "--name=value" arguments.
     *
     * @param list<string> $args
     * @return array<string, string|true>
     */
    private function options(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/', $arg, $m) === 1) {
                $options[$m[1]] = $m[2] ?? true;
            }
        }

        return $options;
    }

    private function ask(string $question): ?string
    {
        fwrite($this->output, $question);
        $answer = fgets($this->input);

        return $answer === false ? null : trim($answer);
    }

    private function showList(): int
    {
        $this->line('<b>Auth Engine</b> <g>' . Version::VERSION . '</g>');
        $this->line('');
        $this->line('<y>Usage:</y> easy-auth <command> [arguments]');
        $this->line('');
        $this->line('<y>Commands:</y>');

        foreach ($this->commands as $name => $command) {
            $this->line(sprintf('  <g>%-13s</g> %s', $name, $command['description']));
        }

        return 0;
    }

    private function showAbout(): int
    {
        $config = ConfigLoader::load();

        $this->line('<b>Auth Engine</b>');
        $this->line(sprintf('  %-16s %s', 'Version', Version::VERSION));
        $this->line(sprintf('  %-16s %s', 'PHP', PHP_VERSION));
        $this->line(sprintf('  %-16s %s', 'Default config', ConfigLoader::defaultPath()));
        $this->line(sprintf('  %-16s %s', 'Identifiers', implode(', ', (array) $config->get('identifiers'))));
        $this->line(sprintf('  %-16s %s', 'Hashing', (string) $config->get('hashing.algo')));
        $this->line(sprintf('  %-16s %s', 'Default drivers', implode(', ', $config->enabledDrivers())));

        return 0;
    }
}
