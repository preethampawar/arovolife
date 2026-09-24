<?php

declare(strict_types=1);

namespace App\Modules\Compensation\Http\Controllers;

use App\Modules\Compensation\Models\AreteCenter;
use App\Modules\Compensation\Models\AreteCenterApplication;
use App\Modules\Compensation\Models\AreteCenterApplicationDocument;
use App\Modules\Compensation\Models\AreteCenterDeclaration;
use App\Modules\Compensation\Services\AreteCenterApplicationService;
use App\Modules\Compensation\Services\AreteCenterDeclarationService;
use App\Modules\Compensation\Services\AreteCenterRegistrySettings;
use App\Modules\Compensation\Services\RankStatusService;
use App\Modules\Compensation\Support\AreteCenterDeclarations;
use App\Modules\Fulfilment\Support\FulfilmentSettings;
use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Shared\Crypto\PiiCrypter;
use App\Modules\Shared\Features\AreteCenterApplicationsFeature;
use App\Modules\Shared\Http\Rules\ScannedForMalware;
use App\Modules\Shared\Support\IndianStates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Distributor side of the Arete Development Centre application
 * (spec §A). Applying is free and shows no income data (hard rules 1, 3).
 */
final class DistributorAreteCenterApplicationController extends Controller
{
    public function __construct(
        private readonly AreteCenterApplicationService $applications,
        private readonly AreteCenterRegistrySettings $settings,
        private readonly RankStatusService $rankStatus,
    ) {}

    public function status(Request $request, FulfilmentSettings $fulfilment): View
    {
        $distributor = $this->distributor($request);

        $application = AreteCenterApplication::query()
            ->where('distributor_id', $distributor->id)
            ->with(['documents', 'center'])
            ->latest('id')
            ->first();

        $ownedCenters = AreteCenter::query()
            ->where('assigned_distributor_id', $distributor->id)
            ->orderBy('name')
            ->get();

        // A centre cannot receive a parcel until its owner has accepted the
        // declarations at the version in force, so the owner has to be able to
        // see what is outstanding and accept it. Before v3 this never came up:
        // declarations were captured once on the application form and the
        // version had never moved.
        $outstandingDeclarations = $ownedCenters
            ->mapWithKeys(fn (AreteCenter $centre): array => [
                $centre->id => AreteCenterDeclaration::outstandingFor($centre->id),
            ])
            ->filter(fn (array $keys): bool => $keys !== [])
            ->all();

        return view('my.arete-centre.status', [
            'application' => $application,
            'ownedCenters' => $ownedCenters,
            'outstandingDeclarations' => $outstandingDeclarations,
            'declarationTexts' => AreteCenterDeclarations::all(),
            'declarationVersion' => AreteCenterDeclarations::VERSION,
            // The declaration binds the owner to "the period arovolife
            // publishes to me in writing". This is where it is published.
            'maxDwellDays' => $fulfilment->maxDwellDays(),
            // The consignments page exists only while collection is on (R-97).
            'collectionEnabled' => AreteCenter::collectionEnabled(),
        ]);
    }

    /**
     * Accept the outstanding declarations for a centre this distributor owns.
     *
     * The ownership check is in the service as well as here: this is the
     * signature the dispatch gate reads, and a signature that the wrong person
     * can produce is not evidence of anything.
     */
    public function acceptDeclarations(
        Request $request,
        AreteCenter $centre,
        AreteCenterDeclarationService $declarations,
    ): RedirectResponse {
        $distributor = $this->distributor($request);

        abort_unless($centre->assigned_distributor_id === $distributor->id, 403);

        // A signature given by someone wearing the signatory's session is not
        // a signature. Impersonation is a full session swap with no read-only
        // mode, so without this a super-staff admin could produce a row that
        // reads `signed_by = owner` with the distributor's own user id on it —
        // indistinguishable from the real thing, and exactly the fabrication
        // the two entry points in AreteCenterDeclarationService exist to stop.
        if ($request->session()->has('impersonator_id')) {
            abort(403, 'Declarations cannot be accepted while impersonating. The centre owner must accept them themselves.');
        }

        $validated = $request->validate([
            'declarations' => ['required', 'array'],
            'declarations.*' => ['string', Rule::in(AreteCenterDeclarations::keys())],
        ]);

        try {
            $declarations->acceptByOwner(
                $centre,
                $distributor,
                array_values(array_unique($validated['declarations'])),
                $request->user()?->id,
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['declarations' => $e->getMessage()]);
        }

        return redirect()->route('my.adc.status')
            ->with('success', 'Thank you. Your centre declarations are up to date.');
    }

    public function create(Request $request): View|RedirectResponse
    {
        $distributor = $this->distributor($request);

        if (AreteCenterApplication::query()->where('distributor_id', $distributor->id)->open()->exists()) {
            return redirect()->route('my.adc.status');
        }

        return view('my.arete-centre.apply', $this->formData($distributor, null));
    }

    public function store(Request $request): RedirectResponse
    {
        $distributor = $this->distributor($request);
        $data = $this->validatePayload($request, requireDocuments: true);

        try {
            $this->applications->submit(
                $distributor,
                $data,
                $this->files($request),
                $data['declarations'],
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['application' => $e->getMessage()]);
        }

        return redirect()->route('my.adc.status')
            ->with('success', 'Your application has been submitted. We will review it and email you the outcome.');
    }

    public function edit(Request $request): View|RedirectResponse
    {
        $distributor = $this->distributor($request);
        $application = $this->editableApplication($distributor);

        if ($application === null) {
            return redirect()->route('my.adc.status');
        }

        return view('my.arete-centre.apply', $this->formData($distributor, $application));
    }

    public function update(Request $request): RedirectResponse
    {
        $distributor = $this->distributor($request);
        $application = $this->editableApplication($distributor);
        abort_unless($application !== null, 404);

        $data = $this->validatePayload($request, requireDocuments: false);

        try {
            $this->applications->resubmit(
                $application,
                $data,
                $this->files($request),
                $data['declarations'],
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['application' => $e->getMessage()]);
        }

        return redirect()->route('my.adc.status')
            ->with('success', 'Your application has been resubmitted for review.');
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function distributor(Request $request): Distributor
    {
        // Zero-trace gating: an unlaunched flow 404s rather than 403s.
        abort_unless(Feature::for(null)->active(AreteCenterApplicationsFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return $distributor;
    }

    private function editableApplication(Distributor $distributor): ?AreteCenterApplication
    {
        return AreteCenterApplication::query()
            ->where('distributor_id', $distributor->id)
            ->where('status', AreteCenterApplication::STATUS_NEEDS_CHANGES)
            ->with('documents')
            ->latest('id')
            ->first();
    }

    /** @return array<string, mixed> */
    private function formData(Distributor $distributor, ?AreteCenterApplication $application): array
    {
        $user = $distributor->user;
        $sponsor = $distributor->sponsor_id === $distributor->id
            ? null
            : Distributor::query()->with('user')->find($distributor->sponsor_id);
        $rankLabels = $this->rankStatus->labelsFor((int) $distributor->id);
        $sponsorRank = $sponsor === null ? null : ($this->rankStatus->labelsFor((int) $sponsor->id)['current'] ?? 'No rank yet');

        return [
            'application' => $application,
            'applicant' => [
                'name' => $user->full_name ?? '—',
                'adn' => $distributor->adn,
                'joined_on' => $distributor->effective_date?->format('d M Y') ?? '—',
                'rank' => $rankLabels['current'] ?? 'No rank yet',
                'address' => $user->address ?? '—',
                'mobile' => $user->phone_e164 ?? '—',
                'email' => $user->email ?? '—',
                'sponsor' => $sponsor === null ? '—' : trim(($sponsor->user->full_name ?? '').' ('.$sponsor->adn.')'),
                'sponsor_joined_on' => $sponsor?->effective_date?->format('d M Y') ?? '—',
                'sponsor_rank' => $sponsorRank ?? '—',
            ],
            // Masked by default with a reveal toggle. The applicant's own
            // verified identity, shown read-only — never re-entered (hard
            // rule 8). `full` is null where the platform no longer holds the
            // number (PAN is purged to its last 4 after KYC verification).
            'identity' => $this->identityBlock($distributor),
            'minSqft' => $this->settings->minPremisesSqft(),
            'maxPhotoKb' => $this->settings->maxPhotoKb(),
            'states' => IndianStates::all(),
            'propertyTypes' => AreteCenter::PROPERTY_TYPES,
            'weeklyOffOptions' => AreteCenter::WEEKLY_OFF_OPTIONS,
            'documentTypes' => AreteCenterApplicationDocument::TYPES,
            'declarations' => AreteCenterDeclarations::all(),
        ];
    }

    /**
     * @return array<string, array{masked: string, full: string|null}>
     */
    private function identityBlock(Distributor $distributor): array
    {
        // PiiCrypter, not Crypt: the bank column is written with the dedicated
        // PII key (ADR-0008). Reading it through the APP_KEY encrypter happens
        // to work only where the two keys coincide — on an environment with a
        // real PII key it throws, and the bare catch this replaces turned that
        // into a permanently blank account number rather than an error anyone
        // saw. tryDecryptString still degrades, but it says so in the log.
        $bankFull = filled($distributor->bank_account_enc)
            ? PiiCrypter::tryDecryptString((string) $distributor->bank_account_enc, [
                'model' => $distributor::class,
                'model_id' => $distributor->getKey(),
                'column' => 'bank_account_enc',
            ])
            : null;
        $bankMasked = $bankFull === null
            ? '—'
            : str_repeat('X', max(0, strlen($bankFull) - 4)).substr($bankFull, -4);

        return [
            'pan' => ['masked' => $distributor->pan_masked ?? '—', 'full' => $distributor->pan_encrypted],
            'bank_account' => ['masked' => $bankMasked, 'full' => $bankFull],
            'bank_ifsc' => ['masked' => filled($distributor->bank_ifsc) ? $distributor->bank_ifsc : '—', 'full' => null],
        ];
    }

    /** @return array<string, mixed> */
    private function validatePayload(Request $request, bool $requireDocuments): array
    {
        $minSqft = $this->settings->minPremisesSqft();
        $maxPhotoKb = $this->settings->maxPhotoKb();

        $rules = [
            'centre_name' => ['required', 'string', 'max:200'],
            'contact_person' => ['nullable', 'string', 'max:150'],
            'alternate_contact_number' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'applicant_alternate_mobile' => ['nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'landmark' => ['required', 'string', 'max:150'],
            'pincode' => ['required', 'digits:6'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', Rule::in(IndianStates::all())],
            'property_type' => ['required', Rule::in(array_keys(AreteCenter::PROPERTY_TYPES))],
            'premises_sqft' => ['required', 'integer', 'min:'.$minSqft, 'max:100000'],
            'distance_to_nearest_adc_km' => ['required', 'numeric', 'min:0', 'max:9999.9'],
            'opening_time' => ['required', 'date_format:H:i'],
            'closing_time' => ['required', 'date_format:H:i', 'after:opening_time'],
            'weekly_off' => ['required', Rule::in(array_keys(AreteCenter::WEEKLY_OFF_OPTIONS))],
            'declarations' => ['required', 'array'],
            'declarations.*' => ['string', Rule::in(AreteCenterDeclarations::keys())],
        ];

        $documentRule = [
            'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf',
            new ValidUploadedDocumentBytes, new ScannedForMalware,
        ];
        $photoRule = [
            'file', 'max:'.$maxPhotoKb, 'mimetypes:image/jpeg,image/png',
            new ValidUploadedDocumentBytes(['jpeg', 'png']), new ScannedForMalware,
        ];
        foreach (AreteCenterApplicationDocument::TYPES as $type => $meta) {
            $required = $requireDocuments && $meta['required'];
            $rules["documents.{$type}"] = [$required ? 'required' : 'nullable', 'array', 'max:'.$meta['max']];
            $rules["documents.{$type}.*"] = $meta['image'] ? $photoRule : $documentRule;
        }

        $messages = [
            'premises_sqft.min' => "The premises must be at least {$minSqft} sq ft.",
            'closing_time.after' => 'Closing time must be after the opening time.',
            'declarations.required' => 'Please accept every declaration.',
            'documents.*.*.max' => 'Each file must not be larger than 5 MB.',
            'documents.*.*.mimetypes' => 'Allowed formats: JPG, PNG or PDF.',
            'documents.*.max' => 'Too many files for one document — see the limit next to the field.',
        ];
        foreach (AreteCenterApplicationDocument::TYPES as $type => $meta) {
            $messages["documents.{$type}.required"] = 'Please upload: '.lcfirst($meta['label']).'.';
            if ($meta['image']) {
                $messages["documents.{$type}.*.max"] = $meta['label']." must not be larger than {$maxPhotoKb} KB.";
                $messages["documents.{$type}.*.mimetypes"] = $meta['label'].' must be a JPG or PNG image.';
            }
        }

        return $request->validate($rules, $messages);
    }

    /** @return array<string, list<UploadedFile>> */
    private function files(Request $request): array
    {
        $out = [];
        foreach (array_keys(AreteCenterApplicationDocument::TYPES) as $type) {
            $files = $request->file("documents.{$type}");
            if ($files instanceof UploadedFile) {
                $files = [$files];
            }
            $out[$type] = array_values(array_filter(is_array($files) ? $files : [], fn ($f) => $f instanceof UploadedFile && $f->isValid()));
        }

        return $out;
    }
}
