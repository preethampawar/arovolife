<?php

declare(strict_types=1);

namespace App\Modules\Content\Services;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Compliance\Support\AuditDigests;
use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Exceptions\PlanChangeRefused;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Content\Models\ContentPageRevision;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A second person approves every change to the Compensation Plan page (R-125).
 *
 * From 2026-10-26 the plan is changed in the admin editor, not in code, so the
 * code review that stood between one person and the text every distributor
 * agrees to is gone. A plan edit is saved as a {@see ContentPageRevision}
 * instead, and reaches the page only when somebody other than its author
 * approves it — the same maker-checker the GSB reversals use (R-92).
 *
 * The approval, not the request, is the publication: the page's updated_at
 * (the consent version) and the "effective from the day after publication"
 * rule (plan §12) both run from the moment it is approved. So the notice
 * checks are made again at approval, against that day.
 */
final class PlanChangeApprovals
{
    /** The page fields a revision proposes and the approver signs off. */
    public const GOVERNED = ['title', 'meta_description', 'status', 'body'];

    public function __construct(private readonly PlanChangeNotices $notices) {}

    public static function governs(ContentPage $page): bool
    {
        return $page->slug === PlanChangeNotices::SLUG;
    }

    public function pending(ContentPage $page): ?ContentPageRevision
    {
        return ContentPageRevision::query()
            ->where('content_page_id', $page->id)
            ->where('state', ContentPageRevision::STATE_PENDING)
            ->latest('id')
            ->first();
    }

    /**
     * Save a proposed change for a second person to approve. The page itself
     * is not touched.
     *
     * @param  array{title: string, meta_description: string|null, status: string, body: string}  $proposed
     */
    public function request(ContentPage $page, array $proposed, string $note, int $requestedBy): ContentPageRevision
    {
        return DB::transaction(function () use ($page, $proposed, $note, $requestedBy): ContentPageRevision {
            // Serialises two authors saving at once, so the second one meets
            // the first one's pending change instead of creating a twin.
            $locked = ContentPage::query()->whereKey($page->id)->lockForUpdate()->firstOrFail();

            if ($this->pending($locked) !== null) {
                throw new PlanChangeRefused('A change to this page is already waiting for approval. It has to be approved, rejected or withdrawn before another one can be proposed.');
            }

            if ($this->changedFields($locked, $proposed) === []) {
                throw new PlanChangeRefused('Nothing on the page was changed, so there is nothing to approve.');
            }

            $problem = $this->noticeProblem($locked, $proposed, Carbon::now());

            if ($problem !== null) {
                throw new PlanChangeRefused($problem);
            }

            $revision = ContentPageRevision::create([
                'content_page_id' => $locked->id,
                'base_digest' => $this->digest($locked),
                'title' => $proposed['title'],
                'meta_description' => $proposed['meta_description'],
                'body' => $proposed['body'],
                'status' => $proposed['status'],
                'state' => ContentPageRevision::STATE_PENDING,
                'note' => $note,
                'requested_by' => $requestedBy,
            ]);

            $this->audit('content_page.change_requested', $requestedBy, $locked, $revision, [
                'changed' => $this->changedFields($locked, $proposed),
            ]);

            return $revision;
        });
    }

    /**
     * Put an approved change on the page. The approver becomes the page's
     * last editor and today becomes its version date.
     */
    public function approve(ContentPageRevision $revision, int $approverId, ?string $decisionNote): ContentPage
    {
        // Outside the transaction, so the refusal's audit row survives the
        // throw. requested_by never changes after the proposal is made.
        if ($revision->requested_by !== null && $revision->requested_by === $approverId) {
            AuditLog::create([
                'actor_id' => $approverId,
                'action' => 'content_page.change_self_approval_refused',
                'subject_type' => 'content_page',
                'subject_id' => $revision->content_page_id,
                'details' => ['slug' => PlanChangeNotices::SLUG, 'revision_id' => $revision->id],
            ]);

            throw new PlanChangeRefused('You proposed this change, so you cannot also approve it. A second person has to read it and approve it.');
        }

        return DB::transaction(function () use ($revision, $approverId, $decisionNote): ContentPage {
            $locked = ContentPageRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $page = ContentPage::query()->whereKey($locked->content_page_id)->lockForUpdate()->firstOrFail();

            $this->assertPending($locked);

            // Approving is publishing. A plan change nobody is told about
            // breaks plan §12, so it waits until the notice is back on.
            if (! $this->notices->enabled()) {
                throw new PlanChangeRefused('The plan-change notice is switched off, so distributors would not be told about this change. Ask the developer to switch on content.plan_change_notice, then approve it.');
            }

            if (! hash_equals($locked->base_digest, $this->digest($page))) {
                throw new PlanChangeRefused('The page has changed since this was proposed, so this is no longer the change that was reviewed. Reject it and propose it again from the current page.');
            }

            $proposed = $this->proposed($locked);
            $problem = $this->noticeProblem($page, $proposed, Carbon::now());

            if ($problem !== null) {
                throw new PlanChangeRefused($problem.' Reject this change and propose it again with the date corrected.');
            }

            $before = AuditDigests::snapshot($page);

            if (! $page->isPublished() && $proposed['status'] === ContentPage::STATUS_PUBLISHED) {
                $page->published_at = Carbon::now();
            }

            $page->fill($proposed);
            $page->edited_in_portal_at = Carbon::now();
            $page->updated_by_user_id = $approverId;
            $page->save();

            $locked->update([
                'state' => ContentPageRevision::STATE_APPROVED,
                'decided_by' => $approverId,
                'decided_at' => Carbon::now(),
                'decision_note' => $decisionNote,
            ]);

            $this->audit('content_page.change_approved', $approverId, $page, $locked, [
                'requested_by' => $locked->requested_by,
            ], $before);

            return $page;
        });
    }

    public function reject(ContentPageRevision $revision, int $deciderId, string $reason): void
    {
        $this->close($revision, $deciderId, ContentPageRevision::STATE_REJECTED, $reason, 'content_page.change_rejected');
    }

    /** Only the author withdraws their own proposal; anyone else rejects it. */
    public function withdraw(ContentPageRevision $revision, int $requesterId): void
    {
        if ($revision->requested_by !== $requesterId) {
            throw new PlanChangeRefused('Only the person who proposed a change can withdraw it. Reject it instead, with a reason.');
        }

        $this->close($revision, $requesterId, ContentPageRevision::STATE_WITHDRAWN, null, 'content_page.change_withdrawn');
    }

    /**
     * Why this proposed page could not go live with a notice, judged on the
     * given day, or null. Only a published body change needs the notice.
     *
     * @param  array{title: string, meta_description: string|null, status: string, body: string}  $proposed
     */
    public function noticeProblem(ContentPage $page, array $proposed, Carbon $on): ?string
    {
        $body = $proposed['body'];
        $goesLive = $proposed['status'] === ContentPage::STATUS_PUBLISHED
            && ($body !== (string) $page->body || ! $page->isPublished());

        if (! $goesLive) {
            return null;
        }

        $label = $this->notices->label($body);
        $liveLabel = $page->isPublished() ? $this->notices->label((string) $page->body) : null;

        if ($label !== null && $label === $liveLabel && $body !== (string) $page->body) {
            return 'The version line at the bottom still says '.$label.'. Change it to today\'s date (or tomorrow\'s if a change was already published today) and add a Small heading reading exactly the same, with the change under it.';
        }

        // A version dated before the day it is published would apply the
        // change to the past.
        if ($label !== null && substr($label, 1) < $on->toDateString()) {
            return 'The version line says '.$label.', which is before today ('.$on->toDateString().'). Use today\'s date or a later one, in the version line and in the Small heading that matches it.';
        }

        return $this->notices->problemWithText($body);
    }

    /**
     * @param  array{title: string, meta_description: string|null, status: string, body: string}  $proposed
     * @return list<string>
     */
    public function changedFields(ContentPage $page, array $proposed): array
    {
        $changed = [];

        foreach (self::GOVERNED as $field) {
            if ((string) $proposed[$field] !== (string) $page->getAttribute($field)) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /** @return array{title: string, meta_description: string|null, status: string, body: string} */
    public function proposed(ContentPageRevision $revision): array
    {
        return [
            'title' => $revision->title,
            'meta_description' => $revision->meta_description,
            'status' => $revision->status,
            'body' => (string) $revision->body,
        ];
    }

    /** The page as the author saw it: every field a revision governs. */
    private function digest(ContentPage $page): string
    {
        return (string) AuditDigests::of(AuditDigests::snapshot($page, self::GOVERNED));
    }

    private function close(ContentPageRevision $revision, int $actorId, string $state, ?string $note, string $action): void
    {
        DB::transaction(function () use ($revision, $actorId, $state, $note, $action): void {
            $locked = ContentPageRevision::query()->whereKey($revision->id)->lockForUpdate()->firstOrFail();
            $this->assertPending($locked);

            $locked->update([
                'state' => $state,
                'decided_by' => $actorId,
                'decided_at' => Carbon::now(),
                'decision_note' => $note,
            ]);

            // Read, never saved: closing a proposal leaves the page alone.
            $this->audit($action, $actorId, $locked->page()->firstOrFail(), $locked);
        });
    }

    private function assertPending(ContentPageRevision $revision): void
    {
        if (! $revision->isPending()) {
            throw new PlanChangeRefused('That change has already been '.$revision->state.'.');
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @param  array<string, mixed>|null  $before
     */
    private function audit(string $action, int $actorId, ContentPage $page, ContentPageRevision $revision, array $extra = [], ?array $before = null): void
    {
        AuditLog::create([
            'actor_id' => $actorId,
            'action' => $action,
            'subject_type' => 'content_page',
            'subject_id' => $page->id,
            'before_hash' => AuditDigests::of($before ?? $page),
            'after_hash' => AuditDigests::of($page),
            'details' => array_merge([
                'slug' => $page->slug,
                'revision_id' => $revision->id,
                'proposed_hash' => bin2hex(hash('sha256', (string) $revision->body, true)),
                'note' => $revision->note,
            ], $extra),
        ]);
    }
}
