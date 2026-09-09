<?php

declare(strict_types=1);

namespace App\Modules\Messaging\Services;

use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every tunable messaging control.
 *
 * Messaging shipped as a deliberately permissive MVP: any authenticated user
 * could message any other, at any rate, with any content. The controls that
 * close that are policy rather than mechanism — how strictly a company polices
 * its own distributors' channel is a business judgement, and one that will be
 * revisited the first time a moderation incident happens. They are therefore
 * settings, not constants, so tightening the regime is an audited settings edit
 * rather than a deploy.
 *
 * Bound as a singleton (see MessagingServiceProvider) so the settings table is
 * read at most once per request or command run. Mirrors the shape of
 * GrievanceSettingsService deliberately.
 */
final class MessagingSettingsService
{
    /** Anyone authenticated may be messaged — the Phase 1 MVP behaviour. */
    public const AUDIENCE_ANYONE = 'anyone';

    /** Only a distributor's own Genos downline, their upline, and their sponsor line. */
    public const AUDIENCE_DOWNLINE_UPLINE = 'downline_upline';

    /** Registry defaults — used when a key is absent from the settings table. */
    private const SCALAR_DEFAULTS = [
        'messaging.audience' => self::AUDIENCE_DOWNLINE_UPLINE,
        'messaging.rate_limit_per_hour' => 60,
        'messaging.rate_limit_per_recipient_per_day' => 20,
        'messaging.block_list_enabled' => true,
        'messaging.reporting_enabled' => true,
        'messaging.max_body_chars' => 4000,
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    /**
     * Who a non-staff distributor may message. An unrecognised stored value
     * falls back to the restrictive setting rather than the permissive one —
     * a typo in the settings table must not silently open the channel.
     */
    public function audience(): string
    {
        $value = $this->scalar('messaging.audience');

        return $value === self::AUDIENCE_ANYONE
            ? self::AUDIENCE_ANYONE
            : self::AUDIENCE_DOWNLINE_UPLINE;
    }

    public function restrictsAudience(): bool
    {
        return $this->audience() === self::AUDIENCE_DOWNLINE_UPLINE;
    }

    public function rateLimitPerHour(): int
    {
        return $this->scalarInt('messaging.rate_limit_per_hour');
    }

    public function rateLimitPerRecipientPerDay(): int
    {
        return $this->scalarInt('messaging.rate_limit_per_recipient_per_day');
    }

    public function blockListEnabled(): bool
    {
        return $this->scalarBool('messaging.block_list_enabled');
    }

    public function reportingEnabled(): bool
    {
        return $this->scalarBool('messaging.reporting_enabled');
    }

    public function maxBodyChars(): int
    {
        return $this->scalarInt('messaging.max_body_chars');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function scalarInt(string $key): int
    {
        $value = $this->scalar($key);

        return $value !== null ? (int) $value : (int) (self::SCALAR_DEFAULTS[$key] ?? 0);
    }

    private function scalarBool(string $key): bool
    {
        $value = $this->scalar($key);
        if ($value === null) {
            return (bool) (self::SCALAR_DEFAULTS[$key] ?? false);
        }

        return in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true);
    }

    private function scalar(string $key): ?string
    {
        if ($this->scalarCache === null) {
            $this->scalarCache = DB::table('settings')->pluck('value', 'key')->all();
        }

        $value = $this->scalarCache[$key] ?? null;

        return $value === null ? null : (string) $value;
    }
}
