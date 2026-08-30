<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Http\Rules\ValidUploadedDocumentBytes;
use App\Modules\Identity\Models\Distributor;
use App\Modules\Identity\Models\DistributorRequest;
use App\Modules\Identity\Services\DistributorRequestService;
use App\Modules\Shared\Features\DistributorRequestsFeature;
use App\Modules\Shared\Http\Rules\ScannedForMalware;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use InvalidArgumentException;
use Laravel\Pennant\Feature;

/**
 * Distributor side of "Distributor requests": file and track a request
 * about their own record. Filing is free and changes nothing until staff
 * approve it.
 */
final class DistributorRequestController extends Controller
{
    public function __construct(private readonly DistributorRequestService $requests) {}

    public function index(Request $request): View
    {
        $distributor = $this->distributor($request);

        $items = DistributorRequest::query()
            ->where('distributor_id', $distributor->id)
            ->orderByDesc('id')
            ->get();

        return view('my.requests.index', [
            'requests' => $items,
            'types' => DistributorRequest::TYPES,
        ]);
    }

    public function create(Request $request): View
    {
        $distributor = $this->distributor($request);
        $type = (string) $request->query('type', '');
        if (! array_key_exists($type, DistributorRequest::TYPES)) {
            $type = DistributorRequest::TYPE_NAME_CORRECTION;
        }

        return view('my.requests.create', [
            'type' => $type,
            'types' => DistributorRequest::TYPES,
            'relationships' => DistributorRequest::RELATIONSHIPS,
            'current' => [
                'name' => $distributor->user->full_name ?? '—',
                'adn' => $distributor->adn,
                'email' => $distributor->user->email ?? '—',
                'date_of_birth' => $distributor->user->date_of_birth ? Carbon::parse($distributor->user->date_of_birth)->format('d M Y') : '—',
                'in_cooling_off' => $distributor->cooling_off_end_at !== null && $distributor->cooling_off_end_at->isFuture(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $distributor = $this->distributor($request);

        $type = (string) $request->input('type', '');
        abort_unless(array_key_exists($type, DistributorRequest::TYPES), 422);

        $data = $request->validate($this->rules($type), $this->messages($type));

        try {
            $created = $this->requests->submit(
                $distributor,
                $type,
                $this->details($type, $data),
                (string) $data['reason'],
                $this->files($request, $type),
                $request->ip(),
            );
        } catch (InvalidArgumentException $e) {
            return back()->withInput()->withErrors(['request' => $e->getMessage()]);
        }

        return redirect()->route('my.requests.index')
            ->with('status', 'Your request '.$created->request_no.' has been submitted. We will email you the outcome.');
    }

    public function show(Request $request, DistributorRequest $distributorRequest): View
    {
        $distributor = $this->distributor($request);
        abort_unless($distributorRequest->distributor_id === $distributor->id, 404);

        $distributorRequest->load('documents');

        return view('my.requests.show', ['item' => $distributorRequest]);
    }

    // ── Internals ────────────────────────────────────────────────────────────

    private function distributor(Request $request): Distributor
    {
        // Zero-trace gating: an unlaunched flow 404s rather than 403s.
        abort_unless(Feature::for(null)->active(DistributorRequestsFeature::class), 404);

        $distributor = $request->user()?->distributor;
        abort_unless($distributor !== null, 403);

        return $distributor;
    }

    /** @return array<string, mixed> */
    private function rules(string $type): array
    {
        $rules = [
            'type' => ['required', Rule::in(array_keys(DistributorRequest::TYPES))],
            'reason' => ['required', 'string', 'max:2000'],
        ];

        $rules += match ($type) {
            DistributorRequest::TYPE_NAME_CORRECTION, DistributorRequest::TYPE_NAME_CHANGE => [
                'requested_full_name' => ['required', 'string', 'min:2', 'max:150', 'regex:/^[\pL\pM .\'-]+$/u'],
            ],
            DistributorRequest::TYPE_DOB_CORRECTION => [
                'requested_date_of_birth' => ['required', 'date_format:Y-m-d', 'before:-18 years'],
            ],
            DistributorRequest::TYPE_MEMBERSHIP_TRANSFER => [
                'transferee_name' => ['required', 'string', 'min:2', 'max:150'],
                'relationship' => ['required', Rule::in(array_keys(DistributorRequest::RELATIONSHIPS))],
                'transferee_mobile' => ['required', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
                'transferee_email' => ['nullable', 'email', 'max:255'],
            ],
            DistributorRequest::TYPE_ID_CANCELLATION => [
                'acknowledged' => ['accepted'],
            ],
            default => [],
        };

        $fileRule = [
            'file', 'max:5120', 'mimetypes:image/jpeg,image/png,application/pdf',
            new ValidUploadedDocumentBytes, new ScannedForMalware,
        ];
        foreach (DistributorRequest::TYPES[$type]['documents'] as $docType => $meta) {
            $rules["documents.{$docType}"] = [$meta['required'] ? 'required' : 'nullable', 'array', 'max:1'];
            $rules["documents.{$docType}.*"] = $fileRule;
        }

        return $rules;
    }

    /** @return array<string, string> */
    private function messages(string $type): array
    {
        $messages = [
            'requested_full_name.regex' => 'The name may contain letters, spaces, dots, apostrophes and hyphens only.',
            'requested_date_of_birth.before' => 'A distributor must be at least 18 years old.',
            'acknowledged.accepted' => 'Please confirm you understand what cancellation means.',
            'documents.*.*.max' => 'Each file must not be larger than 5 MB.',
            'documents.*.*.mimetypes' => 'Allowed formats: JPG, PNG or PDF.',
        ];
        foreach (DistributorRequest::TYPES[$type]['documents'] as $docType => $meta) {
            $messages["documents.{$docType}.required"] = 'Please upload: '.lcfirst($meta['label']).'.';
        }

        return $messages;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function details(string $type, array $data): array
    {
        return match ($type) {
            DistributorRequest::TYPE_NAME_CORRECTION, DistributorRequest::TYPE_NAME_CHANGE => [
                'requested_full_name' => trim(preg_replace('/\s+/', ' ', (string) $data['requested_full_name']) ?? ''),
            ],
            DistributorRequest::TYPE_DOB_CORRECTION => [
                'requested_date_of_birth' => $data['requested_date_of_birth'],
            ],
            DistributorRequest::TYPE_MEMBERSHIP_TRANSFER => [
                'transferee_name' => trim((string) $data['transferee_name']),
                'relationship' => $data['relationship'],
                'transferee_mobile' => $data['transferee_mobile'],
                'transferee_email' => $data['transferee_email'] ?? null,
            ],
            default => [],
        };
    }

    /** @return array<string, list<UploadedFile>> */
    private function files(Request $request, string $type): array
    {
        $out = [];
        foreach (array_keys(DistributorRequest::TYPES[$type]['documents']) as $docType) {
            $files = $request->file("documents.{$docType}");
            if ($files instanceof UploadedFile) {
                $files = [$files];
            }
            $files = array_values(array_filter(is_array($files) ? $files : [], fn ($f) => $f instanceof UploadedFile && $f->isValid()));
            if ($files !== []) {
                $out[$docType] = $files;
            }
        }

        return $out;
    }
}
