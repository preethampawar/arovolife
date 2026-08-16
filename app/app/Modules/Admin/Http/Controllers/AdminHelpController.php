<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * In-admin "Help & Reference" — renders curated markdown reference docs
 * (resources/help/) so the operations team can read them without leaving the
 * panel. The docs live inside the app so they're always present wherever it runs.
 *
 * Only the fixed, allow-listed entries below are ever read; no request input
 * reaches the filesystem, so there is no path-traversal surface.
 */
final class AdminHelpController extends Controller
{
    /**
     * Allow-listed reference docs. slug => metadata; `file` is the markdown
     * filename under resources/help/.
     *
     * @var array<string, array{title: string, description: string, file: string}>
     */
    private const DOCS = [
        'glossary' => [
            'title' => 'Glossary',
            'description' => 'The vocabulary used across the platform and our policies — ADN, BV, Genos, sponsor vs placement, cooling-off, KYC and more.',
            'file' => 'glossary.md',
        ],
        'compliance-dos-and-donts' => [
            'title' => "Compliance Do's & Don'ts",
            'description' => 'The eight hard rules in plain language, plus the do/don\'t list every admin must follow under DSR 2021, the Direct Seller Agreement and DPDP.',
            'file' => 'compliance-dos-and-donts.md',
        ],
        'kyc-review-guide' => [
            'title' => 'KYC Review Guide',
            'description' => 'How to review identity documents — the required set, approve / reject / flag-for-reupload, and handling personal data safely.',
            'file' => 'kyc-review-guide.md',
        ],
        'admin-actions' => [
            'title' => 'Admin Actions & Separation of Duties',
            'description' => 'What each account action does (Block / Unblock / Terminate / Deactivate), what is reversible, and who should do it.',
            'file' => 'admin-actions.md',
        ],
        'staff-access-recovery' => [
            'title' => 'Staff Access & Password Recovery',
            'description' => "What to do when you can't sign in — the self-service reset, why a password can never be looked up for you, and how to reset a distributor's password.",
            'file' => 'staff-access-recovery.md',
        ],
        'cooling-off' => [
            'title' => 'Cooling-off & Cancellation',
            'description' => 'The statutory 30-day cancellation window, the one-click refund flow, reminders, and edge cases.',
            'file' => 'cooling-off.md',
        ],
        'dormancy-termination' => [
            'title' => 'Dormancy & Termination (§21)',
            'description' => 'The twelve-month no-sale rule, the seven-day notice, the master switch that ships off, and the re-registration wait afterwards.',
            'file' => 'dormancy-termination.md',
        ],
        'grievance-handling' => [
            'title' => 'Grievance Handling',
            'description' => 'How to work the complaint queue: the published SLA clocks, the four-step escalation ladder, third-party extensions, and the monthly compliance report.',
            'file' => 'grievance-handling.md',
        ],
        'status-reference' => [
            'title' => 'Status Reference',
            'description' => 'Every status / lifecycle value across the platform — accounts, KYC, genealogy, catalog, orders, payments, returns and more — with plain-English explanations.',
            'file' => 'status-reference.md',
        ],
    ];

    public function index(): View
    {
        return view('admin.help.index', ['docs' => self::DOCS]);
    }

    public function show(string $slug): View
    {
        $doc = self::DOCS[$slug] ?? null;
        if ($doc === null) {
            throw new NotFoundHttpException;
        }

        $path = resource_path('help/'.$doc['file']);
        if (! is_file($path)) {
            throw new NotFoundHttpException;
        }

        // Trusted, repo-controlled markdown — strip any raw HTML defensively.
        $html = Str::markdown((string) file_get_contents($path), [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return view('admin.help.show', [
            'slug' => $slug,
            'doc' => $doc,
            'docs' => self::DOCS,
            'html' => $html,
        ]);
    }
}
