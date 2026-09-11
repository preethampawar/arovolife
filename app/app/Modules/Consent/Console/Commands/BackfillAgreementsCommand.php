<?php

declare(strict_types=1);

namespace App\Modules\Consent\Console\Commands;

use App\Modules\Consent\Models\Agreement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds the `agreements` registry from the acceptance rows (F72).
 *
 * `agreements` is the document side of the consent record: one row per
 * version of each of the four documents, carrying the hash of the text and
 * the date it took effect, with `supersedes_id` chaining the versions in
 * order. `consents` is the person side — who accepted which version, when.
 * The second has 1,144 rows on staging; the first has none, so the platform
 * can say a distributor accepted `tnc` version `1.0.0` and cannot say what
 * `1.0.0` was. That is the half of the record a regulator asks for.
 *
 * Every fact needed is already in `consents`, so this derives rather than
 * invents: for each (type, version) pair, the hash the earliest acceptance
 * recorded and that acceptance's timestamp as `effective_from`. Nothing is
 * fabricated for a version nobody ever accepted.
 *
 * Safe to run repeatedly — it inserts only what is missing and rewrites a
 * `supersedes_id` only where the chain is wrong — so it can go in a deploy
 * script without a "has this run yet" flag. A migration would have been the
 * other option and is worse here: this reads rows a migration cannot assume
 * exist yet, and a registry that drifts wants re-running, not editing.
 */
final class BackfillAgreementsCommand extends Command
{
    protected $signature = 'consent:backfill-agreements {--dry-run : Report what would be written and write nothing.}';

    protected $description = 'Populate the agreements registry from the consent acceptance rows';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $rows = [];
        $created = 0;

        foreach ($this->versionsAccepted() as $version) {
            $existing = Agreement::query()
                ->where('type', $version['type'])
                ->where('version', $version['version'])
                ->first();

            if ($existing === null && ! $dryRun) {
                Agreement::create([
                    'type' => $version['type'],
                    'version' => $version['version'],
                    'pdf_hash' => $version['hash'],
                    'effective_from' => $version['effective_from'],
                ]);
            }

            $created += $existing === null ? 1 : 0;

            $rows[] = [
                $version['type'],
                $version['version'],
                $version['effective_from'],
                number_format($version['acceptances']),
                $existing === null ? ($dryRun ? 'would create' : 'created') : 'already present',
            ];

            if ($version['hash_variants'] > 1) {
                // Two different texts recorded under one version string. The
                // registry can hold one hash per version, so it keeps the
                // earliest — but somebody has to know the document moved
                // without its version moving, which is the thing versioned
                // consent exists to prevent.
                $this->warn(
                    "{$version['type']} {$version['version']}: {$version['hash_variants']} different document "
                    .'hashes were accepted under this one version. The earliest is registered; the others are not.'
                );
            }
        }

        if ($rows === []) {
            $this->info('No consent rows to derive agreements from.');

            return self::SUCCESS;
        }

        $this->table(['Type', 'Version', 'Effective from', 'Acceptances', 'Registry'], $rows);

        $linked = $dryRun ? 0 : $this->linkSupersessions();

        $this->info($dryRun
            ? "Dry run: {$created} agreement(s) would be created."
            : "{$created} agreement(s) created, {$linked} supersession link(s) written.");

        return self::SUCCESS;
    }

    /**
     * Every (type, version) pair anybody has accepted, with the hash and date
     * of the earliest acceptance of it.
     *
     * @return list<array{type: string, version: string, hash: string, effective_from: string, acceptances: int, hash_variants: int}>
     */
    private function versionsAccepted(): array
    {
        $groups = DB::table('consents')
            ->select('document_type', 'document_version')
            ->selectRaw('COUNT(*) as acceptances')
            ->selectRaw('COUNT(DISTINCT doc_hash_sha256) as hash_variants')
            ->groupBy('document_type', 'document_version')
            ->orderBy('document_type')
            ->get();

        $versions = [];

        foreach ($groups as $group) {
            $earliest = DB::table('consents')
                ->where('document_type', $group->document_type)
                ->where('document_version', $group->document_version)
                // `accepted_at` is what "effective from" means here; `id` only
                // breaks a tie between two rows stamped the same millisecond.
                ->orderBy('accepted_at')
                ->orderBy('id')
                ->first(['doc_hash_sha256', 'accepted_at']);

            if ($earliest === null || $earliest->doc_hash_sha256 === null) {
                // `pdf_hash` is BINARY(32) NOT NULL: a version whose earliest
                // acceptance recorded no hash cannot be registered, and
                // guessing one would be worse than the gap.
                $this->warn(
                    "{$group->document_type} {$group->document_version}: no document hash on the earliest "
                    .'acceptance, so it cannot be registered. Left out.'
                );

                continue;
            }

            $versions[] = [
                'type' => (string) $group->document_type,
                'version' => (string) $group->document_version,
                'hash' => (string) $earliest->doc_hash_sha256,
                'effective_from' => (string) $earliest->accepted_at,
                'acceptances' => (int) $group->acceptances,
                'hash_variants' => (int) $group->hash_variants,
            ];
        }

        usort($versions, static fn (array $a, array $b): int => [$a['type'], $a['effective_from']] <=> [$b['type'], $b['effective_from']]);

        return $versions;
    }

    /**
     * Chains each type's versions in the order they took effect, so the
     * registry answers "what did this replace" as well as "what was it".
     *
     * @return int the number of links written
     */
    private function linkSupersessions(): int
    {
        $written = 0;

        foreach (Agreement::query()->get()->groupBy('type') as $versions) {
            $previous = null;

            foreach ($versions->sortBy([['effective_from', 'asc'], ['id', 'asc']]) as $agreement) {
                if ($agreement->supersedes_id !== $previous?->id) {
                    $agreement->update(['supersedes_id' => $previous?->id]);
                    $written++;
                }

                $previous = $agreement;
            }
        }

        return $written;
    }
}
