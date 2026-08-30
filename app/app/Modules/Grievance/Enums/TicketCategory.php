<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Enums;

/**
 * Grievance families published at `/p/grievance` §5.
 *
 * The first six cases predate this enum and are kept for the rows the
 * contact inbox already routed in; the rest mirror the published policy
 * headings one-for-one so the monthly compliance report can be grouped by
 * the same names the complainant saw on the form.
 */
enum TicketCategory: string
{
    case Order = 'order';
    case Payment = 'payment';
    case Refund = 'refund';
    case Account = 'account';
    case Product = 'product';
    case Compliance = 'compliance';
    case Kyc = 'kyc';
    case Compensation = 'compensation';
    case Genealogy = 'genealogy';
    case Ethics = 'ethics';
    // Code-of-ethics complaints against another distributor (client's
    // "Distributor Request" list, items 8–11, 30-08-2026). They are
    // complaints, not requests, so they live here and reach the Compliance
    // Committee like any other ethics matter.
    case Poaching = 'poaching';
    case CompetitiveBusiness = 'competitive_business';
    case ECommerceSelling = 'ecommerce_selling';
    case StockingUndercutting = 'stocking_undercutting';
    case Privacy = 'privacy';
    case Platform = 'platform';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Order => 'Order or delivery',
            self::Payment => 'Payment',
            self::Refund => 'Refund',
            self::Account => 'Account access',
            self::Product => 'Product',
            self::Compliance => 'Compliance',
            self::Kyc => 'Identity & KYC',
            self::Compensation => 'Compensation & payouts',
            self::Genealogy => 'Genealogy & placement',
            self::Ethics => 'Ethics & fraud',
            self::Poaching => 'Poaching (recruiting from another distributor\'s team)',
            self::CompetitiveBusiness => 'Competitive business (promoting another direct-selling company)',
            self::ECommerceSelling => 'Selling arovolife products on e-commerce sites',
            self::StockingUndercutting => 'Stocking and under-cutting (bulk stocking or selling below MRP)',
            self::Privacy => 'Privacy & data',
            self::Platform => 'Platform & security',
            self::Other => 'Something else',
        };
    }

    /**
     * Categories offered on the public grievance form, in the order the
     * published policy lists them. Deliberately excludes the legacy contact-
     * inbox cases so complainants pick from the published vocabulary.
     *
     * @return array<int, self>
     */
    public static function selectable(): array
    {
        return [
            self::Kyc,
            self::Compensation,
            self::Genealogy,
            self::Order,
            self::Refund,
            self::Ethics,
            self::Poaching,
            self::CompetitiveBusiness,
            self::ECommerceSelling,
            self::StockingUndercutting,
            self::Privacy,
            self::Platform,
            self::Other,
        ];
    }

    /**
     * Ethics and privacy complaints route straight past front-line care:
     * ethics matters belong to the Compliance Committee and data matters to
     * the DPO, both of whom sit above step 1 of the escalation matrix.
     */
    public function bypassesFrontLine(): bool
    {
        return $this->isEthics() || $this === self::Privacy;
    }

    /**
     * Every code-of-ethics family: the general case plus the four named
     * distributor-conduct complaints. Used wherever "ethics" visibility or
     * routing rules apply, so the named cases inherit them automatically.
     */
    public function isEthics(): bool
    {
        return in_array($this, [
            self::Ethics, self::Poaching, self::CompetitiveBusiness,
            self::ECommerceSelling, self::StockingUndercutting,
        ], true);
    }

    /**
     * Categories readable by the compliance side only: every ethics family
     * plus privacy. The queue, the ticket page and the monthly report all
     * hide these from anyone without `compliance.discipline`.
     *
     * @return list<string>
     */
    public static function sensitiveValues(): array
    {
        return array_map(
            fn (self $c): string => $c->value,
            array_values(array_filter(self::cases(), fn (self $c): bool => $c->bypassesFrontLine())),
        );
    }

    /** @return list<self> */
    public static function ethicsCases(): array
    {
        return array_values(array_filter(self::cases(), fn (self $c): bool => $c->isEthics()));
    }
}
