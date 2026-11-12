<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters;

use ReyhanItsMe\AuthEngine\Adapters\Laravel\LaravelAdapter;
use ReyhanItsMe\AuthEngine\Adapters\Native\NativeAdapter;
use ReyhanItsMe\AuthEngine\Contracts\FrameworkAdapterInterface;
use ReyhanItsMe\AuthEngine\Core\Config;
use ReyhanItsMe\AuthEngine\Exceptions\ConfigurationException;

/**
 * Picks the framework adapter from the "adapter" config value
 * ("auto" detects a running Laravel application).
 */
final class AdapterResolver
{
    private function __construct()
    {
    }

    public static function resolve(Config $config): FrameworkAdapterInterface
    {
        $choice = (string) $config->get('adapter', 'auto');
        $laravel = self::laravelApplication();

        if ($choice === 'laravel' && $laravel === null) {
            throw new ConfigurationException(
                'Adapter "laravel" was requested but no running Laravel application was found.',
            );
        }

        if ($choice !== 'native' && $laravel !== null) {
            return new LaravelAdapter($laravel, $config);
        }

        return new NativeAdapter($config);
    }

    private static function laravelApplication(): ?object
    {
        if (!class_exists(\Illuminate\Foundation\Application::class) || !function_exists('app')) {
            return null;
        }

        $app = \app();

        return is_object($app) && method_exists($app, 'make') && $app->bound('session.store') ? $app : null;
    }
}
