<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Console\Commands;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Identity\Models\User;
use App\Modules\Inventory\Models\StockBatch;
use App\Modules\Inventory\Notifications\InventoryAlertsNotification;
use App\Modules\Inventory\Services\InventoryAlertService;
use App\Modules\Inventory\Services\InventorySettings;
use App\Modules\Shared\Features\InventoryFeature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;

/**
 * Plan §7.3 — the daily low-stock / expiry email.
 *
 * Re-alerting is throttled per row, not per run: a batch or level that was
 * already mailed within the last 7 days is left out of today's mail even if
 * it is still low/expiring, so the same shortage does not land in the inbox
 * every morning. `expired` batches are not throttled by a separate stamp —
 * they reuse `expiry_alerted_at` (a batch that is already expiring gets one
 * stamp, whichever alert fires first).
 *
 * Gated by InventoryFeature, same killswitch as checkout enforcement: OFF
 * means "stock is still recorded, but nothing blocks or alerts on it yet".
 */
final class InventoryAlertsCommand extends Command
{
    private const RESEND_AFTER_DAYS = 7;

    protected $signature = 'inventory:alerts';

    protected $description = 'Email low-stock and batch-expiry alerts, throttled to once per row per week.';

    public function handle(InventoryAlertService $alerts, InventorySettings $settings): int
    {
        if (! Feature::for(null)->active(InventoryFeature::class)) {
            $this->components->info('InventoryFeature is off — no alerts sent.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays(self::RESEND_AFTER_DAYS);

        $lowStock = $alerts->lowStock()->filter(
            fn (InventoryLevel $level): bool => $level->low_stock_alerted_at === null || $level->low_stock_alerted_at->lt($cutoff)
        )->values();

        $expiring = $alerts->expiring($settings->expiryAlertDays())->filter(
            fn (StockBatch $batch): bool => $batch->expiry_alerted_at === null || $batch->expiry_alerted_at->lt($cutoff)
        )->values();

        $expired = $alerts->expired()->filter(
            fn (StockBatch $batch): bool => $batch->expiry_alerted_at === null || $batch->expiry_alerted_at->lt($cutoff)
        )->values();

        if ($lowStock->isEmpty() && $expiring->isEmpty() && $expired->isEmpty()) {
            $this->components->info('Nothing new to alert on.');

            return self::SUCCESS;
        }

        $recipient = $settings->alertEmail() ?? $this->fallbackAdminEmail();

        if ($recipient === null) {
            $this->components->warn('No inventory.alert_email set and no admin user found — alert not sent.');

            return self::SUCCESS;
        }

        Notification::route('mail', $recipient)->notify(new InventoryAlertsNotification(
            lowStock: $lowStock,
            expiring: $expiring,
            expired: $expired,
            expiryAlertDays: $settings->expiryAlertDays(),
            reportsUrl: route('admin.inventory.reports.low-stock'),
        ));

        $now = Carbon::now();

        InventoryLevel::query()
            ->whereIn('id', $lowStock->pluck('id'))
            ->update(['low_stock_alerted_at' => $now]);

        StockBatch::query()
            ->whereIn('id', $expiring->pluck('id')->merge($expired->pluck('id'))->unique())
            ->update(['expiry_alerted_at' => $now]);

        $this->components->info(sprintf(
            'Alert sent to %s — %d low stock, %d expiring, %d expired.',
            $recipient, $lowStock->count(), $expiring->count(), $expired->count(),
        ));

        return self::SUCCESS;
    }

    private function fallbackAdminEmail(): ?string
    {
        return User::query()->where('email', 'admin@arovolife.test')->value('email');
    }
}
