<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * The isolated end-to-end stack (docker compose profile `e2e`, http://localhost:8085).
 *
 * The e2e container shares the dev code mount, `.env` and `storage/`, so the
 * only things that make it safe are checked here and fail closed:
 *
 *  - it is switched on by the OS env flag `ARV_E2E=1`, never by a setting;
 *  - the CONNECTED database must be named `*_e2e` (read off the live
 *    connection, as tests/TestCase.php does, so a stray DB_URL cannot point
 *    the handle somewhere else while config still says `_e2e`);
 *  - it never runs in production.
 *
 * The frozen clock lives in {@see clockPath()}: every request and every e2e
 * command reads it, so money fixtures give the same figures on any real day.
 */
final class E2eMode
{
    /** The instant the base snapshot is built at (Asia/Kolkata). */
    public const BASE_CLOCK = '2026-06-01 09:00:00';

    public static function requested(): bool
    {
        $flag = $_SERVER['ARV_E2E'] ?? $_ENV['ARV_E2E'] ?? getenv('ARV_E2E');

        return $flag === '1' || $flag === 'true';
    }

    /**
     * @throws RuntimeException when the flag is on but the target is not provably the e2e database
     */
    public static function assertSafe(Application $app): string
    {
        if ($app->environment('production')) {
            throw new RuntimeException('REFUSING E2E MODE: APP_ENV is production.');
        }

        $database = (string) $app->make('db')->connection()->getDatabaseName();

        if (! str_ends_with($database, '_e2e')) {
            throw new RuntimeException(
                "REFUSING E2E MODE: the connected database is '{$database}'. "
                .'ARV_E2E=1 is only allowed on a database whose name ends in _e2e.'
            );
        }

        return $database;
    }

    public static function root(string $path = ''): string
    {
        return storage_path('framework/e2e'.($path === '' ? '' : '/'.ltrim($path, '/')));
    }

    public static function clockPath(): string
    {
        return self::root('clock.json');
    }

    /** The frozen instant, or null when no clock has been written yet. */
    public static function readClock(): ?Carbon
    {
        $path = self::clockPath();

        if (! is_file($path)) {
            return null;
        }

        /** @var array{now?: string}|null $data */
        $data = json_decode((string) file_get_contents($path), true);
        $now = $data['now'] ?? null;

        return is_string($now) && $now !== '' ? Carbon::parse($now) : null;
    }

    public static function writeClock(Carbon $instant): void
    {
        if (! is_dir(self::root())) {
            mkdir(self::root(), 0775, true);
        }

        file_put_contents(
            self::clockPath(),
            json_encode(['now' => $instant->toIso8601String()], JSON_THROW_ON_ERROR),
            LOCK_EX,
        );

        self::freeze($instant);
    }

    public static function freeze(?Carbon $instant): void
    {
        Carbon::setTestNow($instant);
        CarbonImmutable::setTestNow($instant?->toImmutable());
        \Carbon\Carbon::setTestNow($instant);
    }

    public static function parseInstant(string $value): Carbon
    {
        return Carbon::parse($value, (string) config('app.timezone', 'Asia/Kolkata'));
    }
}
