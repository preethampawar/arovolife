<?php

declare(strict_types=1);

namespace App\Modules\Identity\Models;

use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $email
 * @property string $phone_e164
 * @property string $password_hash
 * @property string|null $mfa_secret_enc
 * @property Carbon|null $mfa_enabled_at
 * @property string|null $full_name
 * @property string|null $date_of_birth
 * @property string $status
 * @property string|null $closure_type
 * @property Carbon|null $last_login_at
 * @property string|null $remember_token
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $password_set_at
 * @property string|null $id_photo_path
 * @property Carbon|null $activated_at
 * @property-read Distributor|null $distributor
 */
final class User extends Authenticatable
{
    use HasRoles, Notifiable;

    protected $table = 'users';

    protected $fillable = [
        'full_name',
        'email',
        'phone_e164',
        'password_hash',
        'password_set_at',
        'mfa_secret_enc',
        'mfa_enabled_at',
        'date_of_birth',
        'status',
        'closure_type',
        'last_login_at',
        'remember_token',
        'email_verified_at',
        'id_photo_path',
        'activated_at',
    ];

    protected $hidden = [
        'password_hash',
        'mfa_secret_enc',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'mfa_enabled_at' => 'datetime',
            'last_login_at' => 'datetime',
            'email_verified_at' => 'datetime',
            'password_set_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * Status-aware presentation tokens.
     *
     * Single source of truth for the user.status → Tailwind / label
     * mapping used by every surface (tree-card body styling, dashboard
     * verification pill, Details popup pill). Each value group is
     * one row of the status table:
     *
     *   - `dot`         — small coloured circle in the tree card corner
     *   - `bg`          — light tint applied to the tree card background
     *   - `border`      — border colour for the tree card
     *   - `pill`        — bg + text + border tokens for the verification pill
     *   - `card_label`  — tooltip on the dot (UX-tuned: "New Member" for pending)
     *   - `pill_label`  — text inside the verification pill (UX-tuned: "Verified" for active)
     *
     * The dot/bg/border and pill colours can differ for the same status
     * (the dot is purely decorative, the pill carries a label) — keeping
     * them in one place ensures both walk in step when a status is
     * added or renamed.
     *
     * @return array{dot: string, bg: string, border: string, pill: string, card_label: string, pill_label: string}
     */
    public function statusTheme(): array
    {
        // A terminated account is either a cooling-off self-cancellation
        // ("Cancelled") or an admin closure ("Terminated"); both read more
        // honestly than the old "Inactive", which looked like a KYC state.
        if ($this->status === 'terminated') {
            $label = $this->isCoolingOffCancellation() ? 'Cancelled' : 'Terminated';

            return ['dot' => self::STATUS_DOTS['terminated'], 'bg' => 'bg-red-50', 'border' => 'border-red-200', 'pill' => 'text-red-700 bg-red-50 border-red-200', 'card_label' => $label, 'pill_label' => $label];
        }

        return match ($this->status) {
            'active' => ['dot' => self::STATUS_DOTS['active'],    'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'pill' => 'text-leaf-700 bg-leaf-50 border-leaf-200',           'card_label' => 'Active',     'pill_label' => 'Verified'],
            'pending' => ['dot' => self::STATUS_DOTS['pending'],  'bg' => 'bg-yellow-50',  'border' => 'border-yellow-200',  'pill' => 'text-amber-700 bg-amber-50 border-amber-200',         'card_label' => 'New Member', 'pill_label' => 'Pending'],
            'frozen' => ['dot' => self::STATUS_DOTS['frozen'],    'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'pill' => 'text-sunrise-700 bg-sunrise-50 border-sunrise-200',  'card_label' => 'Suspended',  'pill_label' => 'Suspended'],
            'rejected' => ['dot' => self::STATUS_DOTS['rejected'], 'bg' => 'bg-amber-50',   'border' => 'border-amber-200',   'pill' => 'text-amber-700 bg-amber-50 border-amber-200',         'card_label' => 'Rejected',   'pill_label' => 'Rejected'],
            default => ['dot' => 'bg-gray-400',    'bg' => 'bg-gray-50',    'border' => 'border-gray-200',    'pill' => 'text-gray-700 bg-gray-50 border-gray-200',            'card_label' => ucfirst((string) $this->status), 'pill_label' => ucfirst((string) $this->status)],
        };
    }

    /**
     * Canonical dot colour per account status — the single source shared by
     * the tree-card status dot ({@see self::statusTheme()}) and the tree
     * legend ({@see self::treeLegend()}), so the two can never drift.
     *
     * @var array<string, string>
     */
    private const STATUS_DOTS = [
        'pending' => 'bg-yellow-400',
        'active' => 'bg-leaf-500',
        'frozen' => 'bg-sunrise-500',
        'terminated' => 'bg-red-500',
        'rejected' => 'bg-amber-400',
    ];

    /**
     * The genealogy tree colour-key legend: generic status buckets (not
     * per-record labels), driven by the same {@see self::STATUS_DOTS} the
     * cards use. A closed account (cancelled or terminated) shares the red
     * "Closed" bucket here; the per-card pill carries the precise label.
     *
     * @return list<array{dot: string, label: string}>
     */
    public static function treeLegend(): array
    {
        return [
            ['dot' => self::STATUS_DOTS['pending'],    'label' => 'New Member'],
            ['dot' => self::STATUS_DOTS['active'],     'label' => 'Active'],
            ['dot' => self::STATUS_DOTS['terminated'], 'label' => 'Closed'],
            ['dot' => self::STATUS_DOTS['frozen'],     'label' => 'Suspended'],
        ];
    }

    /**
     * True when this terminal account was closed by the distributor's own
     * cooling-off cancellation (vs an admin termination). Single source for
     * the cancelled-vs-terminated distinction, shared by
     * {@see self::statusTheme()} and {@see self::accountStatusLabel()}.
     */
    public function isCoolingOffCancellation(): bool
    {
        return $this->status === 'terminated'
            && $this->closure_type === 'cooling_off_cancellation';
    }

    /**
     * Convenience wrapper — returns the verification-pill label.
     * Backed by {@see self::statusTheme()}; both the dashboard and the
     * tree-card pill rendering go through this single accessor.
     */
    public function verificationLabel(): string
    {
        return $this->statusTheme()['pill_label'];
    }

    /**
     * Convenience wrapper — returns the Tailwind classes for the
     * verification pill.
     */
    public function verificationClass(): string
    {
        return $this->statusTheme()['pill'];
    }

    /**
     * Coherent, single-source account-status badge for admin surfaces.
     *
     * users.status is the lifecycle state; closure_type explains a terminal
     * one. A 'terminated' status that was reached by a cooling-off
     * self-cancellation reads as "Cancelled (cooling-off)" — distinct from an
     * admin termination ("Terminated"). This avoids the old contradiction of a
     * grey "Terminated" pill sitting next to a green "Distributor: Active" pill.
     *
     * @return array{label: string, class: string}
     */
    public function accountStatusLabel(): array
    {
        if ($this->status === 'terminated') {
            $neutral = 'bg-white text-gray-500 border-gray-200';

            return $this->isCoolingOffCancellation()
                ? ['label' => 'Cancelled (cooling-off)', 'class' => $neutral]
                : ['label' => 'Terminated', 'class' => $neutral];
        }

        return match ($this->status) {
            'active' => ['label' => 'Active', 'class' => 'bg-green-50 text-green-700 border-green-200'],
            'frozen' => ['label' => 'Frozen', 'class' => 'bg-red-50 text-red-700 border-red-200'],
            'rejected' => ['label' => 'Rejected', 'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
            'pending' => ['label' => 'Pending', 'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
            default => ['label' => ucfirst((string) $this->status), 'class' => 'bg-amber-50 text-amber-700 border-amber-200'],
        };
    }

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function distributor(): HasOne
    {
        return $this->hasOne(Distributor::class);
    }
}
