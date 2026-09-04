<?php

declare(strict_types=1);

namespace App\Modules\Payments\Data;

/**
 * The gateway's view of one payment, as fetched from its API — never as
 * pushed by a browser or a webhook body. `PaymentConfirmationService`
 * confirms an order only from one of these.
 */
final readonly class GatewayPayment
{
    /**
     * @param  array<string, mixed>  $scrubbed  the allow-listed payload, safe to persist
     */
    public function __construct(
        public string $id,
        public ?string $orderId,
        public int $amountPaise,
        public string $currency,
        public string $status,
        public bool $captured,
        public int $amountRefundedPaise,
        public ?string $method,
        public ?string $errorCode,
        public ?string $errorDescription,
        public array $scrubbed,
    ) {}

    /**
     * @param  array<string, mixed>  $entity  a Razorpay payment entity
     * @param  array<string, mixed>  $scrubbed  the same entity after the scrubber
     */
    public static function fromEntity(array $entity, array $scrubbed): self
    {
        return new self(
            id: (string) $entity['id'],
            orderId: isset($entity['order_id']) ? (string) $entity['order_id'] : null,
            amountPaise: (int) ($entity['amount'] ?? 0),
            currency: (string) ($entity['currency'] ?? ''),
            status: (string) ($entity['status'] ?? ''),
            captured: (bool) ($entity['captured'] ?? false),
            amountRefundedPaise: (int) ($entity['amount_refunded'] ?? 0),
            method: isset($entity['method']) ? (string) $entity['method'] : null,
            errorCode: isset($entity['error_code']) ? (string) $entity['error_code'] : null,
            errorDescription: isset($entity['error_description']) ? (string) $entity['error_description'] : null,
            scrubbed: $scrubbed,
        );
    }

    public function isCaptured(): bool
    {
        return $this->captured && $this->status === 'captured';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }
}
