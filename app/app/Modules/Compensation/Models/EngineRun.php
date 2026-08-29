<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Models;

use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One invocation of one compensation engine for one period.
 *
 * Written by RecordEngineRun (a console-event listener), so cron runs, developer
 * CLI runs and admin-triggered runs all land here through the same writer.
 *
 * @property int $id
 * @property string $engine_key
 * @property Carbon $period_start
 * @property string $status
 * @property string $trigger
 * @property int|null $actor_id
 * @property string|null $chain_id
 * @property array<string, mixed>|null $summary
 * @property string|null $error
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 */
final class EngineRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    /** The step was not executed: already computed, already running, or upstream failed. */
    public const STATUS_SKIPPED = 'skipped';

    /** Any plain artisan invocation — the scheduler or a developer shell. */
    public const TRIGGER_CONSOLE = 'console';

    /** The engine an admin explicitly asked for on the Engine Runs page. */
    public const TRIGGER_MANUAL = 'manual';

    /** Pulled in as a prerequisite of a manual run. */
    public const TRIGGER_DEPENDENCY = 'dependency';

    /**
     * A `running` row older than this is treated as abandoned (the process died
     * without a CommandFinished event) rather than as a live run to wait for.
     */
    public const STALE_AFTER_MINUTES = 120;

    protected $table = 'engine_runs';

    protected $fillable = [
        'engine_key',
        'period_start',
        'status',
        'trigger',
        'actor_id',
        'chain_id',
        'summary',
        'error',
        'started_at',
        'finished_at',
        'duration_ms',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'summary' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /**
     * The wallet entries this run itself wrote — for a failed run, exactly what
     * it committed before it stopped.
     *
     * @return HasMany<WalletLedgerEntry, $this>
     */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(WalletLedgerEntry::class, 'engine_run_id');
    }

    /** A run still marked `running` long after it started — the process died. */
    public function isStale(): bool
    {
        return $this->status === self::STATUS_RUNNING
            && $this->started_at->lt(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    /**
     * Wall-clock seconds the run took, or null while it is still in flight.
     *
     * Prefers the measured duration_ms: started_at/finished_at carry the
     * replayed instant during a compensation replay, so their difference is 0
     * for every run the replay makes.
     */
    public function durationSeconds(): ?int
    {
        if ($this->duration_ms !== null) {
            return (int) round($this->duration_ms / 1000);
        }

        return $this->finished_at === null
            ? null
            : (int) max(0, $this->started_at->diffInSeconds($this->finished_at, absolute: true));
    }

    /** Human-readable duration for the admin table ("—", "820ms", "1m 12s"). */
    public function durationForHumans(): string
    {
        if ($this->duration_ms === null) {
            $seconds = $this->durationSeconds();

            return $seconds === null ? '—' : $seconds.'s';
        }

        if ($this->duration_ms < 1000) {
            return $this->duration_ms.'ms';
        }

        $seconds = (int) round($this->duration_ms / 1000);

        return $seconds < 60 ? $seconds.'s' : intdiv($seconds, 60).'m '.($seconds % 60).'s';
    }
}
