<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $customer_id
 * @property string $kind
 * @property string|null $label
 * @property string $name
 * @property string $phone_e164
 * @property string $line1
 * @property string|null $line2
 * @property string $city
 * @property string $state
 * @property string $pincode
 * @property string $country
 * @property bool $is_default
 */
final class CustomerAddress extends Model
{
    public const KIND_SHIPPING = 'shipping';

    public const KIND_BILLING = 'billing';

    protected $table = 'customer_addresses';

    protected $fillable = [
        'customer_id', 'kind', 'label', 'name', 'phone_e164',
        'line1', 'line2', 'city', 'state', 'pincode', 'country', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'bool'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Shipping addresses only — the saved-address book. Default first, then
     * newest, so the picker shows the most relevant first.
     *
     * @param  Builder<CustomerAddress>  $query
     */
    #[Scope]
    protected function shipping(Builder $query): void
    {
        $query->where('kind', self::KIND_SHIPPING)
            ->orderByDesc('is_default')
            ->orderByDesc('id');
    }

    /** A one-line display of the address for lists and the picker. */
    public function oneLine(): string
    {
        return collect([$this->line1, $this->line2, $this->city, $this->state, $this->pincode])
            ->filter()
            ->implode(', ');
    }
}
