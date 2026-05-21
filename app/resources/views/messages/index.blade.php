@extends('layouts.app')
@section('title', 'Messages')

@section('content')
<div class="mb-6">
    <h1 class="text-2xl font-bold mb-1 text-gray-900">Messages</h1>
    <p class="text-sm text-gray-800">Direct conversations with other distributors.</p>
</div>

<div class="rounded-2xl border border-gray-200 bg-white overflow-hidden">
    @if($conversations->isEmpty())
        <div class="p-8 text-center">
            <p class="text-sm text-gray-700 mb-1">You don't have any messages yet.</p>
            <p class="text-xs text-gray-600">Open a distributor card in the tree and click the menu's "Send Message" item to start.</p>
        </div>
    @else
        <ul class="divide-y divide-gray-100">
            @foreach($conversations as $conv)
                @php
                    $otherId = (int) $conv->other_user_id;
                    $other = $users->get($otherId);
                    $preview = $previewByPair[$otherId] ?? null;
                    $unread = (int) $conv->unread_count;
                    $name = $other?->full_name ?: $other?->email ?: ('user #'.$otherId);
                @endphp
                <li>
                    <a href="{{ route('messages.show', ['user' => $otherId]) }}"
                       class="flex items-start justify-between gap-4 px-5 py-4 hover:bg-gray-50 transition-colors">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-baseline gap-2">
                                <p class="text-sm font-semibold text-gray-900 truncate {{ $unread > 0 ? 'text-brand-700' : '' }}">{{ $name }}</p>
                                @if($unread > 0)
                                    <span class="shrink-0 inline-flex items-center justify-center min-w-[20px] h-5 px-1.5 rounded-full text-[10px] font-bold bg-brand-500 text-white">{{ $unread }}</span>
                                @endif
                            </div>
                            @if($preview)
                                <p class="text-xs text-gray-700 mt-1 line-clamp-1">
                                    @if($preview->from_user_id === auth()->id())
                                        <span class="text-gray-600">You:</span>
                                    @endif
                                    {{ $preview->body }}
                                </p>
                            @endif
                        </div>
                        @if($preview)
                            <span class="text-[11px] text-gray-600 whitespace-nowrap pt-0.5">{{ $preview->created_at->diffForHumans(short: true) }}</span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
@endsection
