<?php

declare(strict_types=1);

namespace App\Modules\Admin\Notifications;

use App\Modules\Identity\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Number;

/**
 * The daily database-backup result, one mail per admin / developer.
 *
 * Says nothing about who else got the mail or the role that manages restores —
 * that role is hidden from other staff — and links to the backups page only
 * for a recipient who can open it. Scalars only, so the queued payload
 * never carries a model.
 *
 * Queued on the DEFAULT queue, not `compensation` (ADR-0011).
 */
final class DatabaseBackupStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly bool $succeeded,
        public readonly string $environment,
        public readonly string $takenAt,
        public readonly ?string $file,
        public readonly ?int $bytes,
        public readonly float $durationSeconds,
        public readonly int $heldCount,
        public readonly int $prunedCount,
        public readonly ?string $error = null,
        public readonly ?int $settingsBytes = null,
        public readonly ?string $settingsError = null,
        public readonly ?string $filesLine = null,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** @return array<string, string> */
    public function viaQueues(): array
    {
        return ['mail' => 'default'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $duration = number_format($this->durationSeconds, 1).' seconds';

        if (! $this->succeeded) {
            return $this->withDownloadLink((new MailMessage)
                ->error()
                ->subject('Database backup FAILED')
                ->greeting('Database backup failed')
                ->line("The daily arovolife database backup on **{$this->environment}** failed at {$this->takenAt} IST, after {$duration}.")
                ->line('No new backup exists for today. The earlier backups are untouched and nothing was pruned.')
                ->line('Error: '.($this->error ?? 'unknown'))
                ->line("Backups currently held: {$this->heldCount}."), $notifiable);
        }

        $size = Number::fileSize((int) $this->bytes, 1);

        $mail = (new MailMessage)
            ->subject("Database backup succeeded — {$size}")
            ->greeting('Database backup succeeded')
            ->line("The daily arovolife database backup on **{$this->environment}** completed at {$this->takenAt} IST.")
            ->line('**What was backed up in this run**')
            ->line("Database: saved, {$size} compressed — {$this->file}");

        if ($this->settingsBytes !== null) {
            $mail->line('Settings file copy: saved ('.Number::fileSize($this->settingsBytes, 1).')');
        } elseif ($this->settingsError !== null) {
            $mail->line("**Settings file copy: NOT saved — {$this->settingsError}**");
        }

        if ($this->filesLine !== null) {
            $mail->line('Uploaded files: '.$this->filesLine);
        }

        $mail->line("Duration: {$duration}")
            ->line("Backups now held: {$this->heldCount}. Removed as older than the retention period: {$this->prunedCount}.");

        return $this->withDownloadLink($mail, $notifiable);
    }

    /**
     * The link to the backups page, only for a recipient who can open it.
     * Everyone else would get a 404 and learn that the page exists.
     */
    private function withDownloadLink(MailMessage $mail, object $notifiable): MailMessage
    {
        if ($notifiable instanceof User && $notifiable->hasAnyRole(['admin', 'developer'])) {
            $mail->action('Download backups', route('admin.database-backups.index'));
        }

        return $mail;
    }
}
