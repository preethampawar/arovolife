<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Erasure;

/**
 * What a partner erasure would remove: the target, every Genos descendant and
 * any couple partner, split by the target's Left / Right leg, plus everything
 * that stops it. Read-only; the fingerprint pins the exact erase set so the
 * execute step can refuse when the tree moved after the preview.
 */
final readonly class PartnerErasurePreview
{
    /**
     * @param  list<array{id: int, adn: string, name: string|null, depth: int|null, leg: 'L'|'R'|null, side: 'L'|'R'|null, parentAdn: string|null, isSpouse: bool}>  $members  every member except the target
     * @param  list<string>  $leftAdns
     * @param  list<string>  $rightAdns
     * @param  list<array{code: string, message: string}>  $blockers
     * @param  array{orders: int, invoices: int, kyc_documents: int, wallet_entries: int}  $counts
     */
    public function __construct(
        public int $targetId,
        public string $targetAdn,
        public ?string $targetName,
        public array $members,
        public array $leftAdns,
        public array $rightAdns,
        public array $blockers,
        public array $counts,
        public string $fingerprint,
    ) {}

    public function canErase(): bool
    {
        return $this->blockers === [];
    }
}
