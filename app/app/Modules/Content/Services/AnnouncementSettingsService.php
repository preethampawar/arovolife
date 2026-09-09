<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use Illuminate\Support\Facades\DB;

/**
 * The two announcement controls and the one FAQ control.
 *
 * Bound as a singleton (see ContentServiceProvider) so the settings table is
 * read at most once per request. Mirrors GrievanceSettingsService.
 */
final class AnnouncementSettingsService
{
    /** Registry defaults — used when a key is absent from the settings table. */
    private const SCALAR_DEFAULTS = [
        'announcements.email_copy' => false,
        'announcements.pin_limit' => 3,
        'faq.members_only' => true,
    ];

    /** @var array<string, mixed>|null */
    private ?array $scalarCache = null;

    public function emailsCopies(): bool
    {
        return $this->scalarBool('announcements.email_copy');
    }

    public function pinLimit(): int
    {
        return $this->scalarInt('announcements.pin_limit');
    }

    public function faqIsMembersOnly(): bool
    {
        return $this->scalarBool('faq.members_only');
    }

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
