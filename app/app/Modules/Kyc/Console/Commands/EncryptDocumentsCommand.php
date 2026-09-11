<?php

declare(strict_types=1);

namespace App\Modules\Kyc\Console\Commands;

use App\Modules\Kyc\Models\KycDocument;
use App\Modules\Kyc\Services\KycDocumentVault;
use Illuminate\Console\Command;

/**
 * One-off: encrypt the KYC scans uploaded before KycDocumentVault existed.
 * Safe to re-run — a row already stamped `encrypted_at` is skipped.
 */
final class EncryptDocumentsCommand extends Command
{
    protected $signature = 'kyc:encrypt-documents';

    protected $description = 'Encrypt KYC document objects stored before encryption at rest was introduced';

    public function handle(KycDocumentVault $vault): int
    {
        $converted = 0;
        $missing = 0;

        KycDocument::query()
            ->whereNull('encrypted_at')
            ->orderBy('id')
            ->chunkById(100, function ($documents) use ($vault, &$converted, &$missing): void {
                /** @var KycDocument $document */
                foreach ($documents as $document) {
                    if ($vault->encryptLegacy($document)) {
                        $converted++;
                    } else {
                        $missing++;
                        $this->warn("Document {$document->id}: object missing, left as is.");
                    }
                }
            });

        $this->info("Encrypted {$converted} document(s); {$missing} skipped.");

        return self::SUCCESS;
    }
}
