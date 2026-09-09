@extends('admin.layouts.admin')
@section('title', $announcement->exists ? 'Edit announcement' : 'New announcement')
@section('heading', $announcement->exists ? 'Edit announcement' : 'New announcement')

@section('content')
@php
    use App\Modules\Content\Models\Announcement;
    use App\Modules\Identity\Models\User;
    $inp = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400';
    $action = $announcement->exists
        ? route('admin.announcements.update', ['announcement' => $announcement->id])
        : route('admin.announcements.store');
@endphp

<a href="{{ route('admin.announcements.index') }}" class="mb-4 inline-flex items-center gap-1 text-xs text-gray-700 hover:text-gray-900">
    <x-lucide-chevron-left class="h-3.5 w-3.5" />
    All announcements
</a>

@if(session('status'))
    <div class="mb-4 rounded-lg border border-leaf-200 bg-leaf-50 px-4 py-3 text-sm text-leaf-800">{{ session('status') }}</div>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <form method="POST" action="{{ $action }}" class="lg:col-span-2 rounded-xl border border-gray-200 bg-white p-5"
        data-confirm="Save this announcement?"
        data-confirm-title="Save"
        data-confirm-impact="Saving does not send anything. The announcement stays a draft until you publish it.">
        @csrf
        @if($announcement->exists)
            @method('PATCH')
        @endif

        <label class="mb-1 block text-xs font-medium text-gray-700">Title</label>
        <input type="text" name="title" required maxlength="200" value="{{ old('title', $announcement->title) }}" class="{{ $inp }} mb-1">
        @error('title')<p class="mb-3 text-xs text-red-600">{{ $message }}</p>@enderror

        <label class="mb-1 mt-4 block text-xs font-medium text-gray-700">What you want to say</label>
        <textarea name="body" rows="12" required maxlength="20000" class="{{ $inp }} mb-1">{{ old('body', $announcement->body) }}</textarea>
        <p class="mb-1 text-[11px] text-gray-500">Plain text. Leave a blank line between paragraphs. Say what has happened — never what someone might earn.</p>
        @error('body')<p class="mb-3 text-xs text-red-600">{{ $message }}</p>@enderror

        <label class="mb-1 mt-4 block text-xs font-medium text-gray-700">Who should see it</label>
        <select name="audience" class="{{ $inp }} mb-3" id="audienceSelect">
            <option value="{{ Announcement::AUDIENCE_ALL }}" @selected(old('audience', $announcement->audience) === Announcement::AUDIENCE_ALL)>Every distributor</option>
            <option value="{{ Announcement::AUDIENCE_STATUS }}" @selected(old('audience', $announcement->audience) === Announcement::AUDIENCE_STATUS)>Accounts in one state</option>
            <option value="{{ Announcement::AUDIENCE_RANK }}" @selected(old('audience', $announcement->audience) === Announcement::AUDIENCE_RANK)>A rank and above</option>
        </select>

        <div class="mb-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-gray-600">Account state</label>
                <select name="audience_status" class="{{ $inp }}">
                    <option value="">—</option>
                    @foreach($statuses as $value)
                        <option value="{{ $value }}" @selected(old('audience_status', $announcement->audience === Announcement::AUDIENCE_STATUS ? $announcement->audience_value : null) === $value)>
                            {{ User::STATUS_LABELS[$value] ?? ucfirst($value) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs text-gray-600">Rank and above</label>
                <input type="number" name="audience_rank" min="1" max="9" class="{{ $inp }}"
                    value="{{ old('audience_rank', $announcement->audience === Announcement::AUDIENCE_RANK ? $announcement->audience_value : null) }}">
                <p class="mt-1 text-[11px] text-gray-500">Anyone who has ever qualified at this rank or higher.</p>
            </div>
        </div>

        <div class="mb-3 grid gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs text-gray-600">Stop showing it after</label>
                <input type="datetime-local" name="expires_at" class="{{ $inp }}"
                    value="{{ old('expires_at', $announcement->expires_at?->format('Y-m-d\TH:i')) }}">
                <p class="mt-1 text-[11px] text-gray-500">Leave blank to keep it up until you archive it.</p>
            </div>
            <div class="flex items-end">
                <label class="inline-flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="pinned" value="1" @checked(old('pinned', $announcement->pinned)) class="rounded border-gray-300">
                    Pin it to the top
                </label>
            </div>
        </div>
        @error('pinned')<p class="mb-3 text-xs text-red-600">{{ $message }}</p>@enderror

        <button type="submit" class="rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">
            {{ $announcement->exists ? 'Save changes' : 'Save draft' }}
        </button>
    </form>

    @if($announcement->exists)
        <div class="space-y-4">
            <div class="rounded-xl border border-gray-200 bg-white p-5">
                <h2 class="mb-1 text-sm font-semibold text-gray-900">Status</h2>
                <p class="mb-4 text-sm text-gray-700">{{ ucfirst($announcement->status) }}@if($announcement->published_at) · published {{ $announcement->published_at->format('d M Y') }}@endif</p>

                @if($announcement->status !== Announcement::STATUS_PUBLISHED)
                    <form method="POST" action="{{ route('admin.announcements.transition', ['announcement' => $announcement->id]) }}" class="mb-2"
                        data-confirm="Publish this announcement?"
                        data-confirm-title="Publish"
                        data-confirm-impact="Every distributor in its audience will see it immediately. If the email copy setting is on, it is also emailed — and an email cannot be taken back.">
                        @csrf
                        <input type="hidden" name="status" value="{{ Announcement::STATUS_PUBLISHED }}">
                        <button type="submit" class="w-full rounded-lg bg-brand-700 px-4 py-2 text-sm font-semibold text-white hover:bg-brand-800">Publish</button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.announcements.transition', ['announcement' => $announcement->id]) }}" class="mb-2"
                        data-confirm="Archive this announcement?"
                        data-confirm-title="Archive"
                        data-confirm-impact="It stops appearing for distributors. Anyone who was emailed a copy still has it.">
                        @csrf
                        <input type="hidden" name="status" value="{{ Announcement::STATUS_ARCHIVED }}">
                        <button type="submit" class="w-full rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Archive</button>
                    </form>
                @endif
            </div>
        </div>
    @endif
</div>
@endsection
