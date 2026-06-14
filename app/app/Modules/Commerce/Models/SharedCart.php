<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A snapshot of a distributor's cart, shared via a short code so a customer can
 * load the same products in one click ("Easy Purchase" for multiple items).
 *
 * @property int $id
 * @property string $code
 * @property int|null $distributor_id
 * @property string|null $ref_adn
 * @property int|null $created_by_user_id
 * @property array<int, array{variant_id: int, qty: int}> $items
 * @property Carbon $expires_at
 */
final class SharedCart extends Model
{
    /**
     * Session key set when a guest opens a shared ("Easy Purchase") cart. Its
     * presence is the guest's pass through the members-only checkout gate, and
     * it names the distributor the order is attributed to / shown read-only.
     */
    public const SESSION_DISTRIBUTOR_KEY = 'easy_cart.distributor_id';

    protected $table = 'shared_carts';

    protected $fillable = [
        'code', 'distributor_id', 'ref_adn', 'created_by_user_id', 'items', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'items' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function url(): string
    {
        return route('shop.easy-cart', ['code' => $this->code]);
    }
}
