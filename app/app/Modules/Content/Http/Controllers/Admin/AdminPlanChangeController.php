<?php

declare(strict_types=1);

namespace App\Modules\Content\Http\Controllers\Admin;

use App\Modules\Consent\Services\PlanChangeNotices;
use App\Modules\Content\Exceptions\PlanChangeRefused;
use App\Modules\Content\Models\ContentPageRevision;
use App\Modules\Content\Services\PlanChangeApprovals;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Reviewing a proposed Compensation Plan change, and deciding it (R-125).
 *
 * Anyone who may edit content can read a proposal and withdraw their own.
 * Approving and rejecting are `role:developer` routes, a gate the
 * Gate::before super-staff bypass cannot open: the client runs one admin
 * login, so the second person on every plan change is the developer.
 */
final class AdminPlanChangeController extends Controller
{
    public function __construct(private readonly PlanChangeApprovals $approvals) {}

    public function show(ContentPageRevision $revision, PlanChangeNotices $notices, Request $request): View
    {
        $revision->load(['page', 'requester', 'decider']);
        $page = $revision->page;
        $proposed = $this->approvals->proposed($revision);
        $entries = $notices->entries($proposed['body']);

        return view('admin.content.plan-change', [
            'revision' => $revision,
            'page' => $page,
            'changed' => $this->approvals->changedFields($page, $proposed),
            'diff' => $this->diff((string) $page->body, $proposed['body']),
            'newestEntry' => $entries[0] ?? null,
            'problem' => $revision->isPending() ? $this->approvals->noticeProblem($page, $proposed, now()) : null,
            'noticeOn' => $notices->enabled(),
            'canDecide' => (bool) $request->user()?->hasRole('developer'),
            'isRequester' => $revision->requested_by === (int) $request->user()?->id,
        ]);
    }

    public function approve(ContentPageRevision $revision, Request $request): RedirectResponse
    {
        $data = $request->validate(['decision_note' => ['nullable', 'string', 'max:1000']]);

        try {
            $page = $this->approvals->approve($revision, (int) $request->user()->id, $data['decision_note'] ?? null);
        } catch (PlanChangeRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()
            ->route('admin.content.edit', $page)
            ->with('status', 'Approved. The change is live, and distributors are shown it at their next sign-in.');
    }

    public function reject(ContentPageRevision $revision, Request $request): RedirectResponse
    {
        $data = $request->validate(
            ['decision_note' => ['required', 'string', 'max:1000']],
            ['decision_note.required' => 'Say why, so the person who proposed it can fix it.'],
        );

        try {
            $this->approvals->reject($revision, (int) $request->user()->id, $data['decision_note']);
        } catch (PlanChangeRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return back()->with('status', 'Rejected. The live page has not changed.');
    }

    public function withdraw(ContentPageRevision $revision, Request $request): RedirectResponse
    {
        try {
            $this->approvals->withdraw($revision, (int) $request->user()->id);
        } catch (PlanChangeRefused $refused) {
            return back()->with('error', $refused->getMessage());
        }

        return redirect()
            ->route('admin.content.edit', $revision->content_page_id)
            ->with('status', 'Withdrawn. The live page has not changed.');
    }

    /**
     * The changed paragraphs, headings, list items and table rows, as plain
     * text: what an approver needs to read, without the markup.
     *
     * @return list<array{type: string, text: string}>
     */
    private function diff(string $before, string $after): array
    {
        $old = $this->blocks($before);
        $new = $this->blocks($after);
        $n = count($old);
        $m = count($new);

        // Longest common subsequence over blocks; a plan page is a few
        // hundred blocks, so the table stays small.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $old[$i] === $new[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $rows = [];
        $i = 0;
        $j = 0;

        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $old[$i] === $new[$j]) {
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $rows[] = ['type' => 'added', 'text' => $new[$j++]];
            } else {
                $rows[] = ['type' => 'removed', 'text' => $old[$i++]];
            }
        }

        return $rows;
    }

    /** @return list<string> */
    private function blocks(string $html): array
    {
        $html = preg_replace('#</t[dh]>#i', ' | ', $html) ?? $html;
        $parts = preg_split('#</(?:p|h[1-6]|li|tr|blockquote|pre)>|<hr\s*/?>#i', $html) ?: [];
        $blocks = [];

        foreach ($parts as $part) {
            $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($part), ENT_QUOTES | ENT_HTML5)));
            $text = rtrim($text, ' |');

            if ($text !== '') {
                $blocks[] = $text;
            }
        }

        return $blocks;
    }
}
