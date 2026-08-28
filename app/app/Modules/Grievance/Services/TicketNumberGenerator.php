<?php

declare(strict_types=1);

namespace App\Modules\Grievance\Services;

use App\Modules\Grievance\Models\Ticket;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Issues the unique complaint number promised by policy §6.1 and required by
 * DSR 2021 Rule 12.
 *
 * Format: `GRV-YYMMDD-XXXXX`, e.g. `GRV-260816-7KQ2M`.
 *
 * The date prefix is deliberate — a complainant reads their number out over
 * the helpline, and the receiving agent can tell at a glance roughly how old
 * the complaint is. The random suffix (rather than a running sequence) avoids
 * leaking daily complaint volume to anyone holding two ticket numbers.
 *
 * Ambiguous glyphs are excluded from the alphabet because these numbers are
 * dictated over the phone and copied off postal acknowledgements.
 */
final class TicketNumberGenerator
{
    private const ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private const MAX_ATTEMPTS = 8;

    public function generate(?Carbon $on = null): string
    {
        $date = ($on ?? Carbon::now())->format('ymd');

        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++) {
            $candidate = sprintf('GRV-%s-%s', $date, $this->randomSuffix());

            if (! Ticket::where('ticket_no', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new RuntimeException(
            'Could not allocate a unique grievance number after '.self::MAX_ATTEMPTS.' attempts.'
        );
    }

    private function randomSuffix(): string
    {
        $suffix = '';

        for ($i = 0; $i < 5; $i++) {
            $suffix .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $suffix;
    }
}
