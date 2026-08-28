<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * T-5.9 — the Phase-1 exit-gate snapshot the PRD promises the Product Owner.
 *
 * Reads the documents that are already the source of truth rather than keeping
 * a second copy of the answer: the backlog for user stories, the risk register
 * for C-01…C-09, the security audit checklist, and the git log for
 * `Compliance-Review:` trailers. That way the command cannot drift from the
 * documents — it can only report that they disagree with each other.
 *
 * Everything it cannot verify from the repository is reported as
 * NEEDS-A-HUMAN rather than assumed green. A status command that quietly
 * grades a UAT sign-off it has never seen is worse than no command: it lets a
 * phase close on a checklist nobody actually completed.
 */
final class PhaseStatusCommand extends Command
{
    protected $signature = 'phase:status
        {--phase=1 : Which phase to report on (only 1 is specced)}
        {--docs= : Repository root holding docs/ and backlog/ (default: the parent of the Laravel app)}';

    protected $description = 'Emit the phase exit-gate checklist: user stories, compliance sign-offs, security audit, exit criteria';

    /** PRD §11 exit criteria, in the order the PRD numbers them. */
    private const EXIT_CRITERIA = [
        'All 16 user stories pass UAT with PO sign-off',
        'Compliance Officer signs C-01 … C-09 in writing',
        'Post-development security audit closes all Critical/High findings',
        'p95 placement latency ≤ 250 ms on 1M-row tree',
        'Cooling-off + placement-strategy runbooks exist and are exercised',
        'DR drill: previous-night DB restore into staging reaches green',
        'Feature-flag killswitch verified in staging',
        'WCAG 2.1 AA on the registration wizard',
        'p95 page load < 1.5 s on staging with 100k distributors',
    ];

    public function handle(): int
    {
        $phase = (int) $this->option('phase');

        if ($phase !== 1) {
            $this->error('Only phase 1 has a specced exit gate. See docs/roadmap.md for the others.');

            return self::FAILURE;
        }

        $root = rtrim((string) ($this->option('docs') ?: base_path('..')), '/');

        // The Docker image mounts only the Laravel app, so docs/ and backlog/
        // sit outside the container. Say so plainly rather than reporting
        // "not found" nine times and letting someone read that as "nothing
        // has shipped".
        if (! File::exists($root.'/docs/roadmap.md')) {
            $this->error('Cannot see the repository docs at '.$root.'.');
            $this->line('');
            $this->line('This command reads docs/ and backlog/, which live above the Laravel app.');
            $this->line('Run it from the repository checkout on the host:');
            $this->line('');
            $this->line('    cd app && php artisan phase:status');
            $this->line('');
            $this->line('Or point it at the checkout explicitly:');
            $this->line('');
            $this->line('    php artisan phase:status --docs=/path/to/arovolife-code');
            $this->line('');

            return self::FAILURE;
        }

        $this->line('');
        $this->line('ARIVOLIFE — PHASE 1 EXIT-GATE SNAPSHOT');
        $this->line('Generated '.now('Asia/Kolkata')->format('d M Y H:i').' IST');
        $this->line(str_repeat('=', 78));

        $red = 0;
        $red += $this->reportUserStories($root);
        $red += $this->reportComplianceItems($root);
        $red += $this->reportSecurityAudit($root);
        $red += $this->reportExitCriteria($root);

        $this->reportNextUp($root);

        $this->line('');
        $this->line(str_repeat('=', 78));
        $this->line($this->verdict($red));
        $this->line('');

        return self::SUCCESS;
    }

    /**
     * Section 1 — user stories, from the backlog's story-to-sprint map.
     *
     * @return int count of red items
     */
    private function reportUserStories(string $root): int
    {
        $this->section('1. USER STORIES (source: backlog/phase-1-backlog.md)');

        $backlog = $this->read($root.'/backlog/phase-1-backlog.md');

        if ($backlog === null) {
            $this->line('  backlog/phase-1-backlog.md not found — cannot report.');

            return 1;
        }

        $rows = [];
        $red = 0;

        preg_match_all('/^\|\s*(US-1\.\d{2})[^|]*\|([^|]*)\|(.*)$/m', $backlog, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $story = trim($match[1]);
            $detail = trim(strip_tags($match[3]), ' |');

            [$state, $note] = $this->gradeStory($detail);

            if ($state === 'RED') {
                $red++;
            }

            $rows[] = [$story, $state, Str::limit($note, 58)];
        }

        if ($rows === []) {
            $this->line('  No US-1.xx rows found in the story-to-sprint map.');

            return 1;
        }

        $this->asciiTable(['Story', 'State', 'Rationale'], $rows);

        return $red;
    }

    /**
     * A story is green when the backlog says shipped, amber when it carries a
     * deferral, red otherwise.
     *
     * @return array{0: string, 1: string}
     */
    private function gradeStory(string $detail): array
    {
        $plain = trim(preg_replace('/\s+/', ' ', $detail) ?? '');

        if ($plain === '') {
            return ['RED', 'No status recorded in the backlog'];
        }

        if (stripos($plain, 'deferred') !== false) {
            return ['AMBER', $plain];
        }

        if (str_contains($plain, '✅')) {
            return ['GREEN', trim(str_replace('✅', '', $plain)) ?: 'Shipped'];
        }

        return ['RED', $plain];
    }

    /**
     * Section 2 — C-01…C-09 and the Compliance-Review trailers in the log.
     */
    private function reportComplianceItems(string $root): int
    {
        $this->section('2. COMPLIANCE ITEMS C-01 … C-09 (source: docs/compliance/risk-register.md)');

        $register = $this->read($root.'/docs/compliance/risk-register.md');

        if ($register === null) {
            $this->line('  docs/compliance/risk-register.md not found — cannot report.');

            return 1;
        }

        $rows = [];
        $unsigned = 0;

        preg_match_all('/^\|\s*(C-0\d)\s*\|([^|]*)\|([^|]*)\|([^|]*)\|/m', $register, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $item = trim($match[1]);
            $description = trim($match[2]);
            $signedBy = trim($match[3]);

            $signed = $signedBy !== '';

            if (! $signed) {
                $unsigned++;
            }

            $rows[] = [$item, $signed ? 'SIGNED' : 'UNSIGNED', Str::limit($description, 52)];
        }

        $this->asciiTable(['Item', 'Sign-off', 'Description'], $rows);

        $trailers = $this->complianceReviewTrailers();

        $this->line('');
        $this->line('  Compliance-Review trailers in the git log: '.count($trailers));

        foreach (array_slice($trailers, 0, 5) as $trailer) {
            $this->line('    · '.Str::limit($trailer, 70));
        }

        if ($unsigned > 0) {
            $this->line('');
            $this->line('  NEEDS-A-HUMAN: '.$unsigned.' of 9 compliance items carry no sign-off.');
            $this->line('  A trailer in the log is evidence a review happened, not a signature on C-01…C-09.');
        }

        return $unsigned > 0 ? 1 : 0;
    }

    /** @return array<int, string> */
    private function complianceReviewTrailers(): array
    {
        $output = [];
        $status = 0;

        exec('git log --format=%B 2>/dev/null | grep "^Compliance-Review:" | sort -u', $output, $status);

        return $status === 0 ? array_map('trim', $output) : [];
    }

    /**
     * Section 3 — the ten-point security audit.
     */
    private function reportSecurityAudit(string $root): int
    {
        $this->section('3. SECURITY AUDIT — 10 POINTS (source: docs/security/audit-checklist.md)');

        $checklist = $this->read($root.'/docs/security/audit-checklist.md');

        if ($checklist === null) {
            $this->line('  docs/security/audit-checklist.md not found — cannot report.');

            return 1;
        }

        // The file carries the original blank checklist first and then one
        // table per audit run. The most recent run is what counts, so read the
        // last table rather than the first.
        $runs = preg_split('/^## Audit run/m', $checklist) ?: [];
        $latest = count($runs) > 1 ? end($runs) : $checklist;

        $rows = [];
        $open = 0;

        preg_match_all('/^\|\s*(\d{1,2})\s*\|([^|]*)\|([^|]*)\|/m', (string) $latest, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            // The checklist is Markdown, so verdicts arrive wrapped in
            // emphasis — "**OPEN**" reads badly in a plain-text email.
            $verdict = trim(str_replace('*', '', $match[3]));
            $isPass = stripos($verdict, 'pass') !== false;

            if (! $isPass) {
                $open++;
            }

            $rows[] = [
                trim($match[1]),
                $isPass ? 'PASS' : strtoupper(Str::limit($verdict, 18, '')),
                Str::limit(trim(str_replace('*', '', $match[2])), 46),
            ];
        }

        if ($rows === []) {
            $this->line('  No audit-run table found. The audit has not been run for this phase.');

            return 1;
        }

        $this->asciiTable(['#', 'Verdict', 'Item'], $rows);

        if ($open > 0) {
            $this->line('');
            $this->line('  '.$open.' point(s) not passing in the latest recorded run.');
        }

        $this->line('  NEEDS-A-HUMAN: this reads the recorded verdicts. Re-run the');
        $this->line('  security-auditor subagent against the current tree before UAT.');

        return $open;
    }

    /**
     * Section 4 — PRD §11 exit criteria.
     *
     * Only three of the nine are checkable from the repository. The rest are
     * staging measurements and human sign-offs, and are reported as such.
     */
    private function reportExitCriteria(string $root): int
    {
        $this->section('4. EXIT CRITERIA (PRD §11)');

        $runbooks = File::exists($root.'/docs/runbooks/cooling-off-cancellation.md')
            && File::exists($root.'/docs/runbooks/placement-strategy-change.md');

        $killswitch = File::exists(base_path('app/Modules/Shared/Features/RegistrationKillswitch.php'));

        $verdicts = [
            1 => ['NEEDS-A-HUMAN', 'UAT sign-off is not a repository fact'],
            2 => ['NEEDS-A-HUMAN', 'See section 2'],
            3 => ['NEEDS-A-HUMAN', 'See section 3'],
            4 => ['UNVERIFIED', 'No p95 placement measurement in the repo (T-5.5)'],
            5 => [$runbooks ? 'PARTIAL' : 'RED', $runbooks ? 'Runbooks exist; DR exercise unrecorded' : 'Runbook missing'],
            6 => ['UNVERIFIED', 'No DR drill record in the repo (T-5.7)'],
            7 => [$killswitch ? 'PARTIAL' : 'RED', $killswitch ? 'Killswitch exists; staging verification unrecorded' : 'Killswitch missing'],
            8 => ['UNVERIFIED', 'No Pa11y evidence in the repo (T-5.6)'],
            9 => ['UNVERIFIED', 'No staging page-load measurement in the repo'],
        ];

        $rows = [];
        $red = 0;

        foreach (self::EXIT_CRITERIA as $index => $criterion) {
            [$state, $note] = $verdicts[$index + 1];

            if ($state !== 'PARTIAL') {
                $red++;
            }

            $rows[] = [(string) ($index + 1), $state, Str::limit($criterion.' — '.$note, 62)];
        }

        $this->asciiTable(['#', 'State', 'Criterion'], $rows);

        return $red;
    }

    /**
     * Section 5 — what to do next, taken from the roadmap's own open lists so
     * the command does not invent a second backlog.
     */
    private function reportNextUp(string $root): void
    {
        $this->section('5. NEXT UP');

        $roadmap = $this->read($root.'/docs/roadmap.md');

        if ($roadmap === null) {
            $this->line('  docs/roadmap.md not found.');

            return;
        }

        // The final sign-off gate table is the honest answer to "what is left".
        if (preg_match('/## Final sign-off gate.*?\n((?:\|.*\n)+)/s', $roadmap, $match) === 1) {
            $lines = array_values(array_filter(
                explode("\n", trim($match[1])),
                static fn (string $line) => $line !== '' && ! str_starts_with($line, '|---') && ! str_contains($line, '| Owner |')
            ));

            foreach (array_slice($lines, 0, 3) as $position => $line) {
                $cells = array_values(array_filter(array_map('trim', explode('|', $line)), static fn ($c) => $c !== ''));
                $this->line('  '.($position + 1).'. '.Str::limit($cells[0] ?? $line, 68));
            }

            $this->line('');
            $this->line('  Full list: docs/roadmap.md § Final sign-off gate');

            return;
        }

        $this->line('  No "Final sign-off gate" section found in the roadmap.');
    }

    private function verdict(int $red): string
    {
        if ($red === 0) {
            return 'PHASE-1: READY FOR UAT';
        }

        // The security audit and the compliance sign-offs are the two gates
        // that decide which of the two "not ready" verdicts applies.
        return 'PHASE-1: STILL IN BUILD ('.$red.' items red)';
    }

    // ── Output helpers ───────────────────────────────────────────────────────

    private function section(string $title): void
    {
        $this->line('');
        $this->line($title);
        $this->line(str_repeat('-', 78));
    }

    /**
     * Plain ASCII, per the command spec: the Product Owner pastes this into an
     * email, where a Markdown table renders as noise.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, string>>  $rows
     */
    private function asciiTable(array $headers, array $rows): void
    {
        $widths = [];

        foreach ($headers as $column => $header) {
            $widths[$column] = mb_strlen($header);
        }

        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, mb_strlen($cell));
            }
        }

        $render = function (array $cells) use ($widths): string {
            $parts = [];

            foreach ($cells as $column => $cell) {
                $parts[] = $cell.str_repeat(' ', max(0, $widths[$column] - mb_strlen($cell)));
            }

            return '  '.implode('  ', $parts);
        };

        $this->line($render($headers));
        $this->line('  '.str_repeat('-', array_sum($widths) + (count($widths) - 1) * 2));

        foreach ($rows as $row) {
            $this->line($render($row));
        }
    }

    private function read(string $path): ?string
    {
        return File::exists($path) ? File::get($path) : null;
    }
}
