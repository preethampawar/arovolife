<?php

declare(strict_types=1);

namespace App\Modules\Consent\Services;

use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Models\PlanChangeNotice;
use App\Modules\Consent\Models\PlanTextVersion;
use App\Modules\Consent\Notifications\PlanChangeAcceptedNotification;
use App\Modules\Consent\Notifications\PlanChangeNoticeNotification;
use App\Modules\Consent\Services\DTOs\NotifyUnreadSummary;
use App\Modules\Consent\Services\DTOs\PendingPlanChange;
use App\Modules\Consent\Services\DTOs\PlanChangeEntry;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Genealogy\Support\ReservedAdns;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\User;
use App\Modules\Shared\Features\PlanChangeNoticeFeature;
use DOMDocument;
use DOMElement;
use DOMNode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;
use Throwable;

/**
 * The compensation-plan change notice (plan page §12, R-123).
 *
 * When `/p/compensation` changes, every distributor whose last accepted plan
 * text differs is shown the changes after login and may click "I have read the
 * changes and agree", which is recorded exactly as registration records it: a
 * `plan` consent row with the version and the SHA-256 of the published body.
 * Anyone who has not agreed three days after the notice started is emailed the
 * same list once ({@see notifyUnread()}).
 *
 * The change list is cut from the published page itself (the "Changes to this
 * plan, by version" block), so the notice cannot say something the page does
 * not. The page is loaded directly rather than through ConsentDocuments, which
 * throws when any of the four pages is unpublished — that is right for
 * registration and wrong for a pop-up rendered on every page.
 */
final class PlanChangeNotices
{
    public const SLUG = 'compensation';

    public const EMAIL_AFTER_DAYS = 3;

    public const CHANGES_HEADING = 'Changes to this plan, by version';

    /** Account statuses still bound by the plan who can sign in and see the pop-up. */
    private const POPUP_STATUSES = ['pending', 'active', 'frozen'];

    /**
     * Statuses the email goes to. A rejected applicant cannot reach the
     * dashboard (they are sent to KYC resubmit) but still holds a live plan
     * consent and may be approved later, so they are told by email.
     */
    private const EMAIL_STATUSES = ['pending', 'active', 'frozen', 'rejected'];

    /** @var array<string, list<PlanChangeEntry>> */
    private array $entriesCache = [];

    /** @var array{page: ContentPage, hash: string, label: string|null}|false|null */
    private array|false|null $published = null;

    public function enabled(): bool
    {
        return Feature::for(null)->active(PlanChangeNoticeFeature::class);
    }

    /**
     * The published plan page with the raw SHA-256 of its body, or null while
     * it is unpublished.
     *
     * @return array{page: ContentPage, hash: string, label: string|null}|null
     */
    public function published(): ?array
    {
        if ($this->published === null) {
            $page = ContentPage::query()
                ->where('slug', self::SLUG)
                ->where('status', ContentPage::STATUS_PUBLISHED)
                ->first();

            $this->published = $page === null ? false : [
                'page' => $page,
                'hash' => hash('sha256', (string) $page->body, true),
                'label' => $this->label((string) $page->body),
            ];
        }

        return $this->published === false ? null : $this->published;
    }

    /**
     * The notice record for the published text, created the first time the
     * notice meets it while the flag is on. Its `notice_started_at` is the
     * moment the 3-day email clock runs from.
     */
    public function currentVersion(): ?PlanTextVersion
    {
        $published = $this->published();

        if ($published === null || $this->blockedReason() !== null) {
            return null;
        }

        $existing = PlanTextVersion::query()->where('doc_hash_sha256', $published['hash'])->first();

        if ($existing !== null) {
            return $existing;
        }

        // insertOrIgnore, not create: two first requests can race here.
        DB::table('plan_text_versions')->insertOrIgnore([
            'doc_hash_sha256' => $published['hash'],
            'label' => $published['label'],
            'notice_started_at' => Carbon::now(),
            'created_at' => Carbon::now(),
        ]);

        return PlanTextVersion::query()
            ->where('doc_hash_sha256', $published['hash'])
            ->firstOrFail();
    }

    /**
     * Why the published text must not be noticed, or null when it may be.
     *
     * A notice that lists nothing new is worse than none: it asks every
     * distributor to agree again to a list that does not show the change.
     * So the notice
     * holds — and the command says why — when the page has no version label,
     * when its change list cannot be read, when the newest entry in that list
     * is not the footer's version, or when the text changed but kept a label
     * an earlier text already carried (a body edit without a new version and
     * a matching entry).
     */
    public function blockedReason(): ?string
    {
        $published = $this->published();

        if ($published === null) {
            return null;
        }

        if ($published['label'] === null) {
            return 'The plan page footer has no "disclosure version: vYYYY-MM-DD" label.';
        }

        $entries = $this->entries((string) $published['page']->body);

        if ($entries === []) {
            return 'The plan page has no readable "'.self::CHANGES_HEADING.'" list.';
        }

        // Without this, a new footer version with no entry of its own went
        // out listing only the older entries: distributors would agree to a
        // list that does not show the change.
        if ($entries[0]->label !== $published['label']) {
            return 'The plan page footer says '.$published['label'].' but the newest entry in "'.self::CHANGES_HEADING.'" is '
                .$entries[0]->label.' — add a Small heading reading exactly '.$published['label'].' with the change under it.';
        }

        $labelReused = PlanTextVersion::query()
            ->where('label', $published['label'])
            ->where('doc_hash_sha256', '!=', $published['hash'])
            ->exists();

        if ($labelReused) {
            return 'The plan text changed but kept the version label '.$published['label']
                .' — bump the footer version and add a matching #### entry before this notice can go out.';
        }

        return null;
    }

    /**
     * The change this signed-in user still has to be told about, or null.
     */
    public function pendingFor(User $user): ?PendingPlanChange
    {
        if (! $this->enabled()
            || ! in_array($user->status, self::POPUP_STATUSES, true)
            || $user->isSuperStaff()) {
            return null;
        }

        $distributor = $user->distributor;
        $published = $this->published();

        if ($distributor === null
            || $published === null
            || $this->hasAccepted($distributor, $published['hash'])
            || $this->currentVersion() === null) {
            return null;
        }

        return new PendingPlanChange(
            hashHex: bin2hex($published['hash']),
            label: $published['label'],
            entries: $this->entriesFor($distributor, (string) $published['page']->body),
            coolingOffEndsAt: $distributor->cooling_off_end_at?->isFuture() ? $distributor->cooling_off_end_at : null,
        );
    }

    /**
     * Record that the pop-up was shown. Evidence only: it never stops the
     * email, because a dismissed pop-up leaves the distributor no copy to go
     * back to.
     */
    public function markShown(Distributor $distributor, PendingPlanChange $pending): void
    {
        $hash = (string) hex2bin($pending->hashHex);
        $now = Carbon::now();

        DB::table('plan_change_notices')->insertOrIgnore([
            'distributor_id' => $distributor->id,
            'doc_hash_sha256' => $hash,
            'labels_shown' => $pending->labelsShown(),
            'shown_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        PlanChangeNotice::query()
            ->where('distributor_id', $distributor->id)
            ->where('doc_hash_sha256', $hash)
            ->whereNull('shown_at')
            ->update(['shown_at' => $now, 'labels_shown' => $pending->labelsShown(), 'updated_at' => $now]);
    }

    /**
     * Record the distributor's agreement to the text they were shown. Returns
     * null when that text is no longer the published one — the page changed
     * between showing the pop-up and the click, so they must see the new list.
     */
    public function accept(Distributor $distributor, string $shownHashHex, string $ip, string $userAgent): ?Consent
    {
        $published = $this->published();

        // A held text was never shown, so nobody can have agreed to it.
        if ($published === null
            || $this->blockedReason() !== null
            || ! hash_equals(bin2hex($published['hash']), strtolower($shownHashHex))) {
            return null;
        }

        // A second tab or a double click: the agreement is already on record,
        // so return it rather than record it (and send the receipt) twice.
        $existing = Consent::query()
            ->live()
            ->where('distributor_id', $distributor->id)
            ->where('document_type', 'plan')
            ->where('doc_hash_sha256', $published['hash'])
            ->orderBy('id')
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $entries = $this->entriesFor($distributor, (string) $published['page']->body);

        $consent = DB::transaction(function () use ($distributor, $published, $ip, $userAgent, $entries): Consent {
            $now = Carbon::now();

            $consent = Consent::query()->create([
                'distributor_id' => $distributor->id,
                'document_type' => 'plan',
                // Same convention as ConsentDocuments, so every plan row is
                // dated the way registration dates it.
                'document_version' => $published['page']->updated_at->format('Y-m-d'),
                'doc_hash_sha256' => $published['hash'],
                'accepted_at' => $now,
                'ip' => $ip,
                'user_agent' => substr($userAgent, 0, 512),
            ]);

            DB::table('plan_change_notices')->insertOrIgnore([
                'distributor_id' => $distributor->id,
                'doc_hash_sha256' => $published['hash'],
                'labels_shown' => implode(',', array_map(fn (PlanChangeEntry $e): string => $e->label, $entries)),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            PlanChangeNotice::query()
                ->where('distributor_id', $distributor->id)
                ->where('doc_hash_sha256', $published['hash'])
                ->update(['accepted_consent_id' => $consent->id, 'updated_at' => $now]);

            return $consent;
        });

        $user = $distributor->user;

        if ($user !== null) {
            $user->notify(new PlanChangeAcceptedNotification(
                label: $published['label'],
                acceptedAt: $consent->accepted_at->copy()->timezone('Asia/Kolkata')->format('d M Y, H:i').' IST',
                entries: self::entriesAsArrays($entries),
                fingerprint: self::fingerprint($published['hash']),
            ));
        }

        return $consent;
    }

    /**
     * Email the notice to everyone still bound by the plan who has not agreed
     * to the published text, once the notice is EMAIL_AFTER_DAYS old. Sent
     * synchronously so `emailed_at` records an actual send; a failure is
     * recorded and retried on the next run.
     */
    public function notifyUnread(?Carbon $now = null): NotifyUnreadSummary
    {
        $now ??= Carbon::now();

        if (! $this->enabled() || ($published = $this->published()) === null) {
            return NotifyUnreadSummary::idle();
        }

        if (($blocked = $this->blockedReason()) !== null) {
            Log::error('plan_change_notice.blocked', ['reason' => $blocked]);

            return NotifyUnreadSummary::idle($blocked);
        }

        $version = $this->currentVersion();

        if ($version === null) {
            return NotifyUnreadSummary::idle();
        }

        $hash = $published['hash'];
        $body = (string) $published['page']->body;
        $dueFrom = $version->notice_started_at->copy()->addDays(self::EMAIL_AFTER_DAYS);
        $reserved = ReservedAdns::all();

        $emailedNow = 0;
        $skippedNoUser = 0;
        $failed = [];

        if ($now->greaterThanOrEqualTo($dueFrom)) {
            $this->notAcceptedQuery($hash)
                ->whereNotIn('adn', $reserved)
                ->whereNotExists(fn ($q) => $q->from('plan_change_notices')
                    ->whereColumn('plan_change_notices.distributor_id', 'distributors.id')
                    ->where('plan_change_notices.doc_hash_sha256', $hash)
                    ->whereNotNull('plan_change_notices.emailed_at'))
                ->with('user')
                ->chunkById(200, function ($distributors) use ($hash, $body, $published, $now, &$emailedNow, &$skippedNoUser, &$failed): void {
                    foreach ($distributors as $distributor) {
                        /** @var Distributor $distributor */
                        $user = $distributor->user;

                        if ($user === null) {
                            $skippedNoUser++;

                            continue;
                        }

                        $column = 'emailed_at';

                        try {
                            $user->notifyNow(new PlanChangeNoticeNotification(
                                adn: $distributor->adn,
                                name: (string) $user->full_name,
                                label: $published['label'],
                                entries: self::entriesAsArrays($this->entriesFor($distributor, $body)),
                                coolingOffEndsAt: $distributor->cooling_off_end_at?->isFuture()
                                    ? $distributor->cooling_off_end_at->format('d M Y')
                                    : null,
                                canConfirmOnline: in_array($user->status, self::POPUP_STATUSES, true),
                            ));
                            $emailedNow++;
                        } catch (Throwable $e) {
                            $column = 'email_failed_at';
                            $failed[] = $distributor->adn;
                            // The class only: a mailer message can carry the address.
                            Log::warning('plan_change_notice.email_failed', [
                                'distributor_id' => $distributor->id,
                                'error' => $e::class,
                            ]);
                        }

                        DB::table('plan_change_notices')->insertOrIgnore([
                            'distributor_id' => $distributor->id,
                            'doc_hash_sha256' => $hash,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);

                        PlanChangeNotice::query()
                            ->where('distributor_id', $distributor->id)
                            ->where('doc_hash_sha256', $hash)
                            ->update([$column => $now, 'updated_at' => $now]);
                    }
                });
        }

        $emailed = PlanChangeNotice::query()->where('doc_hash_sha256', $hash)->whereNotNull('emailed_at');

        return new NotifyUnreadSummary(
            label: $published['label'],
            noticeStartedAt: $version->notice_started_at,
            emailsDueFrom: $dueFrom,
            notAccepted: $this->notAcceptedQuery($hash)->whereNotIn('adn', $reserved)->count(),
            accepted: $this->boundQuery()->whereNotIn('adn', $reserved)->count()
                - $this->notAcceptedQuery($hash)->whereNotIn('adn', $reserved)->count(),
            emailedNow: $emailedNow,
            emailedEver: (clone $emailed)->count(),
            failedAdns: $failed,
            skippedNoUser: $skippedNoUser,
            reservedNotAccepted: $this->notAcceptedQuery($hash)->whereIn('adn', $reserved)->count(),
            lastEmailedAt: ($last = (clone $emailed)->max('emailed_at')) === null ? null : Carbon::parse((string) $last),
            neverReached: $this->notAcceptedQuery($hash)
                ->whereNotIn('adn', $reserved)
                ->whereExists(fn ($q) => $q->from('plan_change_notices')
                    ->whereColumn('plan_change_notices.distributor_id', 'distributors.id')
                    ->where('plan_change_notices.doc_hash_sha256', $hash)
                    ->whereNull('plan_change_notices.emailed_at')
                    ->whereNotNull('plan_change_notices.email_failed_at'))
                ->count(),
        );
    }

    /**
     * The entries a distributor is shown: every version newer than the one
     * they accepted, newest first. When the version they accepted is not on
     * record (it predates the notice), or nothing newer is found, every entry
     * is listed — saying too much is safe, an empty notice is not.
     *
     * @return list<PlanChangeEntry>
     */
    public function entriesFor(Distributor $distributor, string $html): array
    {
        $entries = $this->entries($html);

        $acceptedHash = Consent::query()
            ->live()
            ->where('distributor_id', $distributor->id)
            ->where('document_type', 'plan')
            ->orderByDesc('accepted_at')
            ->orderByDesc('id')
            ->value('doc_hash_sha256');

        $acceptedLabel = $acceptedHash === null ? null : PlanTextVersion::query()
            ->where('doc_hash_sha256', $acceptedHash)
            ->value('label');

        if ($acceptedLabel === null) {
            return $entries;
        }

        $newer = array_values(array_filter(
            $entries,
            fn (PlanChangeEntry $entry): bool => strcmp($entry->label, (string) $acceptedLabel) > 0,
        ));

        return $newer === [] ? $entries : $newer;
    }

    /**
     * Every entry under the "Changes to this plan, by version" heading of the
     * page HTML: each `<h4>` label with the content up to the next h4, or the
     * end of the block (next h2/h3/hr).
     *
     * @return list<PlanChangeEntry>
     */
    public function entries(string $html): array
    {
        return $this->entriesCache[sha1($html)] ??= $this->parseEntries($html);
    }

    /** @return list<PlanChangeEntry> */
    private function parseEntries(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8"?><div id="plan-root">'.$html.'</div>', LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('plan-root');

        if ($root === null) {
            return [];
        }

        $entries = [];
        $inBlock = false;
        $label = null;
        $buffer = '';

        foreach (iterator_to_array($root->childNodes) as $node) {
            $tag = $node instanceof DOMElement ? strtolower($node->tagName) : null;

            if (in_array($tag, ['h2', 'h3', 'hr'], true)) {
                if ($inBlock) {
                    break;
                }

                $inBlock = $tag === 'h3' && trim($node->textContent) === self::CHANGES_HEADING;

                continue;
            }

            if (! $inBlock) {
                continue;
            }

            if ($tag === 'h4') {
                if ($label !== null) {
                    $entries[] = new PlanChangeEntry($label, $buffer);
                }

                $label = trim($node->textContent);
                $buffer = '';

                continue;
            }

            if ($label !== null) {
                $buffer .= $this->outerHtml($document, $node);
            }
        }

        if ($label !== null) {
            $entries[] = new PlanChangeEntry($label, $buffer);
        }

        usort($entries, fn (PlanChangeEntry $a, PlanChangeEntry $b): int => strcmp($b->label, $a->label));

        return $entries;
    }

    /** The version label from the page footer ("disclosure version: v2026-10-27"). */
    public function label(string $html): ?string
    {
        return preg_match('/disclosure version:\s*(v\d{4}-\d{2}-\d{2})/i', strip_tags($html), $m) === 1 ? $m[1] : null;
    }

    /**
     * Distributors still bound by the plan, by account status.
     *
     * @return Builder<Distributor>
     */
    private function boundQuery(): Builder
    {
        return Distributor::query()->whereHas(
            'user',
            fn ($q) => $q->whereIn('status', self::EMAIL_STATUSES),
        );
    }

    /** @return Builder<Distributor> */
    private function notAcceptedQuery(string $hash): Builder
    {
        return $this->boundQuery()->whereDoesntHave(
            'consents',
            fn ($q) => $q->where('document_type', 'plan')
                ->whereNull('withdrawn_at')
                ->where('doc_hash_sha256', $hash),
        );
    }

    private function hasAccepted(Distributor $distributor, string $hash): bool
    {
        return Consent::query()
            ->live()
            ->where('distributor_id', $distributor->id)
            ->where('document_type', 'plan')
            ->where('doc_hash_sha256', $hash)
            ->exists();
    }

    private function outerHtml(DOMDocument $document, DOMNode $node): string
    {
        return (string) $document->saveHTML($node);
    }

    /**
     * Scalars only, so the entries survive the queue.
     *
     * @param  list<PlanChangeEntry>  $entries
     * @return list<array{label: string, html: string}>
     */
    private static function entriesAsArrays(array $entries): array
    {
        // Emails are read outside the site, so site-relative links become absolute.
        $absolute = fn (string $html): string => (string) preg_replace_callback(
            '/href="(\/[^"]*)"/',
            fn (array $m): string => 'href="'.url($m[1]).'"',
            $html,
        );

        return array_map(fn (PlanChangeEntry $e): array => ['label' => $e->label, 'html' => $absolute($e->html)], $entries);
    }

    /** First and last 8 hex characters of the text's SHA-256, to tie a receipt to the archived text. */
    private static function fingerprint(string $hash): string
    {
        $hex = bin2hex($hash);

        return substr($hex, 0, 8).'…'.substr($hex, -8);
    }
}
