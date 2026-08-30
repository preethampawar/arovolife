@extends('admin.layouts.admin')
@section('title', 'ADC Application #'.$application->id)
@section('heading', 'Application — '.$application->centre_name)

@section('content')
@php
    $badge = [
        'submitted' => 'bg-amber-100 text-amber-800', 'under_review' => 'bg-blue-100 text-blue-800',
        'needs_changes' => 'bg-orange-100 text-orange-800', 'approved' => 'bg-green-100 text-green-700',
        'rejected' => 'bg-red-100 text-red-700',
    ];
    $applicant = $application->distributor;
    $sponsor = $applicant->sponsor_id === $applicant->id ? null : $applicant->sponsor;
    $canDecide = auth()->user()?->can('compliance.discipline') ?? false;
    $decidable = in_array($application->status, ['submitted', 'under_review'], true);
    $ist = fn ($t) => $t?->timezone('Asia/Kolkata')->format('d M Y, H:i') ?? '—';
    $btn = 'px-4 py-2 rounded-lg text-sm font-medium transition-colors';
@endphp

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.compensation.adc-bonus.applications.index') }}" class="text-sm text-gray-600 hover:text-gray-700">← All applications</a>
    <span class="inline-flex px-2 py-0.5 rounded text-[10px] font-medium {{ $badge[$application->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $application->statusLabel() }}</span>
</div>

@if(session('success'))
<div class="rounded-lg border border-green-200 bg-green-50 p-3 mb-4 text-sm text-green-800">{{ session('success') }}</div>
@endif
@if($errors->any())
<div class="rounded-lg border border-red-200 bg-red-50 p-3 mb-4 text-sm text-red-700">
    <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
</div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Applicant</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-gray-500">Name</dt><dd>{{ $applicant->user->full_name ?? '—' }}</dd>
                <dt class="text-gray-500">ADN</dt><dd class="font-mono">{{ $applicant->adn }}</dd>
                <dt class="text-gray-500">Joined</dt><dd>{{ $applicant->effective_date?->format('d M Y') ?? '—' }}</dd>
                <dt class="text-gray-500">Mobile</dt><dd class="font-mono">{{ $applicant->user->phone_e164 ?? '—' }}</dd>
                <dt class="text-gray-500">Email</dt><dd>{{ $applicant->user->email ?? '—' }}</dd>
                <dt class="text-gray-500">Sponsor</dt><dd>{{ $sponsor ? ($sponsor->user->full_name ?? '').' ('.$sponsor->adn.')' : '—' }}</dd>
                <dt class="text-gray-500">Registered address</dt><dd>{{ $applicant->user->address ?? '—' }}</dd>
            </dl>
        </section>

        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Proposed centre and premises</h2>
            <dl class="grid grid-cols-2 gap-x-6 gap-y-2 text-sm">
                <dt class="text-gray-500">Centre name</dt><dd class="font-medium">{{ $application->centre_name }}</dd>
                <dt class="text-gray-500">Contact person</dt><dd>{{ $application->contact_person ?: ($applicant->user->full_name ?? '—') }}</dd>
                <dt class="text-gray-500">Alternate number</dt><dd class="font-mono">{{ $application->alternate_contact_number ?: '—' }}</dd>
                <dt class="text-gray-500">Address</dt><dd>{{ $application->address_line_1 }}{{ $application->address_line_2 ? ', '.$application->address_line_2 : '' }}</dd>
                <dt class="text-gray-500">Landmark</dt><dd>{{ $application->landmark }}</dd>
                <dt class="text-gray-500">City · State · Pincode</dt><dd>{{ $application->city }} · {{ $application->state }} · <span class="font-mono">{{ $application->pincode }}</span></dd>
                <dt class="text-gray-500">Property type</dt><dd>{{ \App\Modules\Compensation\Models\AreteCenter::PROPERTY_TYPES[$application->property_type] ?? $application->property_type }}</dd>
                <dt class="text-gray-500">Size</dt><dd>{{ \App\Modules\Shared\Support\IndianNumber::format($application->premises_sqft) }} sq ft</dd>
                <dt class="text-gray-500">Distance to nearest ADC</dt><dd>{{ $application->distance_to_nearest_adc_km }} km <span class="text-xs text-gray-500">(self-declared)</span></dd>
                <dt class="text-gray-500">Operating hours</dt><dd>{{ substr($application->opening_time, 0, 5) }} – {{ substr($application->closing_time, 0, 5) }}</dd>
                <dt class="text-gray-500">Weekly off</dt><dd>{{ \App\Modules\Compensation\Models\AreteCenter::WEEKLY_OFF_OPTIONS[$application->weekly_off] ?? $application->weekly_off }}</dd>
            </dl>
        </section>

        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Documents</h2>
            @if($application->documents->isEmpty())
                <p class="text-sm text-gray-600">No documents on file.</p>
            @else
            <ul class="divide-y divide-gray-100 text-sm">
                @foreach($application->documents as $doc)
                <li class="py-2 flex items-center justify-between gap-3">
                    <div>
                        <p class="font-medium text-gray-900">{{ $doc->typeLabel() }}</p>
                        <p class="text-xs text-gray-500">{{ $doc->original_name }} · {{ \App\Modules\Shared\Support\IndianNumber::format(intdiv($doc->size_bytes, 1024)) }} KB · uploaded {{ $ist($doc->created_at) }}</p>
                    </div>
                    <a href="{{ route('admin.compensation.adc-bonus.applications.document', [$application, $doc]) }}" target="_blank" rel="noopener" class="text-brand-700 hover:text-brand-800 font-medium whitespace-nowrap">Open ↗</a>
                </li>
                @endforeach
            </ul>
            @endif
        </section>

        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Declarations</h2>
            <ul class="space-y-2 text-sm">
                @foreach($declarationTexts as $key => $text)
                @php $accepted = $application->declarations->firstWhere('declaration_key', $key); @endphp
                <li class="flex items-start gap-2">
                    <span class="{{ $accepted ? 'text-green-600' : 'text-red-600' }}">{{ $accepted ? '✔' : '✘' }}</span>
                    <span>
                        {{ $text }}
                        @if($accepted)<span class="block text-xs text-gray-500">Accepted {{ $ist($accepted->accepted_at) }} · {{ $accepted->version }} · IP {{ $accepted->ip ?? '—' }}</span>@endif
                    </span>
                </li>
                @endforeach
            </ul>
        </section>
    </div>

    <div class="space-y-6">
        <section class="bg-white rounded-xl border border-gray-200 shadow-sm p-6">
            <h2 class="text-sm font-semibold text-gray-900 mb-3">Review</h2>
            <dl class="text-xs grid grid-cols-2 gap-y-1.5 text-gray-700 mb-4">
                <dt class="text-gray-500">Submitted</dt><dd>{{ $ist($application->submitted_at) }}</dd>
                <dt class="text-gray-500">Last reviewed</dt><dd>{{ $ist($application->reviewed_at) }}</dd>
                <dt class="text-gray-500">Reviewed by</dt><dd>{{ $application->reviewedBy?->full_name ?? '—' }}</dd>
                @if($application->center)
                <dt class="text-gray-500">Centre</dt><dd><a class="text-brand-700 hover:text-brand-800 font-medium" href="{{ route('admin.compensation.adc-bonus.centers.edit', $application->center) }}">{{ $application->center->name }}</a></dd>
                @endif
            </dl>
            @if($application->admin_notes)
            <div class="rounded-lg bg-gray-50 border border-gray-200 p-3 text-sm text-gray-700 mb-4">
                <p class="text-xs text-gray-500 mb-1">Admin note / reason shown to the applicant</p>
                {{ $application->admin_notes }}
            </div>
            @endif

            @if(! $canDecide)
                <p class="text-xs text-gray-500">Decisions need the <code>compliance.discipline</code> permission.</p>
            @elseif($decidable)
            <div class="space-y-3">
                @if($application->status === 'submitted')
                <form method="POST" action="{{ route('admin.compensation.adc-bonus.applications.review', [$application, 'review']) }}"
                      data-confirm="Mark this application as under review?" data-confirm-title="Start review" data-confirm-impact="Tells the queue someone is looking at it. No email is sent.">
                    @csrf
                    <button type="submit" class="{{ $btn }} w-full border border-gray-300 text-gray-700 hover:bg-gray-50">Mark under review</button>
                </form>
                @endif

                <form method="POST" action="{{ route('admin.compensation.adc-bonus.applications.review', [$application, 'approve']) }}"
                      data-confirm="Approve this application and create the centre?" data-confirm-title="Approve application"
                      data-confirm-impact="Creates “{{ $application->centre_name }}” as an active distributor centre at Phase 1 with {{ $applicant->adn }} as its owner, and emails the applicant.">
                    @csrf
                    <label class="block text-xs text-gray-600 mb-1">Note to applicant (optional)</label>
                    <input type="text" name="reason" maxlength="1000" class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2" placeholder="e.g. Approved subject to inspection in 30 days">
                    <button type="submit" class="{{ $btn }} w-full bg-green-600 text-white hover:bg-green-700">Approve and create centre</button>
                </form>

                <form method="POST" action="{{ route('admin.compensation.adc-bonus.applications.review', [$application, 'request-changes']) }}"
                      data-confirm="Send this application back for changes?" data-confirm-title="Request changes" data-confirm-impact="The applicant is emailed your reason and can edit and resubmit.">
                    @csrf
                    <label class="block text-xs text-gray-600 mb-1">What needs to change <span class="text-red-500">*</span></label>
                    <textarea name="reason" rows="2" maxlength="1000" required class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2"></textarea>
                    <button type="submit" class="{{ $btn }} w-full bg-orange-500 text-white hover:bg-orange-600">Request changes</button>
                </form>

                <form method="POST" action="{{ route('admin.compensation.adc-bonus.applications.review', [$application, 'reject']) }}"
                      data-confirm="Reject this application?" data-confirm-title="Reject application" data-confirm-impact="The applicant is emailed your reason. They may apply again later.">
                    @csrf
                    <label class="block text-xs text-gray-600 mb-1">Reason <span class="text-red-500">*</span></label>
                    <textarea name="reason" rows="2" maxlength="1000" required class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2"></textarea>
                    <button type="submit" class="{{ $btn }} w-full bg-red-600 text-white hover:bg-red-700">Reject</button>
                </form>
            </div>
            @elseif($application->status === 'needs_changes')
                <p class="text-sm text-gray-600">Waiting for the applicant to update and resubmit.</p>
                <form method="POST" action="{{ route('admin.compensation.adc-bonus.applications.review', [$application, 'reject']) }}" class="mt-3"
                      data-confirm="Reject this application?" data-confirm-title="Reject application" data-confirm-impact="The applicant is emailed your reason.">
                    @csrf
                    <textarea name="reason" rows="2" maxlength="1000" required placeholder="Reason" class="w-full border border-gray-300 rounded-lg px-3 py-1.5 text-sm mb-2"></textarea>
                    <button type="submit" class="{{ $btn }} w-full bg-red-600 text-white hover:bg-red-700">Reject</button>
                </form>
            @else
                <p class="text-sm text-gray-600">This application is closed.</p>
            @endif
        </section>
    </div>
</div>
@endsection
