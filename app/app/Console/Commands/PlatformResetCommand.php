<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Actions\PlatformResetAction;
use Illuminate\Console\Command;

final class PlatformResetCommand extends Command
{
    protected $signature = 'platform:reset {--force : Skip the interactive confirmation prompt}';

    protected $description = 'Wipe all transactional data + S3 KYC files, then re-seed roles, admin, settings, content, ledger, flags, and the 31 reserved company-blocked distributors.';

    public function handle(PlatformResetAction $action): int
    {
        if (! $this->option('force')) {
            $this->warn('THIS WILL WIPE THE DATABASE AND DELETE S3 KYC OBJECTS.');
            $this->line('Targets:');
            $this->line('  - All transactional tables (distributors, audit_log, kyc_documents, etc.)');
            $this->line('  - All users (admin will be re-created from seeder)');
            $this->line('  - S3 prefixes user_*/ in the configured s3 disk');
            $this->line('');
            if (! $this->confirm('Proceed with full platform reset?', false)) {
                $this->info('Aborted.');

                return self::FAILURE;
            }
        }

        $action->execute(function (string $message): void {
            $this->line($message);
        });

        $this->info('platform:reset complete.');

        return self::SUCCESS;
    }
}
