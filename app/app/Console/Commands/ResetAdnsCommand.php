<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * One-shot data migration: renumber every distributor in the table to the
 * new 9-digit numeric ADN format. The root distributor (the self-
 * referencing one) gets the seed value (default 111222333); the rest are
 * renumbered sequentially in ascending order of joining date
 * (effective_date ASC, id ASC as a tie-breaker).
 *
 * Couple-secondary records (those with a `-S` suffix on the legacy ADN
 * AND those linked via spouse_distributor_id) follow their primary —
 * each secondary's new ADN is `<new-primary-adn>-S`.
 *
 * Safe to run on a populated DB:
 *   - The whole renumber happens inside a single DB transaction.
 *   - First pass writes a temporary sentinel value (TMP_<pk>) to clear
 *     the old values from the unique index, second pass writes the
 *     final ADNs. This avoids "Duplicate entry" errors when the new
 *     ADN of one row collides with the old ADN of another.
 *   - Idempotent: re-running on a fully-renumbered DB is a no-op.
 *
 * Run on the staging server with:
 *
 *     php artisan app:reset-adns --dry-run     # preview
 *     php artisan app:reset-adns               # apply
 */
final class ResetAdnsCommand extends Command
{
    protected $signature = 'app:reset-adns
        {--dry-run : Show the renumber plan without writing}
        {--root=111222333 : Starting ADN for the root distributor}';

    protected $description = 'Renumber existing distributors to the 9-digit numeric ADN format.';

    public function handle(): int
    {
        $rootAdn = (int) $this->option('root');
        $dryRun = (bool) $this->option('dry-run');

        if ($rootAdn < 1 || $rootAdn > 999_999_999) {
            $this->error('Root ADN must be a 9-digit positive integer (1 to 999999999).');

            return self::FAILURE;
        }

        // Order: root (self-referencing) first, then primaries by
        // effective_date ASC, id ASC as a tie-breaker. Couple-secondaries
        // are attached to their primary regardless of date.
        $primaries = DB::table('distributors')
            ->whereRaw('(spouse_distributor_id IS NULL OR is_primary_couple = 1)')
            ->orderByRaw('CASE WHEN sponsor_id = id THEN 0 ELSE 1 END')
            ->orderBy('effective_date')
            ->orderBy('id')
            ->select('id', 'adn', 'effective_date', 'spouse_distributor_id', 'is_primary_couple', 'sponsor_id')
            ->get();

        if ($primaries->isEmpty()) {
            $this->info('No primary distributors found. Nothing to do.');

            return self::SUCCESS;
        }

        // Build the rename plan: [oldAdn, newAdn] tuples for primary +
        // (where applicable) the spouse secondary.
        $plan = [];
        foreach ($primaries as $i => $primary) {
            $newPrimaryAdn = (string) ($rootAdn + $i);
            $plan[] = [
                'id' => (int) $primary->id,
                'old' => (string) $primary->adn,
                'new' => $newPrimaryAdn,
                'kind' => $i === 0 && (int) $primary->sponsor_id === (int) $primary->id ? 'root' : 'primary',
            ];

            if ($primary->is_primary_couple === 1 && $primary->spouse_distributor_id !== null) {
                $secondary = DB::table('distributors')
                    ->where('id', $primary->spouse_distributor_id)
                    ->first(['id', 'adn']);
                if ($secondary !== null) {
                    $plan[] = [
                        'id' => (int) $secondary->id,
                        'old' => (string) $secondary->adn,
                        'new' => $newPrimaryAdn.'-S',
                        'kind' => 'spouse',
                    ];
                }
            }
        }

        // Filter no-op rows.
        $changes = array_values(array_filter($plan, fn ($p) => $p['old'] !== $p['new']));

        $this->line('Renumber plan ('.count($changes).' rows of '.count($plan).' total):');
        foreach ($plan as $p) {
            $tag = $p['old'] === $p['new'] ? '· unchanged ' : '→ rewrite   ';
            $this->line(sprintf(
                '  [%-7s] %s  %s  %s -> %s',
                $p['kind'],
                $tag,
                'id='.str_pad((string) $p['id'], 6, ' '),
                $p['old'],
                $p['new']
            ));
        }

        if ($dryRun) {
            $this->warn('Dry run — no rows written.');

            return self::SUCCESS;
        }

        if ($changes === []) {
            $this->info('All ADNs already in target format. Nothing to write.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($changes): void {
                // Pass 1: stamp every changing row with a temporary sentinel
                // so the second pass can write the final ADNs without
                // hitting the unique index from a row whose new ADN happens
                // to equal another row's old ADN.
                foreach ($changes as $c) {
                    DB::table('distributors')
                        ->where('id', $c['id'])
                        ->update(['adn' => 'TMP_'.$c['id']]);
                }

                // Pass 2: write the final ADNs.
                foreach ($changes as $c) {
                    DB::table('distributors')
                        ->where('id', $c['id'])
                        ->update(['adn' => $c['new']]);
                }
            });
        } catch (Throwable $e) {
            $this->error('Renumber aborted: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info('Renumbered '.count($changes).' distributor row(s).');

        return self::SUCCESS;
    }
}
