<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use App\Modules\Compliance\Models\AuditLog;
use App\Modules\Public\Models\ContactInquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Admin-side inbox for the public contact form (`POST /contact-us`).
 *
 * Submissions are stored in `contact_inquiries` (App\Modules\Public\Models).
 * This controller adds list / detail / mark-handled actions plus an audit
 * log entry every time an admin reads or actions a row — required because
 * those rows contain DPDP-protected personal data.
 */
final class AdminContactController extends Controller
{
    public function index(Request $request): View
    {
        $filter = (string) $request->query('filter', 'unhandled');
        if (! in_array($filter, ['unhandled', 'handled', 'all'], true)) {
            $filter = 'unhandled';
        }

        $query = ContactInquiry::query()->orderByDesc('created_at');

        if ($filter === 'unhandled') {
            $query->whereNull('handled_at');
        } elseif ($filter === 'handled') {
            $query->whereNotNull('handled_at');
        }

        $purpose = (string) $request->query('purpose', '');
        if (in_array($purpose, ['become_distributor', 'support', 'compliance', 'partnership', 'other'], true)) {
            $query->where('purpose', $purpose);
        }

        $search = trim((string) $request->query('q', ''));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function ($q) use ($like): void {
                $q->where('email', 'like', $like)
                    ->orWhere('phone_e164', 'like', $like)
                    ->orWhere('name', 'like', $like);
            });
        }

        $inquiries = $query->paginate(25)->withQueryString();

        // Counts for the filter chips — single round trip via two GROUP BY queries
        $unhandledCount = ContactInquiry::query()->whereNull('handled_at')->count();
        $handledCount   = ContactInquiry::query()->whereNotNull('handled_at')->count();

        return view('admin.contact-inquiries.index', [
            'inquiries' => $inquiries,
            'filter' => $filter,
            'purpose' => $purpose,
            'search' => $search,
            'unhandledCount' => $unhandledCount,
            'handledCount' => $handledCount,
            'totalCount' => $unhandledCount + $handledCount,
        ]);
    }

    public function show(int $id): View
    {
        $inquiry = ContactInquiry::query()->findOrFail($id);

        // Audit every admin view of a contact inquiry. Per CLAUDE.md any
        // admin access to PII must be logged.
        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'contact_inquiry.viewed',
            'subject_type' => 'contact_inquiry',
            'subject_id' => $inquiry->id,
            'details' => [
                'email' => $inquiry->email,
                'purpose' => $inquiry->purpose,
            ],
        ]);

        return view('admin.contact-inquiries.show', [
            'inquiry' => $inquiry,
        ]);
    }

    public function markHandled(int $id): RedirectResponse
    {
        $inquiry = ContactInquiry::query()->findOrFail($id);

        if ($inquiry->handled_at !== null) {
            return redirect()->route('admin.contact-inquiries.show', $inquiry->id)
                ->with('status', 'Already marked as handled.');
        }

        $inquiry->update([
            'handled_at' => now(),
            'handled_by' => Auth::id(),
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'contact_inquiry.handled',
            'subject_type' => 'contact_inquiry',
            'subject_id' => $inquiry->id,
            'details' => [
                'email' => $inquiry->email,
                'purpose' => $inquiry->purpose,
            ],
        ]);

        return redirect()->route('admin.contact-inquiries.show', $inquiry->id)
            ->with('status', 'Marked as handled.');
    }

    public function markUnhandled(int $id): RedirectResponse
    {
        $inquiry = ContactInquiry::query()->findOrFail($id);

        $inquiry->update([
            'handled_at' => null,
            'handled_by' => null,
        ]);

        AuditLog::create([
            'actor_id' => Auth::id(),
            'action' => 'contact_inquiry.reopened',
            'subject_type' => 'contact_inquiry',
            'subject_id' => $inquiry->id,
            'details' => [
                'email' => $inquiry->email,
                'purpose' => $inquiry->purpose,
            ],
        ]);

        return redirect()->route('admin.contact-inquiries.show', $inquiry->id)
            ->with('status', 'Reopened.');
    }
}
