<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * Whether an engine is driven by a single date (`--date`, Y-m-d) or by a
 * calendar month (`--month`, Y-m). Everything downstream — the form input type,
 * the period parser, the "is this period computed?" lookup — branches on this.
 */
enum EnginePeriodType: string
{
    case Date = 'date';

    case Month = 'month';

    /** Format accepted by the engine's command option and by the admin form. */
    public function inputFormat(): string
    {
        return match ($this) {
            self::Date => 'Y-m-d',
            self::Month => 'Y-m',
        };
    }

    /** `type` attribute for the period field on the Engine Runs form. */
    public function htmlInputType(): string
    {
        return $this->value === 'date' ? 'date' : 'month';
    }
}
