<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

use InvalidArgumentException;

/**
 * The declarations the owner of an Arete Development Centre accepts (spec §A5).
 *
 * The text is versioned: bump VERSION when a wording changes so the stored
 * acceptance row says which text was agreed. Superseded text stays in
 * SUPERSEDED below — an acceptance row that records `version = 'v2'` is
 * evidence of nothing if the v2 wording has been deleted from the codebase.
 *
 * Wording is deliberately "centre" throughout — an ADC is a development /
 * training centre, never a shop, outlet or retail point (hard rule 7).
 */
final class AreteCenterDeclarations
{
    /**
     * v3 (2026-09-18, client-approved) — rewritten because R-21's closure
     * rested on a permission the v2 text did not contain. v2 said a centre
     * would not be an "e-commerce fulfilment point" full stop, which on its
     * face forbids the very thing the collection journey asks a centre to do;
     * routing parcels to an operator under that undertaking would have been
     * the company inducing breach of its own contract. v3 permits collection
     * of orders already placed and paid for on arovolife's own platform, and
     * prohibits the things that would actually make a centre a shop: stock,
     * prices, bills, payments, and orders from any other channel.
     */
    public const string VERSION = 'v3';

    /** @return array<string, string> key => declaration text */
    public static function all(): array
    {
        return [
            'training_use_only' => 'I will use the centre only for training, product demonstration, distributor support, and the supervised collection of orders that a buyer has already placed and paid for on arovolife\'s own platform and has chosen to collect at this centre. I will not use it as a retail store or outlet. I will not hold, display, stock or offer any product for sale at the centre; I will not quote a price, raise a bill or accept any payment from any person at the centre; and I will not accept or fulfil an order from any e-commerce marketplace or from any channel other than arovolife\'s own platform. I will release a parcel only to the named buyer or their authorised representative, only against the collection code, and I will record every handover on the platform on the day it happens. I will not hold an uncollected parcel beyond the period arovolife publishes to me in writing, after which I will return it. (Direct Seller Agreement §5.1 and §5.2.)',
            'buyer_data_duty' => 'Any buyer\'s name, contact details or order information I am shown so that I can hand over a parcel belongs to arovolife, not to me. I will use it only to complete that handover; I will not copy, retain, publish, share or use it to market anything; and I will delete or return it when arovolife asks or when my centre closes.',
            'details_true' => 'The premises details and documents I have provided are true and complete.',
            'inspection_and_phases' => 'I understand the centre may be inspected, and that the development-phase requirements for premises size and facilities are applied as published in the compensation plan.',
            'deactivation_consent' => 'I understand that if the centre is not developed within the period arovolife notifies to me in writing, the centre may be deactivated or transferred to another distributor after at least 30 days\' written notice stating the reason, and that I may raise any objection through the grievance redressal process before the notice period ends.',
            'contact_consent' => 'I consent to arovolife contacting me on the numbers I have given about this centre.',
        ];
    }

    /**
     * Wording that is no longer offered but has been accepted by somebody, so
     * it must remain renderable. Never edit an entry here — it is a record of
     * what a person agreed to, not a draft.
     *
     * @var array<string, array<string, string>>
     */
    private const array SUPERSEDED = [
        'v2' => [
            'training_use_only' => 'I will use the centre only for training, product demonstration and distributor support — not as a retail store, outlet or e-commerce fulfilment point (Direct Seller Agreement §9).',
            'details_true' => 'The premises details and documents I have provided are true and complete.',
            'inspection_and_phases' => 'I understand the centre may be inspected, and that the development-phase requirements for premises size and facilities are applied as published in the compensation plan.',
            'deactivation_consent' => 'I understand that if the centre is not developed within the period arovolife notifies to me in writing, the centre may be deactivated or transferred to another distributor after at least 30 days\' written notice stating the reason, and that I may raise any objection through the grievance redressal process before the notice period ends.',
            'contact_consent' => 'I consent to arovolife contacting me on the numbers I have given about this centre.',
        ],
    ];

    /**
     * The text as it stood at a given version, for rendering an acceptance
     * record. Throws rather than falling back to the current wording: showing
     * today's text against yesterday's acceptance would misrepresent what was
     * agreed, which is worse than showing nothing.
     *
     * @return array<string, string>
     */
    public static function forVersion(string $version): array
    {
        if ($version === self::VERSION) {
            return self::all();
        }

        return self::SUPERSEDED[$version]
            ?? throw new InvalidArgumentException("No declaration text is on file for version {$version}.");
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
