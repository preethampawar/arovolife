<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Models;

use App\Modules\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $actor_id
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property array<string, mixed>|null $details
 * @property string|null $ip
 * @property string|null $before_hash
 * @property string|null $after_hash
 * @property string|null $row_hash
 * @property string|null $prev_hash
 * @property Carbon $created_at
 *
 * Tamper-evident by hash chain (T-6.1 finding M-1). Every row carries a
 * `row_hash` over its own fields plus the previous row's hash, so editing or
 * deleting a row breaks every hash after it. `compliance:verify-audit-log`
 * walks the chain and reports the first break.
 *
 * Note what this does and does not buy. It detects tampering by anyone with
 * database access but no way to recompute the whole chain — a stolen
 * credential, a support engineer covering a mistake, a `DELETE` fired at the
 * wrong row. It does NOT stop someone who can run this application code, since
 * they can rewrite the chain from the point they change. Detection needs the
 * chain head shipped somewhere the application cannot reach, which is why
 * `verify-audit-log` prints it.
 */
final class AuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'audit_log';

    protected $fillable = [
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_hash',
        'after_hash',
        'details',
        'ip',
    ];

    protected $hidden = [
        'before_hash',
        'after_hash',
        'row_hash',
        'prev_hash',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'details' => 'array',
            'subject_id' => 'integer',
        ];
    }

    /**
     * Link each new row into the chain as it is created.
     *
     * `creating` rather than a service method: every module writes audit rows
     * directly through this model, and a chain that depends on each of a dozen
     * call sites remembering to link it is a chain with holes. The hash covers
     * the fields that carry meaning — who did what to which subject, when, and
     * the detail payload.
     */
    protected static function booted(): void
    {
        self::creating(function (self $entry): void {
            // before/after are BINARY(32). A caller that hands over the hex
            // form of a SHA-256 (64 chars) meant the same digest; store the
            // bytes rather than let MySQL refuse the row — an audit insert
            // that fails inside markPaid() cancels a paid order, which is how
            // this was found. SQLite (the test driver) enforces no length.
            $entry->before_hash = static::normaliseDigest($entry->before_hash);
            $entry->after_hash = static::normaliseDigest($entry->after_hash);

            // A row that already carries a hash is being replayed (a restore,
            // a test fixture); do not overwrite what it brought with it.
            if ($entry->row_hash !== null) {
                return;
            }

            $entry->created_at ??= Carbon::now();

            $previous = static::query()
                ->whereNotNull('row_hash')
                ->orderByDesc('id')
                ->value('row_hash');

            $entry->prev_hash = $previous;
            $entry->row_hash = static::computeRowHash($entry, $previous);
        });
    }

    /**
     * The before/after digest of a subject state, in the raw 32-byte form the
     * columns hold. Every call site that records a state change should use
     * this rather than `hash('sha256', ...)`, whose default hex output is
     * twice the column width.
     */
    public static function digest(string $value): string
    {
        return hash('sha256', $value, true);
    }

    /** Accept a 64-char hex SHA-256 as the same digest as its 32 raw bytes. */
    private static function normaliseDigest(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = (string) $value;
        if (strlen($value) === 64 && ctype_xdigit($value)) {
            return (string) hex2bin($value);
        }

        return $value;
    }

    /**
     * The hash of one row, given the chain so far.
     *
     * Deliberately built from an explicit ordered list rather than the model's
     * attributes: adding a column must not silently change every historical
     * hash, and `json_encode` over an associative array would make the digest
     * depend on insertion order.
     */
    public static function computeRowHash(self $entry, ?string $previousHash): string
    {
        $payload = implode('|', [
            (string) ($entry->actor_id ?? ''),
            (string) $entry->action,
            (string) ($entry->subject_type ?? ''),
            (string) ($entry->subject_id ?? ''),
            json_encode($entry->details ?? [], JSON_THROW_ON_ERROR),
            (string) ($entry->ip ?? ''),
            $entry->created_at->format('Y-m-d H:i:s.v'),
            $previousHash === null ? '' : bin2hex($previousHash),
        ]);

        return hash('sha256', $payload, true);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
