{{-- Notification bell — one top-nav icon for everything addressed to this
     person: unread direct messages, unread company announcements, and
     unread in-app notifications (order status changes, etc. — F59). All
     three counts feed one badge because a distributor should have one place
     to look for "something new for me", not competing dots.

     Messages and announcements are gated on their own flag; the in-app
     notification channel is not a Pennant feature, so it alone can keep the
     bell visible even with both flags off — a bell over a closed channel
     with no unread notification is still a link to a 404, but one carrying
     an unread order update is not.

     Messages come from Message::unreadFor(); announcements come from
     AnnouncementService, which is the single place that knows who an
     announcement is addressed to; notifications come from the standard
     Notifiable::unreadNotifications() relation. All three read fresh on
     every render: for a typical user these are three indexed counts, and a
     stale badge is worse than a cheap one. --}}
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

        // Unread in-app notifications (F59: an order status change now
        // writes one of these). Always-on, unlike the two above.
        $unreadNotifications = auth()->user()->unreadNotifications()->count();

        // Land on whichever channel actually holds the unread items — a
        // badge that says "3" must not open an empty inbox while 3
        // items sit unread on another page.
        $bellDestination = match (true) {
            $unreadMessages > 0 => 'messages',
            $unreadAnnouncements > 0 => 'announcements',
            $unreadNotifications > 0 => 'notifications',
            $messagingOn => 'messages',
            default => 'announcements',
        };
        $bellRoute = match ($bellDestination) {
            'messages' => route('messages.index'),
            'notifications' => route('notifications.index'),
            default => route('announcements.index'),
        };
        $bellLabel = match ($bellDestination) {
            'messages' => 'Messages',
            'notifications' => 'Notifications',
            default => 'Announcements',
        };
    @endphp
    @if($messagingOn || $announcementsOn || $unreadNotifications > 0)
    <a href="{{ $bellRoute }}"
       class="relative {{ $bellLayout ?? '' }} text-brand-50 hover:text-white transition-colors"
       aria-label="Notifications{{ ($unreadMessages + $unreadAnnouncements + $unreadNotifications) > 0 ? ' ('.($unreadMessages + $unreadAnnouncements + $unreadNotifications).' unread)' : '' }}"
       title="{{ $bellLabel }}">
        <x-lucide-bell class="w-5 h-5" />
        @php $unreadTotal = $unreadMessages + $unreadAnnouncements + $unreadNotifications; @endphp
        @if($unreadTotal > 0)
            <span class="absolute -top-0.5 -right-1 inline-flex items-center justify-center min-w-[18px] h-[18px] px-1 rounded-full bg-sunrise-800 text-white text-[10px] font-bold leading-none ring-2 ring-brand-500">
                {{ $unreadTotal > 99 ? '99+' : $unreadTotal }}
            </span>
        @endif
    </a>
    @endif
@endauth
