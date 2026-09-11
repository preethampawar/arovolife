@extends('admin.layouts.admin')
@section('title', 'Reported message')
@section('heading', 'Reported message')

@section('content')
@php
    use App\Modules\Messaging\Models\MessageReport;
    $reportedId = $report->message_id;
@endphp

<a href="{{ route('admin.messaging.reports.index') }}" class="mb-4 inline-flex items-center gap-1 text-xs text-gray-700 hover:text-gray-900">
    <x-lucide-chevron-left class="w-3.5 h-3.5" />
    All reported messages
</a>

<div class="grid gap-6 lg:grid-cols-3">
    <div class="lg:col-span-2 space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h2 class="mb-1 text-sm font-semibold text-gray-900">The conversation around it</h2>
            <p class="mb-4 text-xs text-gray-600">The reported message is highlighted. Three messages either side are shown for context — one line rarely means anything on its own, and the whole history is more than judging one complaint needs.</p>

            <div class="space-y-3">
                @foreach($context as $msg)
                    @php $isReported = (int) $msg->id === (int) $reportedId; @endphp
                    <div class="rounded-lg border px-4 py-3 {{ $isReported ? 'border-red-300 bg-red-50' : 'border-gray-200 bg-gray-50' }}">
                        <p class="mb-1 text-[11px] uppercase tracking-wide text-gray-600">
                            {{ $msg->fromUser?->full_name ?? 'Unknown' }} · {{ $msg->created_at->format('d M Y, h:i A') }}
                            @if($isReported)<span class="font-semibold text-red-700">· reported</span>@endif
                        </p>
                        <p class="whitespace-pre-wrap break-words text-sm text-gray-900">{{ $msg->body }}</p>
                    </div>
                @endforeach
            </div>
        </div>

        @if($report->status === MessageReport::STATUS_OPEN)
            <form method="POST" action="{{ route('admin.messaging.reports.review', ['report' => $report->id]) }}"
                class="rounded-xl border border-gray-200 bg-white p-5"
                data-confirm="Close this report?"
                data-confirm-title="Record your decision"
                data-confirm-impact="Your note and decision are audit-logged against this report. Closing it does not change the message or the sender's account — use the account tools on the distributor's page for that.">
                @csrf
                <h2 class="mb-3 text-sm font-semibold text-gray-900">Your decision</h2>

                <label class="mb-1 block text-xs font-medium text-gray-700">Outcome</label>
                <select name="status" required class="mb-4 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                    <option value="{{ MessageReport::STATUS_REVIEWED }}">Reviewed — nothing further needed</option>
                    <option value="{{ MessageReport::STATUS_ACTIONED }}">Actioned — the account was dealt with</option>
                </select>

                <label class="mb-1 block text-xs font-medium text-gray-700">What you found, and what you did</label>
                <textarea name="review_note" rows="4" required maxlength="2000"
                    class="mb-1 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                    placeholder="e.g. Sender promised a fixed monthly return. Warning issued and recorded on their account."></textarea>
                <p class="mb-4 text-[11px] text-gray-500">Do not paste a full PAN or Aadhaar number into this note.</p>

                @error('review_note')<p class="mb-3 text-xs text-red-600">{{ $message }}</p>@enderror

                <button type="submit" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">
                    Close report
                </button>
            </form>
        @else
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-2 text-sm font-semibold text-gray-900">Decision</h2>
                <p class="text-sm text-gray-900">{{ ucfirst($report->status) }} by {{ $report->reviewer?->full_name ?? 'a colleague' }} on {{ $report->reviewed_at?->format('d M Y, h:i A') }}</p>
                <p class="mt-2 whitespace-pre-wrap text-sm text-gray-700">{{ $report->review_note }}</p>
            </div>
        @endif
    </div>

    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white p-5 text-sm">
            <h2 class="mb-3 text-sm font-semibold text-gray-900">The report</h2>
            <dl class="space-y-2">
                <div><dt class="text-xs text-gray-600">Reason given</dt><dd class="text-gray-900">{{ $report->categoryLabel() }}</dd></div>
                <div><dt class="text-xs text-gray-600">Reported by</dt><dd><x-message-party :user="$report->reporter" /></dd></div>
                <div><dt class="text-xs text-gray-600">Sender</dt><dd><x-message-party :user="$report->message?->fromUser" /></dd></div>
                <div><dt class="text-xs text-gray-600">Received</dt><dd class="text-gray-900">{{ $report->created_at->format('d M Y, h:i A') }}</dd></div>
            </dl>

            @if(filled($report->reason))
                <p class="mt-3 text-xs text-gray-600">What they added</p>
                <p class="whitespace-pre-wrap text-sm text-gray-900">{{ $report->reason }}</p>
            @endif
        </div>
    </div>
</div>
@endsection
