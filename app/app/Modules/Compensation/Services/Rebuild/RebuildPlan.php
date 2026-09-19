<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Support\Carbon;

/**
 * What a rebuild would remove, why it may be refused, and what has to be run
 * after it — computed before anything is written, and shown to the operator.
 *
 * The same object answers both halves of the two-step confirm: the preview
 * renders it, and the confirm re-plans and compares {@see fingerprint()}, so a
 * rebuild can never act on state that changed while somebody was reading it.
 */
final readonly class RebuildPlan
{
    /**
     * @param  list<string>  $refusals  Empty means the rebuild may proceed.
     * @param  list<string>  $warnings  What the operator must run AFTER it succeeds.
     * @param  array<string, int>  $rowsToRemove  table => row count.
     * @param  int  $unsweeps  Wallet credits whose batch stamp is removed (never deleted).
     * @param  array<string, int>  $adjustments  table => rows corrected in place rather than deleted.
     */
    public function __construct(
        public RebuildKind $kind,
        public Carbon $period,
        public array $refusals,
        public array $warnings,
        public array $rowsToRemove,
        public int $unsweeps,
        public array $adjustments = [],
    ) {}

    /** The ordinary command the rebuild re-runs once the period is wiped. */
    public function rerunCommand(): string
    {
        return $this->kind->rerunCommandText($this->period);
    }

    public function isRefused(): bool
    {
        return $this->refusals !== [];
    }

    /** The period as the command option spells it — `2026-09-18` or `2026-09`. */
    public function periodValue(): string
    {
        return EngineRegistry::get($this->kind->registryKey())->formatPeriod($this->period);
    }

    /**
     * What the confirm compares against the preview.
     *
     * Deliberately covers the refusals and the counts, not a timestamp: a
     * rebuild whose row counts and refusals are unchanged is the rebuild that
     * was previewed, and re-previewing after every unrelated write would make
     * the control unusable.
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode([
            'kind' => $this->kind->value,
            'period' => $this->periodValue(),
            'refusals' => $this->refusals,
            'rows' => $this->rowsToRemove,
            'unsweeps' => $this->unsweeps,
            'adjustments' => $this->adjustments,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind->value,
            'period' => $this->periodValue(),
            'label' => EngineRegistry::get($this->kind->registryKey())->label,
            'refusals' => $this->refusals,
            'warnings' => $this->warnings,
            'rowsToRemove' => $this->rowsToRemove,
            'unsweeps' => $this->unsweeps,
            'adjustments' => $this->adjustments,
            'rerunCommand' => $this->rerunCommand(),
            'fingerprint' => $this->fingerprint(),
        ];
    }

    /**
     * The same plan with another refusal list — how the shared preflight
     * refusals ({@see RebuildPreflight}) are put in front of the kind's own.
     *
     * @param  list<string>  $refusals
     */
    public function withRefusals(array $refusals): self
    {
        return new self(
            $this->kind,
            $this->period,
            $refusals,
            $this->warnings,
            $this->rowsToRemove,
            $this->unsweeps,
            $this->adjustments,
        );
    }
}
