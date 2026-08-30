<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Support;

/**
 * The declarations an applicant accepts when applying to open an Arete
 * Development Centre (spec §A5). The text is versioned: bump VERSION when a
 * wording changes so the stored acceptance row says which text was agreed.
 *
 * Wording is deliberately "centre" throughout — an ADC is a development /
 * training centre, never a shop, outlet or retail point (hard rule 7).
 */
final class AreteCenterDeclarations
{
    public const string VERSION = 'v2';

    /** @return array<string, string> key => declaration text */
    public static function all(): array
    {
        return [
            'training_use_only' => 'I will use the centre only for training, product demonstration and distributor support — not as a retail store, outlet or e-commerce fulfilment point (Direct Seller Agreement §9).',
            'details_true' => 'The premises details and documents I have provided are true and complete.',
            'inspection_and_phases' => 'I understand the centre may be inspected, and that the development-phase requirements for premises size and facilities are applied as published in the compensation plan.',
            'deactivation_consent' => 'I understand that if the centre is not developed within the period arovolife notifies to me in writing, the centre may be deactivated or transferred to another distributor after at least 30 days\' written notice stating the reason, and that I may raise any objection through the grievance redressal process before the notice period ends.',
            'contact_consent' => 'I consent to arovolife contacting me on the numbers I have given about this centre.',
        ];
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::all());
    }
}
