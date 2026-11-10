<?php

declare(strict_types=1);

namespace App\Modules\Shared\Testing\Scenarios;

use App\Modules\Admin\Services\ApproveKycSubmission;
use App\Modules\Commerce\Models\Cart;
use App\Modules\Commerce\Models\Order;
use App\Modules\Commerce\Services\CartService;
use App\Modules\Commerce\Services\CheckoutService;
use App\Modules\Genealogy\Services\PlacementEngine;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Services\RegistrationService;
use App\Modules\Payments\Services\PaymentConfirmationService;
use App\Modules\Payments\Services\StubGateway;
use App\Modules\Shared\Crypto\PiiCrypter;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Builds a scenario's INPUT on the e2e database through the same code a real
 * partner goes through:
 *
 *  - partner(): RegistrationService::finalise() (PlacementEngine, sponsorship,
 *    closure, cooling-off clock, consents, orientation record, audit) and then
 *    ApproveKycSubmission, exactly as an admin approves a submission;
 *  - order(): CartService → CheckoutService::place() → StubGateway intent →
 *    PaymentConfirmationService::confirmStub(), so the order carries its
 *    payment intent, its real price and shipping, its invoice, and the BV
 *    accrual the state machine writes.
 *
 * Nothing derived is written: group BV, cut-offs, pools and bonuses come from
 * the real listeners and from `e2e:engines`.
 *
 * Every step runs at the scenario's own instant (Carbon test-now, which the e2e
 * provider mirrors into MySQL SET TIMESTAMP). ADNs and order numbers come from
 * the real generators and are random, so specs read them from the result,
 * never as literals. What a wizard step checks before finalise (OTP, the
 * orientation quiz, the PAN-already-used query) is covered by the UI specs.
 *
 * A scenario partner without a parent hangs under the reserved company block
 * (seeded in the base by SeedReservedTreeAction, as on production): the first
 * depth-5 company account with a free leg.
 */
final class E2eScenarioBuilder
{
    /** Shared by every scenario partner; the suite logs in with it. */
    public const PASSWORD = 'e2e-partner-pwd-2026';

    /** The KYC documents the wizard asks for (step 7). */
    private const DOCUMENT_TYPES = ['pan', 'aadhaar', 'aadhaar_back', 'address_proof_front', 'address_proof_back'];

    /** @var array<string, array{id: int, user_id: int, adn: string, email: string, phone: string, pan_last4: string, customer_id: int|null}> */
    private array $partners = [];

    /** @var array<string, array{id: int, order_no: string, partner: string, bv_paise: int, total_paise: int, paid_at: string}> */
    private array $orders = [];

    /** @var array<string, array{user_id: int, email: string, role: string}> */
    private array $staff = [];

    private ?string $passwordHash = null;

    /**
     * A partner placed under $parent on $side, or (no parent) under the
     * reserved company block. The sponsor is the parent unless given.
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
        $seq = count($this->partners) + 1;
        $email = $key.'@e2e.test';
        $phone = '+9190000'.str_pad((string) $seq, 5, '0', STR_PAD_LEFT);
        $fullName = $name ?? 'E2E '.ucfirst($key);

        [$placementId, $sideLetter] = $parent === null
            ? $this->companySlot()
            : [$this->partnerId($parent), $side === 'left' ? 'L' : 'R'];
        $sponsorId = $sponsor === null ? $placementId : $this->partnerId($sponsor);

        $distributorId = $this->at($at, function () use ($key, $seq, $email, $phone, $fullName, $placementId, $sideLetter, $sponsorId): int {
            $result = app(RegistrationService::class)->finalise([
                'account' => [
                    'email' => $email,
                    'phone_e164' => $phone,
                    'password_hash' => $this->passwordHash ??= Hash::make(self::PASSWORD),
                    'full_name' => $fullName,
                ],
                'personal' => ['state' => 'TS', 'date_of_birth' => '1990-01-01'],
                'pan' => ['pan_number' => self::panFor($seq)],
                'aadhaar' => ['aadhaar_number' => '9999'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT), 'ref' => 'E2E_REF_'.strtoupper($key)],
                // Fake 12-digit account per partner, so payout batches release
                // the line and the NEFT file can print it.
                'bank' => ['account_number' => '5000'.str_pad((string) $seq, 8, '0', STR_PAD_LEFT), 'ifsc' => 'SBIN0000001'],
                'placement' => ['placement_id' => $placementId, 'side' => $sideLetter],
                'sponsor_id' => $sponsorId,
                'consent' => [
                    'ip' => '127.0.0.1',
                    'user_agent' => 'e2e-scenario',
                    'declarations' => ['sound_mind' => true, 'not_insolvent' => true, 'no_moral_turpitude' => true],
                ],
                'documents' => $this->documents($key),
            ]);

            app(ApproveKycSubmission::class)($result->distributorId, $this->approverId());

            return $result->distributorId;
        });

        $row = DB::table('distributors')->where('id', $distributorId)->first(['user_id', 'adn', 'pan_last4']);

        $this->partners[$key] = [
            'id' => $distributorId,
            'user_id' => (int) $row->user_id,
            'adn' => (string) $row->adn,
            'email' => $email,
            'phone' => $phone,
            // As registration stored it, so a spec can prove which PAN a partner holds.
            'pan_last4' => (string) $row->pan_last4,
            // The customer row is made by checkout, as for a real partner.
            'customer_id' => null,
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
     * A paid order by $partner at $paidAt, placed through checkout and paid
     * through the stub gateway, as the shop does for a logged-in partner.
     *
     * @param  list<array{string, int}>  $lines  [variant SKU, qty]
     */
    public function order(string $key, string $partner, string $paidAt, array $lines): self
    {
        if (isset($this->orders[$key])) {
            throw new InvalidArgumentException("Order '{$key}' already exists in this scenario.");
        }

        $at = Carbon::parse($paidAt);
        $buyer = $this->partners[$partner] ?? throw new InvalidArgumentException("Unknown partner '{$partner}' — declare it first.");

        $order = $this->at($at, function () use ($buyer, $lines, $key): Order {
            $user = User::query()->findOrFail($buyer['user_id']);
            $carts = app(CartService::class);
            $cart = Cart::create(['customer_id' => $buyer['customer_id'], 'anonymous_key' => 'e2e-'.$key, 'expires_at' => Carbon::now()->addDays(7)]);

            foreach ($lines as [$sku, $qty]) {
                $variantId = (int) (DB::table('product_variants')->where('variant_sku', $sku)->value('id')
                    ?? throw new InvalidArgumentException("Unknown variant SKU '{$sku}'."));
                $carts->addItem($cart, $variantId, $qty, $user);
            }

            $address = [
                'name' => (string) $user->full_name,
                'phone' => (string) $user->phone_e164,
                'line1' => '1 Test Street',
                'line2' => null,
                'city' => 'Hyderabad',
                'state' => 'TS',
                'pincode' => '500001',
            ];

            $order = app(CheckoutService::class)->place(
                cart: $cart->fresh(['items']) ?? $cart,
                buyer: ['name' => $address['name'], 'email' => $buyer['email'], 'phone' => $address['phone']],
                shipping: $address,
                billing: $address,
                attributedDistributorId: $buyer['id'],
                attributionSource: 'logged_in',
                paymentMethod: Order::PAYMENT_ONLINE,
                authUserId: $user->id,
                buyerDistributorId: $buyer['id'],
                saveShippingAddress: false,
            );

            $intent = app(StubGateway::class)->createIntent($order, 'order:'.$order->id);
            app(PaymentConfirmationService::class)->confirmStub($intent);

            return $order->refresh();
        });

        if ($order->status !== 'paid') {
            throw new InvalidArgumentException("Order '{$key}' ended as '{$order->status}', not paid.");
        }

        $this->partners[$partner]['customer_id'] = (int) $order->customer_id;

        $this->orders[$key] = [
            'id' => (int) $order->id,
            'order_no' => (string) $order->order_no,
            'partner' => $partner,
            'bv_paise' => (int) DB::table('bv_ledger_entries')->where('order_id', $order->id)->sum('bv_paise'),
            'total_paise' => (int) $order->total_paise,
            'paid_at' => (string) $order->paid_at?->format('Y-m-d H:i:s'),
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

    /**
     * Run $step with the clock at $at, then put the previous clock back.
     *
     * @template T
     *
     * @param  Closure(): T  $step
     * @return T
     */
    private function at(Carbon $at, Closure $step): mixed
    {
        $previous = Carbon::getTestNow();
        Carbon::setTestNow($at);

        try {
            return $step();
        } finally {
            Carbon::setTestNow($previous);
        }
    }

    /**
     * The first depth-5 reserved company account with a free leg, left first.
     *
     * @return array{int, string}
     */
    private function companySlot(): array
    {
        $engine = app(PlacementEngine::class);
        $leaves = DB::table('distributors')
            ->whereIn('adn', ReservedAdns::all())
            ->where('depth', 5)
            ->orderBy('id')
            ->pluck('id');

        foreach ($leaves as $id) {
            foreach (['L', 'R'] as $side) {
                if ($engine->hasOpenSlot((int) $id, $side)) {
                    return [(int) $id, $side];
                }
            }
        }

        throw new InvalidArgumentException('No reserved company account has a free leg; run e2e:snapshot to seed the reserved block.');
    }

    /**
     * One tiny file per KYC document type, stored through the vault exactly
     * as the wizard stores an upload (encrypted on the PII key; the kyc disk
     * is local on e2e), in the shape the wizard hands finalise().
     *
     * @return array<string, array{path: string, sha256: string, encrypted: bool}>
     */
    private function documents(string $key): array
    {
        $documents = [];

        foreach (self::DOCUMENT_TYPES as $type) {
            $bytes = "e2e {$type} for {$key}";
            $path = "e2e/{$key}/{$type}.txt";
            Storage::disk('kyc')->put($path, PiiCrypter::encryptString($bytes));
            $documents[$type] = ['path' => $path, 'sha256' => hash('sha256', $bytes), 'encrypted' => true];
        }

        return $documents;
    }

    /** The seeded admin approves every scenario KYC submission. */
    private function approverId(): int
    {
        return (int) (DB::table('users')->where('email', 'admin@arovolife.test')->value('id')
            ?? throw new InvalidArgumentException('The base admin (admin@arovolife.test) is missing; run e2e:snapshot.'));
    }

    /** A valid-format individual PAN, unique per partner: EZEPE0001K. */
    private static function panFor(int $seq): string
    {
        return 'EZEPE'.str_pad((string) $seq, 4, '0', STR_PAD_LEFT).'K';
    }
}
