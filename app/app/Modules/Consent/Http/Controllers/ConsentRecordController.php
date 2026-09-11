<?php

declare(strict_types=1);

namespace App\Modules\Consent\Http\Controllers;

use App\Modules\Consent\Models\Consent;
use App\Modules\Consent\Services\ConsentDocuments;
use App\Modules\Consent\Services\WithdrawConsent;
use App\Modules\Content\Models\ContentPage;
use App\Modules\Identity\Models\Distributor;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The distributor's own record of what they agreed to (F72).
 *
 * DPDP §5 and §6 both assume the data principal can see their consent, not
 * just give it: §5 because the notice has to stay available, §6 because a
 * right to withdraw is hard to exercise against an agreement you can no
 * longer find. The platform held the rows and showed them nowhere — the
 * profile linked the Privacy Policy and nothing else, so a distributor could
 * not tell which version of the agreement they were on, when they accepted
 * it, or that the acceptance had been recorded at all.
 *
 * Their own rows only, never anyone else's, and read-only: this is a record,
 * and a record somebody can edit is not one.
 */
final class ConsentRecordController extends Controller
{
    public function __construct(
        private readonly ConsentDocuments $documents,
        private readonly WithdrawConsent $withdraw,
    ) {}

    public function show(Request $request): View
    {
        $distributor = $this->distributor($request);

        $consents = Consent::query()
            ->where('distributor_id', $distributor->id)
            // Newest acceptance first: the version in force is the one the
            // reader came for, and older ones are history below it.
            ->orderByDesc('accepted_at')
            ->orderBy('document_type')
            ->get();

        return view('consent.record', [
            'distributor' => $distributor,
            'consents' => $consents,
            'titles' => $this->titles(),
            'urls' => $this->urls(),
            'hasLiveConsent' => $this->withdraw->hasLiveConsent($distributor),
        ]);
    }

    /**
     * Document titles taken from the published pages, so the record names each
     * document the way the document names itself.
     *
     * @return array<string, string>
     */
    private function titles(): array
    {
        $fallbacks = [
            'tnc' => 'Direct Seller Agreement & Terms of Service',
            'ethics' => 'Code of Ethics',
            'plan' => 'Compensation Plan Disclosure',
            'privacy' => 'Privacy Policy',
        ];

        $bySlug = ContentPage::query()
            ->whereIn('slug', ['terms', 'ethics', 'compensation', 'privacy'])
            ->pluck('title', 'slug');

        $titles = [];

        foreach (ConsentDocuments::types() as $type) {
            $slug = basename($this->documents->url($type));

            $titles[$type] = (string) ($bySlug[$slug] ?? $fallbacks[$type] ?? ucfirst($type));
        }

        return $titles;
    }

    /** @return array<string, string> */
    private function urls(): array
    {
        $urls = [];

        foreach (ConsentDocuments::types() as $type) {
            $urls[$type] = $this->documents->url($type);
        }

        return $urls;
    }

    private function distributor(Request $request): Distributor
    {
        $distributor = $request->user()?->distributor;

        if ($distributor === null) {
            throw new NotFoundHttpException;
        }

        return $distributor;
    }
}
