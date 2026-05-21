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
        return match ($this->status) {
            'active' => ['dot' => 'bg-leaf-500',    'bg' => 'bg-leaf-50',    'border' => 'border-leaf-200',    'pill' => 'text-leaf-700 bg-leaf-50 border-leaf-200',           'card_label' => 'Active',     'pill_label' => 'Verified'],
            'pending' => ['dot' => 'bg-yellow-400',  'bg' => 'bg-yellow-50',  'border' => 'border-yellow-200',  'pill' => 'text-amber-700 bg-amber-50 border-amber-200',         'card_label' => 'New Member', 'pill_label' => 'Pending'],
            'frozen' => ['dot' => 'bg-sunrise-500', 'bg' => 'bg-sunrise-50', 'border' => 'border-sunrise-200', 'pill' => 'text-sunrise-700 bg-sunrise-50 border-sunrise-200',  'card_label' => 'Suspended',  'pill_label' => 'Suspended'],
            'terminated' => ['dot' => 'bg-red-500',     'bg' => 'bg-red-50',     'border' => 'border-red-200',     'pill' => 'text-red-700 bg-red-50 border-red-200',               'card_label' => 'Inactive',   'pill_label' => 'Inactive'],
            default => ['dot' => 'bg-gray-400',    'bg' => 'bg-gray-50',    'border' => 'border-gray-200',    'pill' => 'text-gray-700 bg-gray-50 border-gray-200',            'card_label' => ucfirst((string) $this->status), 'pill_label' => ucfirst((string) $this->status)],
        };
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

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function distributor(): HasOne
    {
        return $this->hasOne(Distributor::class);
    }
}
