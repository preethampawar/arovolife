<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Services\DraftStateService;
use Illuminate\Console\Command;

final class PurgeExpiredDraftsCommand extends Command
{
    protected $signature = 'drafts:purge';

    protected $description = 'Delete registration_drafts rows past their expires_at timestamp.';

    public function handle(DraftStateService $drafts): int
    {
        $count = $drafts->purgeExpired();
        $this->info("Purged {$count} expired registration draft(s).");

        return self::SUCCESS;
    }
}
