@extends('layouts.app')
@section('title', 'Notifications')

@section('content')
<h1 class="mb-1 text-xl font-bold text-gray-900">Notifications</h1>
<p class="mb-6 text-sm text-gray-600">Updates about your account and orders. The newest are at the top.</p>

@if($notifications->isEmpty())
    <div class="rounded-xl border border-gray-200 bg-white p-10 text-center text-sm text-gray-600">
        There are no notifications right now.
    </div>
@else
    <div class="space-y-3">
        @foreach($notifications as $notification)
            @php
                $isUnread = $notification->read_at === null;
                $title = match ($notification->data['kind'] ?? null) {
                    'order.status_changed' => 'Order update',
                    default => 'Notification',
                };
                $body = $notification->data['message'] ?? '';
            @endphp
            <a href="{{ route('notifications.open', $notification->id) }}"
               class="block rounded-xl border bg-white p-5 transition-colors hover:border-brand-300 {{ $isUnread ? 'border-brand-200' : 'border-gray-200' }}">
                <div class="mb-1 flex items-start justify-between gap-3">
                    <h2 class="text-sm font-semibold text-gray-900">{{ $title }}</h2>
                    @if($isUnread)
                        <span class="shrink-0 rounded-full bg-brand-100 px-2 py-0.5 text-[11px] font-semibold text-brand-800">New</span>
                    @endif
                </div>
                <p class="text-sm text-gray-700">{{ $body }}</p>
                <p class="mt-2 text-[11px] text-gray-500">{{ $notification->created_at?->format('d M Y, h:i A') }}</p>
            </a>
        @endforeach
    </div>
    <div class="mt-4">{{ $notifications->links() }}</div>
@endif
@endsection
