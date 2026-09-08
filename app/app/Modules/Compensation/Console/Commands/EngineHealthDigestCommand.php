<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Console\Commands;

use App\Modules\Compensation\Notifications\EngineHealthDigestNotification;
use App\Modules\Compensation\Services\EngineHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The daily backstop for engine failures nobody is watching.
 *
 * A failed run raises a badge inside the admin console and nothing else; a
 * scheduled run that never happened leaves no row at all, so not even the badge
 * appears. Once a day, after every overnight engine, this looks for all three
 * shapes of trouble and emails the configured mailbox — and only then. A
 * healthy day sends nothing, so an email arriving is itself the signal.
 *
 * The recipient is the `notifications.engine_health_email` setting; a blank or
 * invalid value turns the digest off (the gate and the address are the same
 * setting, exactly as for the admin new-order alert).
 */
final class EngineHealthDigestCommand extends Command
{
    protected $signature = 'compensation:engine-health-digest
                            {--always : Send even when every engine is healthy (mailbox test)}
                            {--dry-run : Print the report, send nothing}';

    protected $description = 'Email the admin mailbox the compensation engine failures, missed runs and stuck runs';

    public function handle(EngineHealthService $health): int
    {
        $report = $health->report(Carbon::now('Asia/Kolkata'));

        $this->renderSection('Failed runs', ['Engine', 'Period', 'Failed at', 'Error'], array_map(
            static fn (array $item): array => [$item['engine'], $item['period'], $item['started_at'], $item['error']],
            $report->failures,
        ));

        $this->renderSection('Scheduled runs that did not happen', ['Engine', 'Period', 'Was due'], array_map(
            static fn (array $item): array => [$item['engine'], $item['period'], $item['due_at']],
            $report->missing,
        ));

        $this->renderSection('Runs that appear stuck', ['Engine', 'Period', 'Running since'], array_map(
            static fn (array $item): array => [$item['engine'], $item['period'], $item['started_at']],
            $report->stuck,
        ));

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        $recipient = (string) DB::table('settings')
            ->where('key', 'notifications.engine_health_email')
            ->value('value');

        if (! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->warn('notifications.engine_health_email is not set — digest not sent.');

            return self::SUCCESS;
        }

        if ($report->isHealthy() && ! $this->option('always')) {
            $this->info('All engines healthy — nothing sent.');

            return self::SUCCESS;
        }

        Notification::route('mail', $recipient)->notify(new EngineHealthDigestNotification(
            report: $report,
            engineRunsUrl: route('admin.compensation.engine-runs.index'),
        ));

        Log::info('compensation.engine_health.digest_sent', [
            'failures' => count($report->failures),
            'missing' => count($report->missing),
            'stuck' => count($report->stuck),
        ]);

        $this->info("Digest sent — {$report->total()} item(s).");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function renderSection(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->line("<comment>{$title}</comment>");

        if ($rows === []) {
            $this->line('  nothing to report');

            return;
        }

        $this->table($headers, $rows);
    }
}
