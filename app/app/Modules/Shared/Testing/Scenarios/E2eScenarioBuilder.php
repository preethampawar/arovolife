<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Scenarios;

use App\Modules\Compensation\Console\Commands\SeedPayingPeriodCommand;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Crypto\PiiCrypter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

/**
 * Writes a scenario's INPUT on the e2e database: partners placed in the Genos,
 * and paid orders with their personal-BV ledger rows. Nothing derived is
 * written — group BV, cut-offs, pools and bonuses come from `e2e:engines`,
 * which replays the real engines, so a money spec proves the engines and not
 * the fixture. The order shape mirrors
 * {@see SeedPayingPeriodCommand}.
 *
 * Everything is deterministic: ADNs, e-mails and phones come from the
 * partner's position in the scenario, and every instant is the scenario's own
 * (the frozen clock only stamps what the scenario leaves unstated). The same
 * scenario on a fresh reset therefore produces identical rows, except for two
 * salted values that no assertion reads: the bcrypt password hash and the
 * bank-account ciphertext (random IV; it always decrypts to the same number).
 */
final class E2eScenarioBuilder
{
    /** Shared by every scenario partner; the suite logs in with it. */
    public const PASSWORD = 'e2e-partner-pwd-2026';

    /** ADNs start here: 9 digits, clear of anything the placement engine issues. */
    private const ADN_BASE = 900000000;

    /** @var array<string, array{id: int, user_id: int, adn: string, email: string, phone: string, customer_id: int}> */
    private array $partners = [];

    /** @var array<string, array{id: int, order_no: string, partner: string, bv_paise: int, total_paise: int, paid_at: string}> */
    private array $orders = [];

    /** @var array<string, array{user_id: int, email: string, role: string}> */
    private array $staff = [];

    /** @var array<int, array<string, mixed>>|null */
    private ?array $variants = null;

    private ?string $passwordHash = null;

    /**
     * A partner at the root of the Genos (no parent), or placed under
     * $parent on $side. The sponsor is the parent unless given.
     */
    public function partner(string $key, ?string $parent = null, ?string $side = null, ?string $joinedAt = null, ?string $sponsor = null, ?string $name = null): self
    {
        if (isset($this->partners[$key])) {
            throw new InvalidArgumentException("Partner '{$key}' already exists in this scenario.");
        }

        if (($parent === null) !== ($side === null) || ($side !== null && ! in_array($side, ['left', 'right'], true))) {
            throw new InvalidArgumentException("Partner '{$key}' needs both a parent and a side (left/right), or neither.");
        }

        $at = $joinedAt === null ? Carbon::now() : Carbon::parse($joinedAt);
        $stamp = $at->format('Y-m-d H:i:s.v');
        $seq = count($this->partners) + 1;
        $email = $key.'@e2e.test';
        $phone = '+9190000'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        $adn = (string) (self::ADN_BASE + $seq);

        $userId = (int) DB::table('users')->insertGetId([
            'full_name' => $name ?? 'E2E '.ucfirst($key),
            'email' => $email,
            'phone_e164' => $phone,
            'password_hash' => $this->passwordHash ??= Hash::make(self::PASSWORD),
            'password_set_at' => $stamp,
            'status' => 'active',
            'email_verified_at' => $stamp,
            'activated_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $parentId = $parent === null ? null : $this->partnerId($parent);
        $sponsorId = $sponsor === null ? $parentId : $this->partnerId($sponsor);
        $depth = $parent === null ? 0 : $this->depthOf($parentId) + 1;

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            $id = (int) DB::table('distributors')->insertGetId([
                'user_id' => $userId,
                'adn' => $adn,
                'pan_hash' => hash('sha256', 'e2e-pan-'.$key, true),
                'pan_last4' => str_pad((string) $seq, 4, '0', STR_PAD_LEFT),
                // A real ciphertext, so payout batches release the line and the
                // NEFT file can print it. Fake 12-digit number per partner.
                'bank_account_enc' => PiiCrypter::encryptString('5000'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT)),
                'bank_ifsc' => 'SBIN0000001',
                'sponsor_id' => $sponsorId ?? 0,
                'placement_parent_id' => $parentId ?? 0,
                'placement_side' => $side === null ? null : ($side === 'left' ? 'L' : 'R'),
                'side_chosen_by' => 'referral_default',
                'depth' => $depth,
                'effective_date' => $stamp,
                'cooling_off_end_at' => $at->copy()->addDays(30)->format('Y-m-d H:i:s.v'),
                'state' => 'TS',
                'is_primary_couple' => 0,
                'created_at' => $stamp,
                'updated_at' => $stamp,
            ]);

            // A root is its own sponsor and placement parent, as on every env.
            if ($parentId === null) {
                DB::table('distributors')->where('id', $id)->update(['sponsor_id' => $sponsorId ?? $id, 'placement_parent_id' => $id, 'updated_at' => $stamp]);
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }

        $closure = [['ancestor_id' => $id, 'descendant_id' => $id, 'depth' => 0]];

        if ($parentId !== null) {
            foreach (DB::table('genealogy_closure')->where('descendant_id', $parentId)->get(['ancestor_id', 'depth']) as $row) {
                $closure[] = ['ancestor_id' => (int) $row->ancestor_id, 'descendant_id' => $id, 'depth' => (int) $row->depth + 1];
            }
        }

        DB::table('genealogy_closure')->insert($closure);

        $customerId = (int) DB::table('customers')->insertGetId([
            'user_id' => $userId,
            'distributor_id' => $id,
            'display_name' => $name ?? 'E2E '.ucfirst($key),
            'marketing_opt_in' => 0,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $this->partners[$key] = [
            'id' => $id,
            'user_id' => $userId,
            'adn' => $adn,
            'email' => $email,
            'phone' => $phone,
            'customer_id' => $customerId,
        ];

        return $this;
    }

    /**
     * A back-office login holding one Spatie role (admin, admin-finance,
     * admin-operations, admin-compliance, developer). E-mail `<key>@e2e.test`,
     * password {@see self::PASSWORD}.
     */
    public function staff(string $key, string $role, ?string $name = null): self
    {
        if (isset($this->staff[$key]) || isset($this->partners[$key])) {
            throw new InvalidArgumentException("'{$key}' already exists in this scenario.");
        }

        $at = Carbon::now()->format('Y-m-d H:i:s.v');
        $email = $key.'@e2e.test';

        $userId = (int) DB::table('users')->insertGetId([
            'full_name' => $name ?? 'E2E '.ucfirst($key),
            'email' => $email,
            'phone_e164' => '+9190001'.str_pad((string) (count($this->staff) + 1), 5, '0', STR_PAD_LEFT),
            'password_hash' => $this->passwordHash ??= Hash::make(self::PASSWORD),
            'password_set_at' => $at,
            'status' => 'active',
            'email_verified_at' => $at,
            'activated_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        User::query()->findOrFail($userId)->assignRole($role);

        $this->staff[$key] = ['user_id' => $userId, 'email' => $email, 'role' => $role];

        return $this;
    }

    /**
     * A paid, self-consumption order by $partner at $paidAt.
     *
     * @param  list<array{string, int}>  $lines  [variant SKU, qty]
     */
    public function order(string $key, string $partner, string $paidAt, array $lines): self
    {
        if (isset($this->orders[$key])) {
            throw new InvalidArgumentException("Order '{$key}' already exists in this scenario.");
        }

        $at = Carbon::parse($paidAt);
        $stamp = $at->format('Y-m-d H:i:s');
        $orderNo = 'E2E-'.$at->format('ymd').'-'.str_pad((string) (count($this->orders) + 1), 4, '0', STR_PAD_LEFT);
        $distributorId = $this->partnerId($partner);

        $items = [];
        $subtotal = 0;
        $gst = 0;
        $bv = 0;

        foreach ($lines as [$sku, $qty]) {
            $variant = $this->variant($sku);
            $unit = (int) ($variant['sale_price_paise'] ?: $variant['mrp_paise']);
            $rate = (int) $variant['gst_rate_bp'];
            $lineTotal = $unit * $qty;
            // Catalogue prices are GST-inclusive; back the taxable value out as checkout does.
            $taxable = (int) round($lineTotal * 10000 / (10000 + $rate));
            $lineBv = (int) $variant['bv_paise'] * $qty;

            $items[] = [
                'product_variant_id' => (int) $variant['id'],
                'product_name_snapshot' => (string) $variant['name'],
                'variant_sku_snapshot' => (string) $variant['variant_sku'],
                'hsn_code_snapshot' => (string) ($variant['hsn_code'] ?? ''),
                'qty' => $qty,
                'unit_price_paise' => $unit,
                'bv_paise' => $lineBv,
                'gst_rate_bp' => $rate,
                'taxable_value_paise' => $taxable,
                'gst_paise' => $lineTotal - $taxable,
                'line_total_paise' => $lineTotal,
                'created_at' => $stamp,
            ];

            $subtotal += $lineTotal;
            $gst += $lineTotal - $taxable;
            $bv += $lineBv;
        }

        $orderId = (int) DB::table('orders')->insertGetId([
            'order_no' => $orderNo,
            'customer_id' => $this->partners[$partner]['customer_id'],
            'attributed_distributor_id' => $distributorId,
            'arete_center_id' => null,
            'delivery_type' => 'ship',
            'attribution_source' => 'logged_in',
            'payment_method' => 'online',
            'status' => 'paid',
            'self_consumption' => 1,
            'subtotal_paise' => $subtotal,
            'gst_paise' => $gst,
            'total_paise' => $subtotal,
            'idempotency_key' => 'e2e:'.$orderNo,
            'placed_at' => $stamp,
            'paid_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        DB::table('order_items')->insert(array_map(static fn (array $item): array => ['order_id' => $orderId] + $item, $items));

        DB::table('bv_ledger_entries')->insert([
            'distributor_id' => $distributorId,
            'order_id' => $orderId,
            'bv_paise' => $bv,
            'type' => 'accrual',
            'effective_at' => $stamp,
            'created_at' => $stamp,
            'updated_at' => $stamp,
        ]);

        $this->orders[$key] = [
            'id' => $orderId,
            'order_no' => $orderNo,
            'partner' => $partner,
            'bv_paise' => $bv,
            'total_paise' => $subtotal,
            'paid_at' => $stamp,
        ];

        return $this;
    }

    /** @return array{password: string, partners: array<string, array<string, mixed>>, staff: array<string, array<string, mixed>>, orders: array<string, array<string, mixed>>} */
    public function result(): array
    {
        return ['password' => self::PASSWORD, 'partners' => $this->partners, 'staff' => $this->staff, 'orders' => $this->orders];
    }

    private function partnerId(string $key): int
    {
        return $this->partners[$key]['id'] ?? throw new InvalidArgumentException("Unknown partner '{$key}' — declare it first.");
    }

    private function depthOf(int $id): int
    {
        return (int) DB::table('distributors')->where('id', $id)->value('depth');
    }

    /** @return array<string, mixed> */
    private function variant(string $sku): array
    {
        if ($this->variants === null) {
            $this->variants = [];

            foreach (
                DB::table('product_variants')
                    ->leftJoin('products', 'products.id', '=', 'product_variants.product_id')
                    ->select('product_variants.*', 'products.hsn_code')
                    ->get() as $row
            ) {
                $this->variants[(string) $row->variant_sku] = (array) $row;
            }
        }

        return $this->variants[$sku] ?? throw new InvalidArgumentException("Unknown variant SKU '{$sku}'.");
    }
}
