<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Services;

use Illuminate\Support\Facades\DB;

/**
 * Admin-owned settings for the Arete Development Centre registry. Read from
 * the `settings` table so operations can change them without a deploy; the
 * defaults here mirror the registry in AdminSettingsController.
 */
final class AreteCenterRegistrySettings
{
    public const string KEY_MIN_PREMISES_SQFT = 'adc.min_premises_sqft';

    public const int DEFAULT_MIN_PREMISES_SQFT = 350;

    public const string KEY_MAX_PHOTO_KB = 'adc.max_photo_kb';

    public const int DEFAULT_MAX_PHOTO_KB = 500;

    /**
     * Smallest premises an application may declare, in square feet. Pre-filled
     * on the form and enforced server-side against the same value (the
     * client's decision #3, 2026-08-30).
     */
    public function minPremisesSqft(): int
    {
        $value = DB::table('settings')->where('key', self::KEY_MIN_PREMISES_SQFT)->value('value');

        if ($value === null || ! is_numeric($value)) {
            return self::DEFAULT_MIN_PREMISES_SQFT;
        }

        return max(1, (int) $value);
    }

    /**
     * Largest premises photo accepted, in kilobytes. The client's reference
     * form says 500 KB; the application form shrinks larger phone photos in
     * the browser before upload so the cap is workable in practice.
     */
    public function maxPhotoKb(): int
    {
        $value = DB::table('settings')->where('key', self::KEY_MAX_PHOTO_KB)->value('value');

        if ($value === null || ! is_numeric($value)) {
            return self::DEFAULT_MAX_PHOTO_KB;
        }

        return max(50, (int) $value);
    }
}
