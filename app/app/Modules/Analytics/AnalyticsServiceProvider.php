<?php

declare(strict_types=1);

namespace App\Modules\Analytics;

use Illuminate\Support\ServiceProvider;

/**
 * Analytics — read-only reporting over data the other modules already own.
 *
 * Deliberately holds no tables and no migrations. Analytics that maintains its
 * own copy of the numbers is analytics that will one day disagree with the
 * system it describes, and the report is always the one people believe.
 */
final class AnalyticsServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void {}
}
