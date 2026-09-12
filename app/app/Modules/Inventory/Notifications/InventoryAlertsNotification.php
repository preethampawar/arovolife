<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Notifications;

use App\Modules\Catalog\Models\InventoryLevel;
use App\Modules\Inventory\Models\StockBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The daily low-stock / expiry digest (plan §7.3). Admin-only mail: SKUs,
 * warehouses and quantities, never a distributor and never an earnings
 * figure.
 */
final class InventoryAlertsNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  Collection<int, InventoryLevel>  $lowStock
     * @param  Collection<int, StockBatch>  $expiring
     * @param  Collection<int, StockBatch>  $expired
     */
    public function __construct(
        public readonly Collection $lowStock,
        public readonly Collection $expiring,
        public readonly Collection $expired,
        public readonly int $expiryAlertDays,
        public readonly string $reportsUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('Inventory alerts — '.$this->lowStock->count().' low stock, '.$this->expiring->count().' expiring, '.$this->expired->count().' expired')
            ->greeting('Inventory alerts — '.now('Asia/Kolkata')->format('d M Y'));

        $mail->line('**Low stock** (at or under reorder level)');

        if ($this->lowStock->isEmpty()) {
            $mail->line('Nothing to report.');
        } else {
            foreach ($this->lowStock as $level) {
                $sku = $level->variant->variant_sku;
                $name = $level->variant->product->name;
                $mail->line("- {$sku} {$name} @ {$level->warehouse_code}: available ".max(0, $level->on_hand - $level->reserved).', reorder level '.$level->reorder_level);
            }
        }

        $mail->line("**Expiring within {$this->expiryAlertDays} days**");

        if ($this->expiring->isEmpty()) {
            $mail->line('Nothing to report.');
        } else {
            foreach ($this->expiring as $batch) {
                $sku = $batch->variant->variant_sku;
                $mail->line("- {$sku} batch {$batch->batch_no} @ {$batch->warehouse_code}: qty {$batch->qty_on_hand}, expires {$batch->expiry_date?->format('d M Y')}");
            }
        }

        $mail->line('**Expired, still on hand**');

        if ($this->expired->isEmpty()) {
            $mail->line('Nothing to report.');
        } else {
            foreach ($this->expired as $batch) {
                $sku = $batch->variant->variant_sku;
                $mail->line("- {$sku} batch {$batch->batch_no} @ {$batch->warehouse_code}: qty {$batch->qty_on_hand}, expired {$batch->expiry_date?->format('d M Y')}");
            }
        }

        return $mail
            ->action('Open Inventory Reports', $this->reportsUrl)
            ->line('You will get this again in 7 days for anything still open; a resolved row drops out on its own.');
    }
}
