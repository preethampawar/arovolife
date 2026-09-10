{{-- Notification bell — one top-nav icon for everything addressed to this
     person: unread direct messages, and unread company announcements. Both
     counts feed one badge because a distributor should have one place to look
     for "something new for me", not two competing dots.

     Each half is gated on its own flag, and the bell disappears entirely when
     neither is live — a bell over a closed channel is a link to a 404.

     Messages come from Message::unreadFor(); announcements come from
     AnnouncementService, which is the single place that knows who an
     announcement is addressed to. Both read fresh on every render: for a
     typical user this is two indexed counts, and a stale badge is worse than
     a cheap one. --}}
@auth
    @php
        // Zero trace while the killswitch is off: the badge counts messages,
        // so a bell with no channel behind it is a link to a 404.
        $messagingOn = \Laravel\Pennant\Feature::for(null)
            ->active(\App\Modules\Shared\Features\MessagingFeature::class);
        $unreadMessages = $messagingOn
            ? \App\Modules\Messaging\Models\Message::query()->unreadFor((int) auth()->id())->count()
            : 0;

        // Unread announcements ride the same bell rather than adding a second
        // one: a distributor has one place to look for "something new for me".
        $announcementsOn = \Laravel\Pennant\Feature::for(null)
            ->active(\App\Modules\Shared\Features\AnnouncementsFeature::class);
        $unreadAnnouncements = $announcementsOn
            ? app(\App\Modules\Content\Services\AnnouncementService::class)->unreadCountFor(auth()->user())
            : 0;
    @endphp
    @if($messagingOn || $announcementsOn)
    <a href="{{ $messagingOn ? route('messages.index') : route('announcements.index') }}"
       class="relative {{ $bellLayout ?? '' }} text-brand-50 hover:text-white transition-colors"
       aria-label="Notifications{{ ($unreadMessages + $unreadAnnouncements) > 0 ? ' ('.($unreadMessages + $unreadAnnouncements).' unread)' : '' }}"
       title="{{ $messagingOn ? 'Messages' : 'Announcements' }}">
        <x-lucide-bell class="w-5 h-5" />
        @php $unreadTotal = $unreadMessages + $unreadAnnouncements; @endphp
        @if($unreadTotal > 0)
            <span class="absolute -top-0.5 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-sunrise-800 text-white text-[10px] font-bold leading-none ring-2 ring-brand-500">
                {{ $unreadTotal > 99 ? '99+' : $unreadTotal }}
            </span>
        @endif
    </a>
    @endif
@endauth
