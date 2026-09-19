<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services\Rebuild;

use App\Modules\Compensation\Support\EngineRegistry;
use Illuminate\Support\Carbon;

/**
 * The four periods that can be rebuilt from scratch (ADR-0016, D4).
 *
 * One case per PERIOD KIND rather than per engine: what a rebuild removes and
 * re-derives is decided by the period — a night, a Tuesday batch, a month's
 * crediting, a month's payout — and the engines inside it are whatever the
 * ordinary command for that period runs.
 *
 * Everything else about a kind (its command, its period option, how a period
 * string is parsed) is read from {@see EngineRegistry}, so the registry stays
 * the one place a signature is written down.
 */
enum RebuildKind: string
{
    case Night = 'night';
    case Week = 'week';
    case Month = 'month';
    case Payout = 'payout';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }

    public function registryKey(): string
    {
        return 'compensation.rebuild-'.$this->value;
    }

    /** The artisan name of the rebuild command itself, e.g. `compensation:rebuild-night`. */
    public function signature(): string
    {
        return EngineRegistry::get($this->registryKey())->commandSignature;
    }

    /** `--date` or `--month`, whichever the rebuild command takes. */
    public function periodOption(): string
    {
        return EngineRegistry::get($this->registryKey())->periodOption;
    }

    /**
     * The ORDINARY command this kind re-runs once the period is wiped, and its
     * options — the same command the scheduler would have run.
     *
     * A rebuild never invents a way to compute a period: it removes the rows and
     * lets the engines derive them again through their own product-sale-chained
     * path (D10). `--restart` goes to the two resuming orchestrators because the
     * pre-wipe success still counts until the re-run finishes (D13).
     *
     * @return array{0: string, 1: array<string, bool|string>}
     */
    public function rerunCall(Carbon $period): array
    {
        return match ($this) {
            self::Night => ['compensation:nightly-run', [
                '--date' => $period->toDateString(),
                '--restart' => true,
            ]],
            self::Week => ['gsb:weekly-payout', ['--date' => $period->toDateString()]],
            self::Month => ['compensation:monthly-close', [
                '--month' => $period->format('Y-m'),
                '--restart' => true,
            ]],
            self::Payout => ['compensation:monthly-payout-close', ['--month' => $period->format('Y-m')]],
        };
    }

    /** {@see rerunCall()} written out as an operator would type it. */
    public function rerunCommandText(Carbon $period): string
    {
        [$signature, $options] = $this->rerunCall($period);

        foreach ($options as $option => $value) {
            $signature .= $value === true ? ' '.$option : sprintf(' %s=%s', $option, $value);
        }

        return $signature;
    }

    /**
     * How the rebuild names itself in a sentence — "The night was wiped …".
     * Capitalised with its article because every message it appears in starts
     * with it.
     */
    public function label(): string
    {
        return match ($this) {
            self::Night => 'The night',
            self::Week => 'The weekly payout batch',
            self::Month => 'The monthly close',
            self::Payout => 'The monthly payout batch',
        };
    }
}
