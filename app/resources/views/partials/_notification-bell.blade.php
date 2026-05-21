{{-- Notification bell — top-nav icon link to /messages with an unread badge.
     Only shown for authenticated users. Counts unread messages addressed to
     the current user via Message::unreadFor() — single source of truth.

     The count is read once per request via a cached Eloquent query against
     the `(to_user_id, read_at)` index. For a typical user with <100 lifetime
     messages this is <1ms; under load we'd cache the count for 30s, but
     Phase 1 reads fresh on every render so the badge is always accurate. --}}
@auth
    @php
        $unreadMessages = \App\Modules\Messaging\Models\Message::query()
            ->unreadFor((int) auth()->id())
            ->count();
    @endphp
    <a href="{{ route('messages.index') }}"
       class="relative {{ $bellLayout ?? '' }} text-brand-50 hover:text-white transition-colors"
       aria-label="Messages{{ $unreadMessages > 0 ? ' ('.$unreadMessages.' unread)' : '' }}"
       title="Messages">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.8" stroke="currentColor" class="w-5 h-5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
        </svg>
        @if($unreadMessages > 0)
            <span class="absolute -top-0.5 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-sunrise-500 text-white text-[10px] font-bold leading-none ring-2 ring-brand-500">
                {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
            </span>
        @endif
    </a>
@endauth
