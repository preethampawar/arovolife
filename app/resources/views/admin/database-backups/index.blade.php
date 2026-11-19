@extends('admin.layouts.admin')
@section('title', 'Database backups')
@section('heading', 'Database backups')

@if($running)
    {{-- Refresh while a backup or restore runs, so the result appears without a click. --}}
    @push('styles')
        <meta http-equiv="refresh" content="10">
    @endpush
@endif

@section('content')

<p class="text-sm text-gray-600 mb-2 max-w-3xl">A compressed copy of the whole database is taken every day at 11:45 PM IST and kept for {{ config('db_backup.retention_days') }} days. Each backup can be downloaded{{ $canRestore ? ' or restored' : '' }}. Every download{{ $canRestore ? ' and restore' : '' }} is audit-logged.</p>
@if($canRestore)
<p class="text-xs text-gray-500 mb-5 max-w-3xl">After a restore: if the code is newer than the backup, run <code class="font-mono">php artisan migrate</code>. Cached values may be stale for a few minutes.</p>
@endif
@if($canRestore)
<p class="text-xs text-gray-500 mb-5 max-w-3xl">Uploaded files (catalogue images) are copied to the same bucket every day and are restored with <code class="font-mono">php artisan db:backup:files-restore public</code>.</p>
@else
<p class="text-xs text-gray-500 mb-5 max-w-3xl">Uploaded files (catalogue images) are copied to the same bucket every day.</p>
@endif

@if($status && ($canRestore || $status['type'] !== 'restore'))
    @php
        $started = $status['started_at'] ? \Illuminate\Support\Carbon::parse($status['started_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i A') : null;
        $finished = $status['finished_at'] ? \Illuminate\Support\Carbon::parse($status['finished_at'])->timezone('Asia/Kolkata')->format('d M Y, h:i A') : null;
        $label = $status['type'] === 'restore' ? 'Restore' : 'Backup';
    @endphp
    @if($status['state'] === 'running')
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 max-w-3xl">
            <x-lucide-loader class="w-4 h-4 shrink-0 mt-px animate-spin" />
            <div>{{ $label }} running{{ $status['file'] ? ' — '.$status['file'] : '' }}{{ $started ? ', started '.$started.' IST' : '' }}. This page refreshes every 10 seconds.</div>
        </div>
    @elseif($status['state'] === 'succeeded')
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-green-200 bg-green-50 p-4 text-sm text-green-700 max-w-3xl">
            <x-lucide-circle-check class="w-4 h-4 shrink-0 mt-px" />
            <div>Last {{ strtolower($label) }} succeeded{{ $finished ? ' at '.$finished.' IST' : '' }}. {{ $status['message'] }}</div>
        </div>
    @else
        <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 max-w-3xl">
            <x-lucide-circle-alert class="w-4 h-4 shrink-0 mt-px" />
            <div>Last {{ strtolower($label) }} failed{{ $finished ? ' at '.$finished.' IST' : '' }}. {{ $status['message'] }}</div>
        </div>
    @endif
@endif

<form method="POST" action="{{ route('admin.database-backups.store') }}" class="mb-6"
      data-confirm="Take a backup of the database now?"
      data-confirm-title="Back up now"
      data-confirm-impact="The backup runs in the background and appears in the list when it finishes. The site stays up.">
    @csrf
    <button type="submit" @disabled($running)
        class="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50">
        <x-lucide-database-backup class="w-4 h-4" /> Back up now
    </button>
</form>

<x-ui.card flush>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase w-12">S.No.</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">File</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Taken (IST)</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Type</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Size</th>
                <th class="px-4 py-3 text-xs font-medium text-gray-600 uppercase">Download</th>
                @if($canRestore)
                <th class="px-4 py-3 text-xs font-medium text-gray-600 uppercase">Restore</th>
                @endif
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($backups as $backup)
            <tr class="hover:bg-gray-50 align-top">
                <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-700 break-all">{{ $backup->file }}</td>
                <td class="px-4 py-3 text-gray-800 whitespace-nowrap">{{ $backup->createdAt->format('d M Y, h:i A') }}</td>
                <td class="px-4 py-3 text-gray-700">{{ ucfirst(str_replace('-', ' ', $backup->trigger)) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ \Illuminate\Support\Number::fileSize($backup->bytes, 1) }}</td>
                <td class="px-4 py-3 text-center">
                    <a href="{{ route('admin.database-backups.download', $backup->file) }}" class="inline-flex items-center gap-1.5 text-brand-700 font-medium hover:underline">
                        <x-lucide-download class="w-4 h-4" /> Download
                    </a>
                </td>
                @if($canRestore)
                <td class="px-4 py-3">
                    <details class="min-w-64">
                        <summary class="cursor-pointer inline-flex items-center gap-1.5 text-red-700 font-medium hover:underline">
                            <x-lucide-history class="w-4 h-4" /> Restore
                        </summary>
                        <form method="POST" action="{{ route('admin.database-backups.restore', $backup->file) }}" class="mt-3 space-y-3"
                              data-confirm="Replace the whole database with {{ $backup->file }}?"
                              data-confirm-title="Restore this backup"
                              data-confirm-impact="Everything recorded after this backup is lost, but payments and payouts made since then have still moved real money. The site is in maintenance mode until the restore finishes.">
                            @csrf
                            <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 space-y-1">
                                <p class="font-semibold">This replaces the current database with this backup.</p>
                                <p>Everything recorded after {{ $backup->createdAt->format('d M Y, h:i A') }} IST is lost. The site is in maintenance mode while the restore runs. A safety backup of the current database is taken first.</p>
                                <p class="font-semibold pt-1">A restore does not undo real money.</p>
                                <ul class="list-disc pl-4 space-y-0.5">
                                    <li>Customer payments made after this backup disappear from the site, but the customers have still been charged.</li>
                                    <li>Payouts sent after this backup look unpaid again, so the next payout run could pay them a second time.</li>
                                    <li>Bonuses and wallet entries computed after this backup are gone and the engines must be re-run.</li>
                                </ul>
                                <p>Before restoring the live site, compare Razorpay and bank records from {{ $backup->createdAt->format('d M Y, h:i A') }} IST onward.</p>
                            </div>
                            <label class="block text-xs font-medium text-gray-700">
                                Type the file name to confirm
                                <input type="text" name="confirm_file" required autocomplete="off"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs">
                            </label>
                            <label class="block text-xs font-medium text-gray-700">
                                Your password
                                <input type="password" name="password" required autocomplete="current-password"
                                    class="mt-1 block w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                            </label>
                            <button type="submit" @disabled($running)
                                class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50">
                                <x-lucide-history class="w-4 h-4" /> Restore this backup
                            </button>
                        </form>
                    </details>
                </td>
                @endif
            </tr>
            @empty
            <tr>
                <td colspan="{{ $canRestore ? 7 : 6 }}" class="px-4 py-6 text-center text-gray-500">No backups on this environment yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

<h2 class="mt-10 mb-2 text-base font-semibold text-gray-900">Settings file copies</h2>
<p class="text-sm text-gray-600 mb-2 max-w-3xl">Each daily run also saves a copy of the server's settings file, protected with the backup password the owner keeps. It cannot be opened without that password. macOS's built-in Archive Utility cannot open it; Keka or 7-Zip can. @if($canRestore)To restore, unzip it and put the <code class="font-mono">.env</code> file back on the server. There is no restore button.@endif</p>

@unless($settingsPasswordUsable)
    <div class="mb-5 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 max-w-3xl">
        <x-lucide-triangle-alert class="w-4 h-4 shrink-0 mt-px" />
        <div>@if($canRestore)Settings-file copies are not being made until <code class="font-mono">ENV_BACKUP_PASSWORD</code> (20 or more characters) is set in the server's settings file.@else Settings-file copies are not being made yet. The backup password has not been set on the server.@endif</div>
    </div>
@endunless

<x-ui.card flush>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200">
            <tr>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase w-12">S.No.</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">File</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Taken (IST)</th>
                <th class="text-left px-4 py-3 text-xs font-medium text-gray-600 uppercase">Type</th>
                <th class="text-right px-4 py-3 text-xs font-medium text-gray-600 uppercase">Size</th>
                <th class="px-4 py-3 text-xs font-medium text-gray-600 uppercase">Download</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse($settingsCopies as $copy)
            <tr class="hover:bg-gray-50 align-top">
                <td class="px-4 py-3 text-gray-500 tabular-nums">{{ $loop->iteration }}</td>
                <td class="px-4 py-3 font-mono text-xs text-gray-700 break-all">{{ $copy->file }}</td>
                <td class="px-4 py-3 text-gray-800 whitespace-nowrap">{{ $copy->createdAt->format('d M Y, h:i A') }}</td>
                <td class="px-4 py-3 text-gray-700">{{ ucfirst(str_replace('-', ' ', $copy->trigger)) }}</td>
                <td class="px-4 py-3 text-right tabular-nums text-gray-700">{{ \Illuminate\Support\Number::fileSize($copy->bytes, 1) }}</td>
                <td class="px-4 py-3 text-center">
                    <a href="{{ route('admin.database-backups.settings.download', $copy->file) }}" class="inline-flex items-center gap-1.5 text-brand-700 font-medium hover:underline">
                        <x-lucide-download class="w-4 h-4" /> Download
                    </a>
                </td>
            </tr>
            @empty
            <tr>
                <td colspan="6" class="px-4 py-6 text-center text-gray-500">No settings file copies on this environment yet.</td>
            </tr>
            @endforelse
        </tbody>
    </table>
    </div>
</x-ui.card>

@endsection
