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
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 border-b border-gray-200 text-left text-[11px] uppercase tracking-wider text-gray-500">
                    <tr>
                        <th class="px-4 py-3 w-12">S.No</th>
                        <th class="px-4 py-3">From</th>
                        <th class="px-4 py-3">Message</th>
                        <th class="px-4 py-3 whitespace-nowrap">Date &amp; time</th>
                        <th class="px-4 py-3 text-right">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($conversations as $i => $conv)
                        @php
                            $otherId = (int) $conv->other_user_id;
                            $other = $users->get($otherId);
                            $preview = $previewByPair[$otherId] ?? null;
                            $unread = (int) $conv->unread_count;
                            // Sender name — never fall back to an email address.
                            $name = $other?->full_name ?: ('Distributor #'.$otherId);
                            $adn = $adnByUser[$otherId] ?? null;
                        @endphp
                        <tr class="hover:bg-gray-50/60">
                            <td class="px-4 py-3 align-top text-gray-500">{{ $i + 1 }}</td>
                            <td class="px-4 py-3 align-top">
                                <div class="flex items-center gap-2">
                                    <span class="font-semibold text-gray-900">{{ $name }}</span>
                                    @if($unread > 0)
                                        <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[10px] font-bold bg-brand-500 text-white" title="{{ $unread }} unread">{{ $unread }}</span>
                                    @endif
                                </div>
                                <div class="text-[11px] font-mono text-brand-700 mt-0.5">ADN {{ $adn ?? '—' }}</div>
                            </td>
                            <td class="px-4 py-3 align-top">
                                @if($preview)
                                    <p class="text-gray-700 line-clamp-2 max-w-md {{ $unread > 0 ? 'font-medium text-gray-900' : '' }}">
                                        @if($preview->from_user_id === auth()->id())<span class="text-gray-500">You:</span> @endif{{ $preview->body }}
                                    </p>
                                @else
                                    <span class="text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 align-top whitespace-nowrap text-xs text-gray-600">
                                {{ $preview?->created_at?->format('d M Y, h:i A') ?? '—' }}
                            </td>
                            <td class="px-4 py-3 align-top text-right">
                                <a href="{{ route('messages.show', ['user' => $otherId]) }}"
                                   class="inline-flex items-center rounded-lg bg-brand-500 hover:bg-brand-600 text-white font-medium px-3 py-1.5 text-xs transition-colors">
                                    Reply →
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
@endsection
