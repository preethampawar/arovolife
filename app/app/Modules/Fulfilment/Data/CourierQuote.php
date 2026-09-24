<?php

declare(strict_types=1);

namespace App\Modules\Fulfilment\Data;

use App\Modules\Shared\Support\IndianNumber;

/**
 * One courier Shiprocket offers for a parcel: what it charges the company and
 * how long it expects to take. Staff pick one before the booking.
 *
 * The rate is the company's cost. It is never shown to, or charged to, the buyer.
 */
final readonly class CourierQuote
{
    /**
     * Service extras: Shiprocket's field, then its wording (lower-cased) →
     * our label. Seen on the sandbox 2026-09-24: "Real Time" / "MIS",
     * "Instant" / "On Request", "Available" / "Not Available". Any other
     * wording shows nothing rather than a guess.
     */
    public const SERVICES = [
        'realtime_tracking' => ['real time' => 'Real-time tracking', 'yes' => 'Real-time tracking'],
        'pod_available' => ['instant' => 'Instant proof of delivery', 'on request' => 'Proof of delivery on request', 'yes' => 'Proof of delivery'],
        'call_before_delivery' => ['available' => 'Calls before delivery', 'yes' => 'Calls before delivery'],
        'delivery_boy_contact' => ['available' => 'Delivery agent contact', 'yes' => 'Delivery agent contact'],
    ];

    /**
     * @param  list<string>  $services  labels from SERVICES
     */
    public function __construct(
        public int $courierId,
        public string $name,
        public int $ratePaise,
        public ?int $etdDays,
        public ?string $etd,
        public ?float $rating,
        public bool $surface,
        public array $services,
        public bool $recommended,
    ) {}

    /**
     * Shiprocket serviceability row → quote. Null when the row has no usable
     * courier id, name or rate. The service fields and delivery days arrive
     * as strings ("Instant", "Not Available", "4").
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromShiprocket(array $row, ?int $recommendedId): ?self
    {
        $id = $row['courier_company_id'] ?? null;
        $id = is_int($id) || (is_string($id) && ctype_digit($id)) ? (int) $id : 0;
        $name = is_string($row['courier_name'] ?? null) ? trim($row['courier_name']) : '';
        $rate = $row['rate'] ?? $row['freight_charge'] ?? null;

        if ($id <= 0 || $name === '' || ! is_numeric($rate)) {
            return null;
        }

        $days = $row['estimated_delivery_days'] ?? null;
        $days = is_int($days) ? $days : (is_string($days) && ctype_digit(trim($days)) ? (int) trim($days) : null);

        $services = [];
        foreach (self::SERVICES as $field => $labels) {
            $value = $row[$field] ?? null;
            $label = is_string($value) ? ($labels[strtolower(trim($value))] ?? null) : null;
            if ($label !== null) {
                $services[] = $label;
            }
        }

        return new self(
            courierId: $id,
            name: mb_substr($name, 0, 60),
            ratePaise: (int) round((float) $rate * 100),
            etdDays: $days,
            etd: is_string($row['etd'] ?? null) && trim($row['etd']) !== '' ? mb_substr(trim($row['etd']), 0, 40) : null,
            rating: is_numeric($row['rating'] ?? null) ? round((float) $row['rating'], 1) : null,
            surface: ($row['is_surface'] ?? false) === true || ($row['is_surface'] ?? null) === 1,
            services: $services,
            recommended: $recommendedId !== null && $recommendedId === $id,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'courier_id' => $this->courierId,
            'name' => $this->name,
            'rate' => '₹'.IndianNumber::format($this->ratePaise / 100, 2),
            'etd_days' => $this->etdDays,
            'etd' => $this->etd,
            'rating' => $this->rating,
            'mode' => $this->surface ? 'Surface' : 'Air',
            'services' => $this->services,
            'recommended' => $this->recommended,
        ];
    }
}
