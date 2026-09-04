<?php

declare(strict_types=1);

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Models\RefundIntent;
use Illuminate\Support\Facades\DB;

/**
 * Queue a refund intent for sending once the surrounding transaction commits.
 *
 * A refund is real money leaving; dispatching inside a transaction that then
 * rolls back would refund a sale that never happened. The sender is
 * `SendRazorpayRefundJob`; until it is registered (Chunk 5) an unsent intent
 * simply waits, and the reconciler picks every unsent intent up regardless —
 * nothing is lost if this hook is a no-op.
 */
final class RefundDispatch
{
    /** @var (callable(RefundIntent): void)|null */
    private static $sender = null;

    /** @param  callable(RefundIntent): void  $sender */
    public static function using(callable $sender): void
    {
        self::$sender = $sender;
    }

    public static function afterCommit(RefundIntent $refund): void
    {
        $sender = self::$sender;
        if ($sender === null) {
            return;
        }

        DB::afterCommit(static fn () => $sender($refund));
    }
}
