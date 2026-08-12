<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

/**
 * The ordered work an admin's single "run this engine" click expands into:
 * prerequisites first, the requested engine last.
 */
final readonly class EngineChainPlan
{
    /**
     * @param  list<EngineChainStep>  $steps  Execution order — prerequisites precede the target.
     * @param  list<string>  $warnings  Prerequisites deliberately left out, with the reason.
     */
    public function __construct(
        public array $steps,
        public array $warnings = [],
    ) {}

    /**
     * @return list<EngineChainStep>
     */
    public function dependencySteps(): array
    {
        return array_values(array_filter($this->steps, fn (EngineChainStep $step): bool => ! $step->isTarget()));
    }

    public function dependencyCount(): int
    {
        return count($this->dependencySteps());
    }

    /**
     * Compact form for the audit log: what the resolver intended to run at the
     * moment the admin confirmed it.
     *
     * @return list<string>
     */
    public function toAuditPreview(): array
    {
        return array_map(fn (EngineChainStep $step): string => $step->id(), $this->steps);
    }

    /**
     * Distinct engine labels among the prerequisites, for the confirm modal.
     *
     * @return list<string>
     */
    public function dependencyEngineLabels(): array
    {
        $labels = [];

        foreach ($this->dependencySteps() as $step) {
            $labels[$step->engine->key] = $step->engine->label;
        }

        return array_values($labels);
    }
}
