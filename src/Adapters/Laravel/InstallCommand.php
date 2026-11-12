<?php

declare(strict_types=1);

namespace ReyhanItsMe\AuthEngine\Adapters\Laravel;

use Illuminate\Console\Command;
use ReyhanItsMe\AuthEngine\Core\AuthManager;

/**
 * php artisan auth-engine:install — creates the package tables on Laravel's default connection.
 */
final class InstallCommand extends Command
{
    /** @var string */
    protected $signature = 'auth-engine:install';

    /** @var string */
    protected $description = 'Create the Auth Engine database tables (existing tables are left untouched)';

    public function handle(AuthManager $auth): int
    {
        $auth->installSchema();
        $this->info('Auth Engine tables are ready.');

        return self::SUCCESS;
    }
}
