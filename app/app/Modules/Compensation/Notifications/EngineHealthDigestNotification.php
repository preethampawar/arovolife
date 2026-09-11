<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Notifications;

use App\Modules\Compensation\Services\DTOs\EngineHealthReport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * The daily engine-health digest for the admin mailbox.
 *
 * Admin-only mail: it names engines, periods and instructions, never a
 * distributor, never an amount. It is sent only when something needs a human,
 * so its arrival is itself the signal.
 *
 * Queued on the DEFAULT queue, not `compensation`: that queue is one process
 * with tries 1 reserved for the money path (ADR-0011), and a mail failure must
 * never sit in front of a bonus job.
 */
final class EngineHealthDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly EngineHealthReport $report,
        public readonly string $engineRunsUrl,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Explicit, so a change to the global default queue can never land this
     * mail on `compensation`.
     *
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'default'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $total = $this->report->total();

        $mail = (new MailMessage)
            ->subject($total === 0
                ? 'Compensation engines — all healthy'
                : "Compensation engines need attention — {$total} item(s)")
            ->greeting('Engine health — '.Carbon::today('Asia/Kolkata')->format('d M Y'))
            ->line($total === 0
                ? 'Every engine is healthy — nothing needs attention today.'
                : "{$total} item(s) need attention. Each one below tells you exactly what to do.");

        $position = 0;

        if ($this->report->failures !== []) {
            $mail->line('**Failed runs**');

            foreach ($this->report->failures as $item) {
                $position++;
                $mail->line("**{$position}. {$item['engine']} — {$item['period']}** (failed {$item['started_at']})");
                $mail->line("Error recorded: {$item['error']}");
                $this->appendSteps($mail, $item['steps']);
            }
        }

        if ($this->report->missing !== []) {
            $mail->line('**Scheduled runs that did not happen**');

            foreach ($this->report->missing as $item) {
                $position++;
                $mail->line("**{$position}. {$item['engine']} — {$item['period']}** (was due {$item['due_at']}, no run recorded)");
                $this->appendSteps($mail, $item['steps']);
            }
        }

        if ($this->report->prematureFreezes !== []) {
            $mail->line('**Periods priced against a pool frozen too early**');

            foreach ($this->report->prematureFreezes as $item) {
                $position++;
                $mail->line("**{$position}. {$item['engine']} — {$item['period']}** (pool frozen {$item['frozen_at']}, kept because money had already moved on it)");
                $this->appendSteps($mail, $item['steps']);
            }
        }

        if ($this->report->stuck !== []) {
            $mail->line('**Runs that appear stuck**');

            foreach ($this->report->stuck as $item) {
                $position++;
                $mail->line("**{$position}. {$item['engine']} — {$item['period']}** (running since {$item['started_at']})");
                $this->appendSteps($mail, $item['steps']);
            }
        }

        return $mail
            ->action('Open Engine Runs', $this->engineRunsUrl)
            ->line('A re-run never credits anybody twice: every engine only fills what the failed run left empty, and every trigger is audit-logged with your name and reason.')
            ->line('If a step fails again after one retry, stop and send the recorded error to the developer instead of retrying.')
            ->line('You will get this email again tomorrow at 08:00 IST if anything is still open; you get nothing on a healthy day.');
    }

    /**
     * @param  list<string>  $steps
     */
    private function appendSteps(MailMessage $mail, array $steps): void
    {
        $mail->line('What to do:');

        foreach ($steps as $index => $step) {
            $mail->line(($index + 1).'. '.$step);
        }
    }
}
