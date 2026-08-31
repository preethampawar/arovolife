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
        <x-lucide-bell class="w-5 h-5" />
        @if($unreadMessages > 0)
            <span class="absolute -top-0.5 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-sunrise-800 text-white text-[10px] font-bold leading-none ring-2 ring-brand-500">
                {{ $unreadMessages > 99 ? '99+' : $unreadMessages }}
            </span>
        @endif
    </a>
@endauth
