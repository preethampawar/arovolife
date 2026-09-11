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
        // OFF until the environment's published Privacy Policy carries §4.5b.
        // See reportingEnabled() for why the default is the restrictive one.
        'messaging.reporting_enabled' => false,
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

    /**
     * Whether a distributor can report a message to the company.
     *
     * Defaults OFF, unlike every other control here. Moderation means staff
     * reading a private message, and DPDP §5 requires the purpose to be
     * published before the processing starts — the moderation purpose (§4.5b)
     * and the retention rows live in `privacy.md`, which reaches members only
     * when that environment runs `php artisan content:publish privacy`. An ON
     * default moderates against a notice the company has not served in any
     * environment where that step was missed, which is a state nobody can see
     * from the repo (R-80(a)).
     *
     * So it is switched on per environment, as an audited settings edit, once
     * the live Privacy Policy page has been read and carries §4.5b.
     */
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
