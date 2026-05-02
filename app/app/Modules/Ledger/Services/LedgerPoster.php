<?php

declare(strict_types=1);

namespace App\Modules\Ledger\Services;

use App\Modules\Ledger\Exceptions\UnbalancedLedgerException;
use App\Modules\Ledger\Models\LedgerAccount;
use App\Modules\Ledger\Models\LedgerEntry;
use App\Modules\Ledger\Models\LedgerTx;
use Carbon\CarbonInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * The ONLY way to write to ledger_entries.
 *
 * Enforces:
 *   - sum(debits) = sum(credits) per transaction
 *   - amount_paise > 0 on every line
 *   - idempotency key uniqueness (retry-safe)
 */
final class LedgerPoster
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * Post a balanced double-entry transaction.
     *
     * @param  array<int, array{account: string, side: 'debit'|'credit', amount_paise: int}>  $lines
     */
    public function post(
        string $sourceModule,
        string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        array $lines,
        ?string $memo = null,
        ?int $createdByUserId = null,
        ?CarbonInterface $occurredAt = null,
    ): LedgerTx {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('Ledger tx requires at least 2 entries.');
        }

        $debits = 0;
        $credits = 0;
        foreach ($lines as $i => $line) {
            if (! isset($line['account'], $line['side'], $line['amount_paise'])) {
                throw new InvalidArgumentException("Line {$i} missing required keys: account, side, amount_paise.");
            }
            if (! in_array($line['side'], ['debit', 'credit'], true)) {
                throw new InvalidArgumentException("Line {$i} side must be debit or credit.");
            }
            if ($line['amount_paise'] <= 0) {
                throw new InvalidArgumentException("Line {$i} amount_paise must be > 0.");
            }
            if ($line['side'] === 'debit') {
                $debits += $line['amount_paise'];
            }
            if ($line['side'] === 'credit') {
                $credits += $line['amount_paise'];
            }
        }

        if ($debits !== $credits) {
            throw new UnbalancedLedgerException($debits, $credits);
        }

        return $this->db->transaction(function () use (
            $sourceModule, $sourceType, $sourceId, $idempotencyKey, $lines, $memo, $createdByUserId, $occurredAt,
        ) {
            // Idempotency — return existing tx on replay
            $existing = LedgerTx::where('idempotency_key', $idempotencyKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            $tx = LedgerTx::create([
                'occurred_at' => $occurredAt ?? Carbon::now(),
                'source_module' => $sourceModule,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'idempotency_key' => $idempotencyKey,
                'memo' => $memo,
                'created_by_user_id' => $createdByUserId,
            ]);

            foreach ($lines as $line) {
                $account = LedgerAccount::where('code', $line['account'])->first();
                if ($account === null) {
                    throw new InvalidArgumentException("Unknown ledger account: {$line['account']}");
                }
                LedgerEntry::create([
                    'ledger_tx_id' => $tx->id,
                    'account_id' => $account->id,
                    'side' => $line['side'],
                    'amount_paise' => $line['amount_paise'],
                    'currency' => 'INR',
                ]);
            }

            return $tx;
        });
    }

    /**
     * Shortcut: Dr A, Cr B for the same amount.
     */
    public function transfer(
        string $sourceModule,
        string $sourceType,
        ?int $sourceId,
        string $idempotencyKey,
        string $debitAccount,
        string $creditAccount,
        int $amountPaise,
        ?string $memo = null,
        ?int $createdByUserId = null,
    ): LedgerTx {
        return $this->post(
            sourceModule: $sourceModule,
            sourceType: $sourceType,
            sourceId: $sourceId,
            idempotencyKey: $idempotencyKey,
            lines: [
                ['account' => $debitAccount,  'side' => 'debit',  'amount_paise' => $amountPaise],
                ['account' => $creditAccount, 'side' => 'credit', 'amount_paise' => $amountPaise],
            ],
            memo: $memo,
            createdByUserId: $createdByUserId,
        );
    }
}
