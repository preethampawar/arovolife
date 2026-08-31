{{-- Messages —— mirrors the topnav bell. Same unread-count source
     (Message::unreadFor), so the badge here and the badge in the
     top-right corner always agree. --}}
<div class="bg-white rounded-2xl border border-gray-200 shadow-sm p-6">
    <div class="flex items-center justify-between gap-2 mb-2">
        <p class="text-xs text-gray-700 uppercase tracking-wider font-semibold">Messages</p>
        @if($unreadMessagesCount > 0)
            <span class="inline-flex items-center justify-center min-w-[20px] h-[20px] px-1.5 rounded-full bg-brand-700 text-white text-[10px] font-bold leading-none">
                {{ $unreadMessagesCount > 99 ? '99+' : $unreadMessagesCount }}
            </span>
        @endif
    </div>

    @if($latestMessage)
        @php
            $senderName = $latestMessage->fromUser?->full_name
                ?: ($latestMessage->fromUser?->email ?: 'Unknown sender');
            $isUnread = $latestMessage->read_at === null;
        @endphp
        <a href="{{ route('messages.show', ['user' => $latestMessage->from_user_id]) }}"
            class="block group">
            <p class="text-sm font-semibold text-gray-900 group-hover:text-brand-800 truncate {{ $isUnread ? 'text-brand-700' : '' }}">
                {{ $senderName }}
            </p>
            <p class="text-xs text-gray-800 line-clamp-2 mt-1">{{ $latestMessage->body }}</p>
            <p class="text-[11px] text-gray-600 mt-1.5">{{ $latestMessage->created_at->diffForHumans() }}</p>
        </a>
        <a href="{{ route('messages.index') }}"
            class="inline-flex items-center gap-1 mt-3 text-xs text-brand-700 hover:text-brand-800 font-medium">
            View all messages
            <x-lucide-chevron-right class="w-3.5 h-3.5" />
        </a>
    @else
        <p class="text-sm text-gray-700">No messages yet.</p>
        <p class="text-xs text-gray-600 mt-1">Open a card in the tree view and click "Send Message" to start a conversation.</p>
        <a href="{{ route('messages.index') }}"
            class="inline-flex items-center gap-1 mt-3 text-xs text-brand-700 hover:text-brand-800 font-medium">
            Open inbox
            <x-lucide-chevron-right class="w-3.5 h-3.5" />
        </a>
    @endif
</div>
