<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Identity\Models\RegistrationDraft;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;

final class FixRegistrationDraftSteps extends Command
{
    protected $signature = 'fix:registration-draft-steps {--dry-run : Show what would be fixed without making changes}';

    protected $description = 'Fix incorrect current_step values in registration drafts (Bug: step was being saved as step+1)';

    private const STEPS = [
        1 => 'sponsor_placement',
        2 => 'account',
        3 => 'orientation',
        4 => 'consent',
        5 => 'pan',
        6 => 'aadhaar',
        7 => 'bank',
        8 => 'personal',
        9 => 'documents',
        10 => 'complete',
    ];

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $drafts = RegistrationDraft::all();
        $fixed = 0;
        $errors = 0;

        $this->info('Analyzing ' . $drafts->count() . ' draft(s)...');

        foreach ($drafts as $draft) {
            try {
                $payload = json_decode(Crypt::decryptString($draft->payload_enc), true) ?? [];
                $calculatedStep = $this->calculateCorrectStep($payload);

                if ($calculatedStep !== $draft->current_step) {
                    $this->line(sprintf(
                        'User %d: current_step=%d (should be %d)',
                        $draft->user_id,
                        $draft->current_step,
                        $calculatedStep
                    ));

                    if (!$dryRun) {
                        $draft->update(['current_step' => $calculatedStep]);
                    }

                    $fixed++;
                }
            } catch (\Exception $e) {
                $this->error('Error processing draft ' . $draft->id . ': ' . $e->getMessage());
                $errors++;
            }
        }

        $mode = $dryRun ? '(DRY RUN)' : '';
        $this->info("\n✓ Fixed {$fixed} draft(s) {$mode}");
        if ($errors > 0) {
            $this->warn("⚠ Encountered {$errors} error(s)");
        }

        return 0;
    }

    private function calculateCorrectStep(array $payload): int
    {
        // Find the highest step that has data in the payload
        $highestStep = 2; // Steps 1-2 are implicit (referral link + account creation)

        for ($step = 3; $step <= 10; $step++) {
            $stepKey = self::STEPS[$step] ?? null;
            if ($stepKey && isset($payload[$stepKey]) && !empty($payload[$stepKey])) {
                $highestStep = $step;
            }
        }

        // current_step should be the next step to display (after the highest completed step)
        return $highestStep + 1;
    }
}
