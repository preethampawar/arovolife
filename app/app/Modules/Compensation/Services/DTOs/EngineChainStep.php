<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\DTOs;

use App\Modules\Compensation\Support\EngineDefinition;
use Illuminate\Support\Carbon;

/**
 * One engine + one period, in the order it must be executed.
 */
final readonly class EngineChainStep
{
    public const REASON_TARGET = 'target';

    public const REASON_DEPENDENCY = 'dependency';

    public function __construct(
        public EngineDefinition $engine,
        public Carbon $period,
        public string $reason,
    ) {}

    public function isTarget(): bool
    {
        return $this->reason === self::REASON_TARGET;
    }

    /** Stable identity used for de-duplication and for the audit-log preview. */
    public function id(): string
    {
        return $this->engine->key.'|'.$this->engine->formatPeriod($this->period);
    }

    public function label(): string
    {
        return $this->engine->label.' — '.$this->engine->displayPeriod($this->period);
    }
}
