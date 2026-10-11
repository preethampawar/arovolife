<?php

declare(strict_types=1);

namespace App\Modules\Identity\Console\Commands;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Identity\Models\DistributorFamilyMember;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Erase family members registered as children once the year they turn 18 has
 * passed: the parent's consent covered a child, not the adult (DPDP Act 2023
 * §6(7), §8(7); R-117). The distributor can add them again with the adult
 * consent. Runs whether or not the Family Card flag is on.
 */
final class ExpireMinorFamilyConsentsCommand extends Command
{
    protected $signature = 'family:expire-minor-consents
        {--dry-run : Report what would be erased without touching anything}';

    protected $description = 'Erase family members registered as children after the year they turn 18';

    public function handle(): int
    {
        $year = (int) Carbon::now('Asia/Kolkata')->year;

        $expired = DistributorFamilyMember::query()
            ->where('is_minor', true)
            ->where('turns_18_year', '<', $year)
            ->orderBy('id')
            ->get(['id', 'distributor_id', 'relation', 'turns_18_year']);

        if ($expired->isEmpty()) {
            $this->info('No child family members past the year they turn 18.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->warn("DRY RUN — {$expired->count()} family member(s) would be erased.");

            return self::SUCCESS;
        }

        foreach ($expired as $member) {
            DB::transaction(function () use ($member): void {
                $member->delete();
                AuditLog::create([
                    'actor_id' => null,
                    'action' => 'family_member.minor_consent_expired',
                    'subject_type' => 'distributor',
                    'subject_id' => $member->distributor_id,
                    'details' => [
                        'family_member_id' => $member->id,
                        'relation' => $member->relation,
                        'turns_18_year' => $member->turns_18_year,
                    ],
                ]);
            });
        }

        $this->info("Erased {$expired->count()} family member(s).");

        return self::SUCCESS;
    }
}
