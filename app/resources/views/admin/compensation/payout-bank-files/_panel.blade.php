{{--
    Every bank file of the batch, newest first, with who handled it and when.
    Expects: $batch, $bankFiles (from HandlesPayoutBatchActions::bankFilePanel),
    $routeBase, $rupees.
--}}
@can('finance.record')
@php
    $files = $bankFiles['files'];
    $ordinals = $bankFiles['ordinals'];
    $importCount = (int) $bankFiles['import_count'];
@endphp
@if($batch->approved_at !== null)
<x-ui.card flush class="mt-6">
    <div class="px-5 py-3 border-b border-gray-100 flex flex-wrap items-center justify-between gap-2">
        <span class="text-sm font-semibold text-gray-900 flex items-center gap-1">
            Bank files
            <x-help-tip text="Every bank file downloaded for this batch and every bank response file uploaded to it, kept encrypted. Each download is logged. Files are deleted after the retention period set in Settings; their rows stay, so comparisons keep working." />
        </span>
        <div class="flex flex-wrap items-center gap-2">
            @if($importCount >= 2)
            <a href="{{ route($routeBase.'.bank-files.compare', $batch) }}"
               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <x-lucide-git-compare class="w-4 h-4" /> Compare imports
            </a>
            @endif
            @if($importCount >= 1)
            <a href="{{ route($routeBase.'.bank-files.history', $batch) }}"
               class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50 transition-colors">
                <x-lucide-table class="w-4 h-4" /> All imports
            </a>
            @endif
        </div>
    </div>
    @if($files->isEmpty())
    <x-ui.empty-state title="No bank file has been downloaded or uploaded for this batch yet." />
    @else
    <ul class="divide-y divide-gray-50">
        @foreach($files as $file)
        @php
            $summary = $file->summary ?? [];
            $isImport = $file->direction === 'import';
            $label = ($isImport ? 'Import' : 'Export').' #'.($ordinals[$file->id] ?? '?');
            $identical = $file->identical_to_id !== null ? ($isImport ? 'Import' : 'Export').' #'.($ordinals[$file->identical_to_id] ?? '?') : null;
        @endphp
        <li class="px-5 py-3 flex flex-wrap items-start justify-between gap-3 text-xs">
            <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900 flex items-center gap-1">
                    @if($isImport)
                        <x-lucide-upload class="w-4 h-4 text-gray-500" />
                    @else
                        <x-lucide-download class="w-4 h-4 text-gray-500" />
                    @endif
                    {{ $label }}
                    <span class="font-normal text-gray-600">·
                        {{ $isImport ? 'uploaded' : 'downloaded' }} by {{ $file->actor?->full_name ?? 'unknown' }}
                        · {{ $file->created_at->format('d M Y H:i') }}
                    </span>
                </p>
                <p class="mt-0.5 text-gray-600">
                    @if($isImport && $file->outcome === 'refused')
                        <span class="text-red-700 font-medium">Not applied</span> — {{ $summary['errors'][0] ?? 'the file could not be read.' }}
                    @elseif($isImport)
                        {{ $summary['rows'] ?? $file->row_count }} row(s) · {{ $summary['transferred'] ?? 0 }} paid · {{ $summary['failed'] ?? 0 }} failed
                        · {{ $summary['skipped'] ?? 0 }} skipped · {{ $summary['rejected'] ?? 0 }} rejected · {{ $summary['unmatched'] ?? 0 }} not in this batch
                    @else
                        {{ $summary['line_count'] ?? $file->row_count }} line(s) · {{ $rupees((int) ($summary['total_net_paise'] ?? 0)) }}
                        @if(($summary['already_in_earlier_file'] ?? 0) > 0)
                            · <span class="text-amber-700 font-medium">{{ $summary['already_in_earlier_file'] }} already in an earlier file</span>
                        @endif
                    @endif
                    @if($identical)
                        · <span class="text-gray-500">identical to {{ $identical }}</span>
                    @endif
                </p>
                <p class="mt-0.5 text-gray-500 truncate">{{ $file->original_name }}</p>
            </div>
            <div class="flex items-center gap-2 shrink-0">
                @if($isImport && $importCount >= 2 && $file->outcome !== 'refused')
                <a href="{{ route($routeBase.'.bank-files.compare', [$batch, 'to' => $file->id]) }}"
                   class="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-300 bg-white text-[11px] font-medium text-gray-700 hover:bg-gray-50">
                    <x-lucide-git-compare class="w-3 h-3" /> Compare
                </a>
                @endif
                @if($file->storage_key === null)
                <span class="text-[11px] text-gray-500">Deleted after the retention period{{ $file->purged_at ? ' on '.$file->purged_at->format('d M Y') : '' }}</span>
                @else
                <a href="{{ route($routeBase.'.bank-files.download', [$batch, $file]) }}"
                   class="inline-flex items-center gap-1 px-2 py-1 rounded border border-gray-300 bg-white text-[11px] font-medium text-gray-700 hover:bg-gray-50">
                    <x-lucide-download class="w-3 h-3" /> Download
                </a>
                @endif
            </div>
        </li>
        @endforeach
    </ul>
    @endif
</x-ui.card>
@endif
@endcan
