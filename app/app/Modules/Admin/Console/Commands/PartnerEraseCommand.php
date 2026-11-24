<?php

declare(strict_types=1);

namespace App\Modules\Admin\Console\Commands;

use App\Modules\Admin\Services\Erasure\PartnerErasureRefused;
use App\Modules\Admin\Services\Erasure\PartnerErasureService;
use App\Modules\Identity\Models\User;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * The server-side twin of the developer's "Delete a partner" page, for servers
 * whose web PHP cannot start the mysqldump that production takes first. Same
 * service, same preview, same blockers; the APN is typed again to confirm
 * (or passed as --confirm where there is no terminal).
 *
 *     php artisan partners:erase 123456789 --actor=dev@example.com
 */
final class PartnerEraseCommand extends Command
{
    protected $signature = 'partners:erase
        {adn : APN of the partner to delete}
        {--actor= : Email of the developer account doing the delete}
        {--confirm= : The APN typed again, for runs without a terminal}';

    protected $description = 'Permanently delete a partner and everyone below them in the Genos (developer only)';

    public function handle(PartnerErasureService $erasure): int
    {
        $adn = (string) $this->argument('adn');
        if (preg_match('/^\d{9}$/', $adn) !== 1) {
            $this->error('An APN is nine digits.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->option('actor')));
        if ($email === '') {
            $this->error('--actor is required: the email of the developer account doing the delete.');

            return self::FAILURE;
        }

        $actor = User::query()->where('email', $email)->first();
        if ($actor === null || ! $actor->hasRole('developer')) {
            $this->error('No developer account has that email.');

            return self::FAILURE;
        }

        try {
            $preview = $erasure->preview($adn);
        } catch (PartnerErasureRefused $e) {
            foreach ($e->blockers as $blocker) {
                $this->error($blocker['message']);
            }

            return self::FAILURE;
        }

        $members = collect($preview->members);
        $this->line("Partner to delete: APN {$preview->targetAdn}, ".($preview->targetName ?: 'no name on record'));
        $this->line("{$members->count()} partners below them will be deleted with them.");

        foreach (['L' => 'Left Genos', 'R' => 'Right Genos'] as $leg => $label) {
            $rows = $members->where('leg', $leg);
            $this->line("{$label} ({$rows->count()})");
            if ($rows->isNotEmpty()) {
                $this->table(['APN', 'Depth', 'Side', 'Parent APN'], $rows->map(fn (array $m): array => [
                    $m['adn'],
                    $m['depth'] ?? '—',
                    $m['side'] === 'L' ? 'Left' : ($m['side'] === 'R' ? 'Right' : '—'),
                    $m['parentAdn'] ?? '—',
                ])->all());
            }
        }

        $couple = $members->where('leg', null);
        if ($couple->isNotEmpty()) {
            $this->line('Couple partner ('.$couple->count().'): '.$couple->pluck('adn')->implode(', '));
        }

        $this->line("Orders: {$preview->counts['orders']}, invoices: {$preview->counts['invoices']}, wallet entries: {$preview->counts['wallet_entries']} stay without personal details. KYC documents deleted: {$preview->counts['kyc_documents']}.");

        if (! $preview->canErase()) {
            $this->error('This partner cannot be deleted yet:');
            foreach ($preview->blockers as $blocker) {
                $this->error('- '.$blocker['message']);
            }
            $this->error('Nothing was deleted.');

            return self::FAILURE;
        }

        $confirm = $this->option('confirm') ?? $this->ask('Type the APN again to confirm');
        if (! is_string($confirm) || $confirm === '' || ! hash_equals($adn, $confirm)) {
            $this->error('The APN you typed again does not match. Nothing was deleted.');

            return self::FAILURE;
        }

        try {
            $result = $erasure->execute($adn, $preview->fingerprint, $actor);
        } catch (PartnerErasureRefused $e) {
            $this->error('Nothing was deleted.');
            foreach ($e->blockers as $blocker) {
                $this->error($blocker['message']);
            }

            return self::FAILURE;
        } catch (Throwable $e) {
            $reason = $e instanceof QueryException ? 'A database error occurred.' : $e->getMessage();
            $this->error("Nothing was deleted. {$reason}");

            return self::FAILURE;
        }

        $this->info("APN {$adn} and ".($result->erasedCount - 1).' partners below it were deleted.');
        $this->line("Files deleted: {$result->filesDeleted}, could not be removed: {$result->filesFailed}.");
        if ($result->backupFile !== null) {
            $this->line("Backup taken first: {$result->backupFile}");
        }

        return self::SUCCESS;
    }
}
