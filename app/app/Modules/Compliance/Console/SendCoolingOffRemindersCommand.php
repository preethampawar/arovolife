<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console;

use App\Modules\Compliance\Services\SendCoolingOffReminders;
use Illuminate\Console\Command;

final class SendCoolingOffRemindersCommand extends Command
{
    protected $signature = 'cooling-off:remind';

    protected $description = 'Send the statutory D-20 / D-7 / D-1 cooling-off reminder emails. Idempotent — safe to re-run.';

    public function handle(SendCoolingOffReminders $service): int
    {
        $sent = $service();
        $this->info("Cooling-off reminders sent: {$sent}");

        return self::SUCCESS;
    }
}
