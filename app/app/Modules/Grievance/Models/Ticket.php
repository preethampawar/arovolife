<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Models;

use App\Modules\Commerce\Models\Customer;
use App\Modules\Commerce\Models\Order;
use App\Modules\Grievance\Enums\EscalationLevel;
use App\Modules\Grievance\Enums\TicketCategory;
use App\Modules\Grievance\Enums\TicketChannel;
use App\Modules\Grievance\Enums\TicketStatus;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A grievance, from any of the intake channels in policy §3.
 *
 * State transitions are owned by GrievanceService — nothing outside it should
 * write `status`, the SLA columns or `escalation_level` directly, because each
 * of those changes owes the complainant a timeline entry and (usually) a
 * notification.
 *
 * @property int $id
 * @property string $ticket_no
 * @property string $subject
 * @property string $body
 * @property TicketCategory $category
 * @property string $severity
 * @property TicketStatus $status
 * @property TicketChannel $channel
 * @property int|null $customer_id
 * @property int|null $distributor_id
 * @property string|null $reporter_email
 * @property string|null $reporter_phone
 * @property string|null $reporter_name
 * @property bool $is_anonymous
 * @property int|null $order_id
 * @property int|null $assigned_to_user_id
 * @property EscalationLevel $escalation_level
 * @property Carbon|null $sla_acknowledgement_at
 * @property Carbon|null $sla_first_response_at
 * @property Carbon|null $sla_resolution_at
 * @property Carbon|null $acknowledged_at
 * @property Carbon|null $first_response_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $escalated_at
 * @property bool $third_party_dependent
 * @property Carbon|null $last_status_update_at
 * @property Carbon|null $acknowledgement_breached_at
 * @property Carbon|null $first_response_breached_at
 * @property Carbon|null $resolution_breached_at
 * @property string|null $resolution_note
 * @property int|null $closed_by_user_id
 * @property Carbon|null $retention_until
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, TicketEvent> $events
 * @property-read Collection<int, TicketAttachment> $attachments
 * @property-read Distributor|null $distributor
 * @property-read Customer|null $customer
 * @property-read Order|null $order
 * @property-read User|null $assignedTo
 * @property-read User|null $closedBy
 */
final class Ticket extends Model
{
    protected $table = 'tickets';

    protected $fillable = [
        'ticket_no', 'subject', 'body', 'category', 'severity', 'status', 'channel',
        'customer_id', 'distributor_id', 'reporter_email', 'reporter_phone',
        'reporter_name', 'is_anonymous',
        'order_id', 'assigned_to_user_id',
        'sla_acknowledgement_at', 'sla_first_response_at', 'sla_resolution_at',
        'acknowledged_at', 'first_response_at', 'resolved_at', 'closed_at',
        'escalation_level', 'escalated_at',
        'third_party_dependent', 'last_status_update_at',
        'acknowledgement_breached_at', 'first_response_breached_at', 'resolution_breached_at',
        'resolution_note', 'closed_by_user_id', 'retention_until',
    ];

    protected function casts(): array
    {
        return [
            'category' => TicketCategory::class,
            'status' => TicketStatus::class,
            'channel' => TicketChannel::class,
            'escalation_level' => EscalationLevel::class,
            'is_anonymous' => 'boolean',
            'third_party_dependent' => 'boolean',
            'sla_acknowledgement_at' => 'datetime',
            'sla_first_response_at' => 'datetime',
            'sla_resolution_at' => 'datetime',
            'acknowledged_at' => 'datetime',
            'first_response_at' => 'datetime',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'escalated_at' => 'datetime',
            'last_status_update_at' => 'datetime',
            'acknowledgement_breached_at' => 'datetime',
            'first_response_breached_at' => 'datetime',
            'resolution_breached_at' => 'datetime',
            'retention_until' => 'date',
        ];
    }

    // ── Relations ────────────────────────────────────────────────────────────

    /** @return HasMany<TicketEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(TicketEvent::class);
    }

    /** @return HasMany<TicketAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /** @return BelongsTo<Distributor, $this> */
    public function distributor(): BelongsTo
    {
        return $this->belongsTo(Distributor::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    // ── Scopes ───────────────────────────────────────────────────────────────

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeUnsettled(Builder $query): Builder
    {
        return $query->whereNotIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
    }

    /**
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeSettled(Builder $query): Builder
    {
        return $query->whereIn('status', [TicketStatus::Resolved->value, TicketStatus::Closed->value]);
    }

    /**
     * Tickets that breached any published SLA. Used by the monthly compliance
     * report and the admin queue's breach filter.
     *
     * @param  Builder<Ticket>  $query
     * @return Builder<Ticket>
     */
    public function scopeBreached(Builder $query): Builder
    {
        return $query->where(function (Builder $q): void {
            $q->whereNotNull('acknowledgement_breached_at')
                ->orWhereNotNull('first_response_breached_at')
                ->orWhereNotNull('resolution_breached_at');
        });
    }

    // ── Derived state ────────────────────────────────────────────────────────

    /**
     * Resolution SLA is overdue right now. Distinct from
     * `resolution_breached_at`, which is the recorded fact once the sweep has
     * seen it; this is the live read for a page load between sweeps.
     */
    public function isSlaBreached(): bool
    {
        if ($this->status->isSettled()) {
            return false;
        }

        return $this->sla_resolution_at !== null && $this->sla_resolution_at->isPast();
    }

    public function isAcknowledgementOverdue(): bool
    {
        return $this->acknowledged_at === null
            && $this->sla_acknowledgement_at !== null
            && $this->sla_acknowledgement_at->isPast();
    }

    public function isFirstResponseOverdue(): bool
    {
        return $this->first_response_at === null
            && ! $this->status->isSettled()
            && $this->sla_first_response_at !== null
            && $this->sla_first_response_at->isPast();
    }

    public function hasEverBreached(): bool
    {
        return $this->acknowledgement_breached_at !== null
            || $this->first_response_breached_at !== null
            || $this->resolution_breached_at !== null;
    }

    /**
     * Where to write when the complainant asks a question. Anonymous tickets
     * (policy §6.5) intentionally have nowhere to reply to — we accept the
     * complaint and investigate, but we cannot report back.
     */
    public function notifiableEmail(): ?string
    {
        if ($this->is_anonymous) {
            return null;
        }

        return $this->reporter_email;
    }

    public function displayReporter(): string
    {
        if ($this->is_anonymous) {
            return 'Anonymous complainant';
        }

        return $this->reporter_name ?? $this->reporter_email ?? 'Unnamed complainant';
    }
}
