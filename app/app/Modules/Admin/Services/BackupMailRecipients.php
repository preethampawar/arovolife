<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services;

use Illuminate\Support\Facades\DB;

/**
 * The extra mailboxes (no account needed) that receive the daily backup
 * result email, kept in one admin-owned setting as a comma-separated list.
 */
final class BackupMailRecipients
{
    public const SETTING_KEY = 'notifications.backup_status_emails';

    public const MAX = 10;

    /**
     * Split a typed list on commas, semicolons and whitespace; lowercased,
     * de-duplicated, first-seen order. No validation here.
     *
     * @return list<string>
     */
    public static function parse(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_map(static fn (string $p): string => mb_strtolower(trim($p)), $parts)));
    }

    /**
     * @param  list<string>  $addresses
     * @return list<string>
     */
    public static function invalid(array $addresses): array
    {
        return array_values(array_filter($addresses, static fn (string $a): bool => ! filter_var($a, FILTER_VALIDATE_EMAIL)));
    }

    /**
     * The stored valid addresses, at most MAX. A missing row or empty value
     * gives none; a malformed entry is skipped rather than failing the mail.
     *
     * @return list<string>
     */
    public function extra(): array
    {
        $raw = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');

        $addresses = self::parse(is_string($raw) ? $raw : '');

        return array_slice(array_values(array_diff($addresses, self::invalid($addresses))), 0, self::MAX);
    }
}
