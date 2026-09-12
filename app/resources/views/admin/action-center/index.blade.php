@extends('admin.layouts.admin')
@section('title', 'Action Center')
@section('heading', 'Action Center')

@section('content')

@if($summary->isEmpty())
    <div class="bg-white rounded-xl border border-gray-200 p-10 text-center text-gray-600">
        Nothing needs action right now.
    </div>
@else
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-5">
        @foreach($summary as $group => $rows)
        <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
            <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
                <h3 class="text-sm font-semibold text-gray-900">{{ \App\Modules\ActionCenter\Support\ActionGroup::label($group) }}</h3>
            </div>
            <ul class="divide-y divide-gray-100">
                @foreach($rows as $row)
                @php
                    $dotClass = match ($row['severity']) {
                        'critical' => 'bg-red-500',
                        'warning' => 'bg-amber-500',
                        default => 'bg-slate-400',
                    };
                @endphp
                <li>
                    <a href="{{ route('admin.action-center.show', $row['key']) }}"
                       class="flex items-center justify-between gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                        <div class="flex items-center gap-3 min-w-0">
                            <span class="w-2 h-2 rounded-full shrink-0 {{ $dotClass }}" aria-hidden="true"></span>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $row['label'] }}</p>
                                <p class="text-xs text-gray-600 truncate">{{ $row['description'] }}</p>
                            </div>
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-sm font-semibold text-gray-900">{{ $row['count'] }}</p>
                            @if($row['oldest_age_hours'] !== null)
                                <p class="text-xs text-gray-600">oldest {{ $row['oldest_age_hours'] }}h</p>
                            @endif
                        </div>
                    </a>
                </li>
                @endforeach
            </ul>
        </div>
        @endforeach
    </div>
@endif

@endsection
