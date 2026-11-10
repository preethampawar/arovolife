<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Console;

use App\Modules\Shared\Testing\E2eDatabaseTemplate;
use App\Modules\Shared\Testing\E2eMode;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\Migrator;
use RuntimeException;

/**
 * Restores `arovolife_e2e` from the template, flushes the e2e cache database
 * (rate-limiter counters live there) and puts the clock back to the base
 * instant. Sessions live in a separate Redis database and survive, so a saved
 * Playwright login stays valid across resets.
 */
final class E2eResetCommand extends Command
{
    /** The only Redis database the e2e cache may flush (sessions sit in 5; dev and other projects elsewhere). */
    private const E2E_CACHE_REDIS_DATABASE = '6';

    protected $signature = 'e2e:reset
                            {--keep-clock : Leave the frozen clock where it is}
                            {--cache-only : Only flush the e2e cache (rate-limiter counters); data and clock untouched}';

    protected $description = 'Restore the e2e database from its template, flush the e2e cache and reset the clock';

    public function handle(E2eDatabaseTemplate $template): int
    {
        E2eMode::assertSafe($this->laravel);
        $started = microtime(true);

        if ($this->option('cache-only')) {
            $this->flushCache();
            $this->info('Flushed the e2e cache.');

            return self::SUCCESS;
        }

        $this->assertTemplateIsCurrent($template);
        $tables = $template->restore();
        $this->flushCache();

        if (! $this->option('keep-clock')) {
            E2eMode::writeClock(E2eMode::parseInstant(E2eMode::BASE_CLOCK));
        }

        $this->info(sprintf('Restored %d tables from %s (%.1fs).', $tables, $template->templateDatabase(), microtime(true) - $started));

        return self::SUCCESS;
    }

    /**
     * A template taken before a newer migration restores a schema the code no
     * longer matches. Checked against the TEMPLATE's migrations table before
     * anything is truncated, so a stale template refuses without side effects.
     */
    private function assertTemplateIsCurrent(E2eDatabaseTemplate $template): void
    {
        /** @var Migrator $migrator */
        $migrator = $this->laravel->make('migrator');
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_values(array_diff(array_keys($files), $template->templateMigrations()));

        if ($pending !== []) {
            throw new RuntimeException(sprintf(
                'The e2e template is behind by %d migration(s) (first: %s). Run `php artisan e2e:snapshot`.',
                count($pending),
                reset($pending),
            ));
        }
    }

    private function flushCache(): void
    {
        $store = (string) config('cache.default');

        if (config("cache.stores.{$store}.driver") === 'redis') {
            $connection = (string) config("cache.stores.{$store}.connection", 'default');
            $redisDb = (string) config("database.redis.{$connection}.database");
            $redisUrl = (string) config("database.redis.{$connection}.url");

            if ($redisUrl !== '' || $redisDb !== self::E2E_CACHE_REDIS_DATABASE) {
                throw new RuntimeException("Refusing to flush Redis database '{$redisDb}'".($redisUrl !== '' ? ' (REDIS_URL is set)' : '').': only database '.self::E2E_CACHE_REDIS_DATABASE.' belongs to the e2e cache.');
            }
        } else {
            throw new RuntimeException("Refusing to flush cache store '{$store}': the e2e stack expects the redis store on database ".self::E2E_CACHE_REDIS_DATABASE.'.');
        }

        $this->laravel->make('cache')->store()->flush();
    }
}
