@extends('admin.layouts.admin')
@section('title', $provider->label())
@section('heading', $provider->label())

@section('content')

<p class="text-sm text-gray-600 mb-5">{{ $provider->description() }}</p>

<form method="GET" class="flex items-center gap-3 mb-6 flex-wrap">
    <select name="severity" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="">All severities</option>
        @foreach(['critical' => 'Critical', 'warning' => 'Warning', 'info' => 'Info'] as $value => $label)
            <option value="{{ $value }}" @selected($filters['severity'] === $value)>{{ $label }}</option>
        @endforeach
    </select>
    <select name="age" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="">Any age</option>
        <option value="day" @selected($filters['age'] === 'day')>Under 1 day</option>
        <option value="week" @selected($filters['age'] === 'week')>1–7 days</option>
        <option value="month" @selected($filters['age'] === 'month')>7–30 days</option>
        <option value="over_month" @selected($filters['age'] === 'over_month')>Over 30 days</option>
    </select>
    @if($warehouses->isNotEmpty())
    <select name="warehouse" class="rounded-lg border border-gray-300 px-3 py-2 text-sm bg-white focus:outline-none focus:ring-2 focus:ring-brand-500">
        <option value="">All warehouses</option>
        @foreach($warehouses as $wh)
            <option value="{{ $wh }}" @selected($filters['warehouse'] === $wh)>{{ $wh }}</option>
        @endforeach
    </select>
    @endif
    <button type="submit" class="px-4 py-2 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold transition-colors">Filter</button>
    @if(collect($filters)->filter()->isNotEmpty())
        <a href="{{ route('admin.action-center.show', $provider->key()) }}" class="text-sm text-gray-600 hover:text-gray-900">Clear</a>
    @endif
</form>

<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-gray-600 text-left">
            <tr>
                <th class="px-4 py-3 font-semibold">Severity</th>
                <th class="px-4 py-3 font-semibold">Item</th>
                <th class="px-4 py-3 font-semibold text-right">Age</th>
                <th class="px-4 py-3 font-semibold">Due</th>
                <th class="px-4 py-3"></th>
                <th class="px-4 py-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($items as $item)
                @php
                    $badgeClasses = match ($item->severity) {
                        'critical' => 'bg-red-50 text-red-700 border-red-200',
                        'warning' => 'bg-amber-50 text-amber-700 border-amber-200',
                        default => 'bg-slate-50 text-slate-700 border-slate-200',
                    };
                @endphp
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3">
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border {{ $badgeClasses }}">{{ ucfirst($item->severity) }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <p class="font-medium text-gray-900">{{ $item->title }}</p>
                        <p class="text-xs text-gray-600">{{ $item->subtitle }}</p>
                    </td>
                    <td class="px-4 py-3 text-right font-mono text-gray-700">{{ $item->ageHours() }}h</td>
                    <td class="px-4 py-3 text-gray-600">
                        {{ $item->dueAt?->format('d M Y H:i') ?? '—' }}
                        @if($item->isOverdue())
                            <span class="text-red-700 font-semibold">overdue</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @if($item->url)
                            <a href="{{ $item->url }}" class="text-brand-700 hover:text-brand-800 font-medium">Fix →</a>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right">
                        @unless($provider->statutory())
                        <details class="inline-block text-left">
                            <summary class="cursor-pointer text-gray-700 hover:text-gray-900 font-medium list-none">Snooze</summary>
                            <form method="POST" action="{{ route('admin.action-center.snooze', $provider->key()) }}"
                                class="mt-2 p-3 rounded-lg border border-gray-200 bg-gray-50 w-64"
                                data-confirm="Snooze this item for the chosen number of days?"
                                data-confirm-title="Confirm snooze"
                                data-confirm-impact="Hides this item from the queue until the snooze expires. It reappears automatically — nothing sweeps it early.">
                                @csrf
                                <input type="hidden" name="subject_type" value="{{ $item->subjectType }}">
                                <input type="hidden" name="subject_id" value="{{ $item->subjectId }}">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Days (1–{{ $maxSnoozeDays }})</label>
                                <input type="number" name="days" min="1" max="{{ $maxSnoozeDays }}" value="1" required
                                    class="w-full rounded-md border border-gray-300 px-2 py-1 text-sm mb-2">
                                <label class="block text-xs font-medium text-gray-700 mb-1">Reason (min 10 chars)</label>
                                <textarea name="reason" required minlength="10" rows="2"
                                    class="w-full rounded-md border border-gray-300 px-2 py-1 text-sm mb-2"></textarea>
                                <button type="submit" class="w-full px-3 py-1.5 rounded-md bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition-colors">Snooze</button>
                            </form>
                        </details>
                        @endunless
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="px-4 py-10 text-center text-gray-600">Nothing needs action right now.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $items->links() }}</div>

@endsection
