<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Laravel;

use Illuminate\Support\ServiceProvider;
use ReyhanItsMe\AuthEngine\Auth;
use ReyhanItsMe\AuthEngine\Core\AuthManager;
use ReyhanItsMe\AuthEngine\Core\ConfigLoader;

/**
 * Auto-discovered by Laravel (see composer.json "extra.laravel").
 *
 * The static entry point is aliased as "AuthEngine" to avoid clashing with
 * Laravel's own Auth facade:  AuthEngine::check()
 */
final class AuthEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(ConfigLoader::defaultPath(), 'auth-engine');

        $this->app->singleton(AuthManager::class, function ($app): AuthManager {
            $overrides = (array) $app->make('config')->get('auth-engine', []);
            $overrides['adapter'] = 'laravel';

            $config = ConfigLoader::load($overrides);
            $manager = new AuthManager($config, new LaravelAdapter($app, $config));

            Auth::setManager($manager);

            return $manager;
        });

        $this->app->alias(AuthManager::class, 'auth-engine');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                ConfigLoader::defaultPath() => $this->app->configPath('auth-engine.php'),
            ], 'auth-engine-config');

            $this->commands([InstallCommand::class]);
        }
    }
}
