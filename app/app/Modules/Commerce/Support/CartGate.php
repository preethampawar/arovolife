<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Support;

use App\Modules\Identity\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Pre-launch cart gate. `commerce.cart.enabled` OFF closes adds, cart sharing
 * and checkout for distributors and guests while the catalogue stays
 * browsable; every back-office role bypasses it so the team can keep testing.
 * A missing row means OPEN so a deploy never closes the cart by itself.
 *
 * Bound as a singleton: the setting is read once per request however many
 * product cards ask.
 */
final class CartGate
{
    public const SETTING_KEY = 'commerce.cart.enabled';

    public const CLOSED_MESSAGE = 'Ordering opens at launch. You can browse the catalogue until then.';

    private ?bool $settingOpen = null;

    public function isOpenFor(?User $user): bool
    {
        if ($user?->isStaff()) {
            return true;
        }

        return $this->settingOpen ??= $this->readSetting();
    }

    private function readSetting(): bool
    {
        $value = DB::table('settings')->where('key', self::SETTING_KEY)->value('value');

        return $value === null || $value === 'true';
    }
}
