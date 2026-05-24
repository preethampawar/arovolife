{{-- Draft notice removed: pure session-based registration has no persistent drafts --}}
@if (isset($draftExpiresAt) && $draftExpiresAt !== null)
    @php
        $daysLeft = (int) now()->diffInDays($draftExpiresAt, false);
    @endphp
    @if ($daysLeft >= 0)
        @if ($daysLeft === 0)
            <div class="mt-6 rounded-md bg-red-50 border border-red-200 p-4">
                <p class="text-sm text-red-800 font-medium">
                    Your registration draft expires <strong>today</strong>. Complete your registration now or you will need to start again.
                </p>
            </div>
        @elseif ($daysLeft <= 3)
            <div class="mt-6 rounded-md bg-amber-50 border border-amber-200 p-4">
                <p class="text-sm text-amber-800">
                    <strong>Your progress is saved</strong>, but your draft expires in <strong>{{ $daysLeft }} day{{ $daysLeft === 1 ? '' : 's' }}</strong>. Complete your registration before then.
                </p>
            </div>
        @else
            <div class="mt-6 rounded-md bg-blue-50 border border-blue-200 p-4">
                <p class="text-sm text-blue-700">
                    <strong>Your progress is saved.</strong> You can close this page and return within {{ $daysLeft }} days to continue from where you left off. After 7 days your draft expires and you will need to start again using your referral link.
                </p>
            </div>
        @endif
    @endif
@endif
