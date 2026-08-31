{{-- Reusable ID-card panel.

     Used by:
       - dashboard/index.blade.php (interactive — owner can upload/change photo)
       - partials/_id-card-modal.blade.php (read-only — viewer is looking at
         another distributor via the tree-card "Details" menu)

     Required vars:
       $idCardStats — output of DistributorIdCardStats::full()
       $idPhotoUrl  — output of DistributorIdCardStats::photoUrl(), or null

     Optional vars:
       $readonly    — bool; when true, photo block is plain <img> with no
                      upload affordance. Default false.
       $colorful    — bool; when true, rows are grouped into tinted bands
                      (identity / team / rank / money). Default false. --}}
@php
    $readonly = $readonly ?? false;
    $colorful = $colorful ?? false;

    // Row groups for the colourful variant — label => [dot, band] classes.
    $groupTones = [
        'identity' => ['dot' => 'bg-brand-500',   'band' => 'bg-brand-50/70'],
        'team'     => ['dot' => 'bg-indigo-500',  'band' => 'bg-indigo-50/70'],
        'rank'     => ['dot' => 'bg-sunrise-500', 'band' => 'bg-sunrise-50/70'],
        'money'    => ['dot' => 'bg-leaf-500',    'band' => 'bg-leaf-50/70'],
    ];
@endphp

<div class="grid grid-cols-1 sm:grid-cols-[1fr_140px] lg:grid-cols-[1fr_160px] gap-6">
    {{-- LEFT: 15-row stats list ───────────────────────────────────────── --}}
    <dl class="grid grid-cols-[180px_1fr] sm:grid-cols-[200px_1fr] gap-x-3 {{ $colorful ? 'gap-y-1' : 'gap-y-2' }} text-sm">
        @php
            $rows = [
                ['label' => 'Name',                    'group' => 'identity', 'value' => $idCardStats['name']],
                ['label' => 'ID Number',               'group' => 'identity', 'value' => $idCardStats['adn'],                'class' => 'font-mono text-brand-700 tracking-wider'],
                ['label' => 'Registration Date',       'group' => 'identity', 'value' => $idCardStats['registration_date']?->format('d M Y, h:i A')],
                ['label' => 'Franchise',               'group' => 'identity', 'value' => $idCardStats['franchise']],
                ['label' => 'Region',                  'group' => 'identity', 'value' => $idCardStats['region']],
                ['label' => 'Status',                  'group' => 'identity', 'value' => $idCardStats['verification_label'], 'render' => 'pill', 'pill_class' => $idCardStats['verification_class']],
                ['label' => 'Activation Date',         'group' => 'identity', 'value' => $idCardStats['activation_date']?->format('d M Y')],
                ['label' => 'Personal Sales Position', 'group' => 'rank', 'value' => $idCardStats['personal_sales_title']],
                ['label' => 'Left Team',               'group' => 'team', 'value' => \App\Modules\Shared\Support\IndianNumber::format((int) $idCardStats['left_team'])],
                ['label' => 'Right Team',              'group' => 'team', 'value' => \App\Modules\Shared\Support\IndianNumber::format((int) $idCardStats['right_team'])],
                ['label' => 'Total Team',              'group' => 'team', 'value' => \App\Modules\Shared\Support\IndianNumber::format((int) $idCardStats['total_team'])],
                ['label' => 'Highest Rank',            'group' => 'rank', 'value' => $idCardStats['highest_rank']],
                ['label' => 'Current Rank',            'group' => 'rank', 'value' => $idCardStats['current_rank']],
                ['label' => 'Total Personal BV',       'group' => 'money', 'value' => $idCardStats['total_personal_bv']],
                ['label' => 'Total Withdrawal Income', 'group' => 'money', 'value' => $idCardStats['total_withdrawal_income']],
            ];
        @endphp

        @foreach($rows as $row)
            @php $gt = $colorful ? $groupTones[$row['group']] : null; @endphp
            <dt class="text-gray-700 break-words {{ $gt ? 'flex items-center gap-2 rounded-l-lg pl-2 py-1 '.$gt['band'] : '' }}">
                @if($gt)<span class="h-2 w-2 shrink-0 rounded-full {{ $gt['dot'] }}" aria-hidden="true"></span>@endif
                {{ $row['label'] }}
            </dt>
            <dd class="font-medium text-gray-900 truncate self-stretch {{ $row['class'] ?? '' }} {{ $gt ? 'rounded-r-lg pr-2 py-1 '.$gt['band'] : '' }}">
                @if(($row['render'] ?? null) === 'pill')
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold border {{ $row['pill_class'] ?? '' }}">
                        {{ $row['value'] }}
                    </span>
                @elseif($row['value'] === null || $row['value'] === '')
                    <span class="text-gray-600">—</span>
                @else
                    {{ $row['value'] }}
                @endif
            </dd>
        @endforeach
    </dl>

    {{-- RIGHT: passport photo block ───────────────────────────────────── --}}
    <div class="sm:place-self-start">
        @if($readonly)
            {{-- Read-only — Details popup viewing another distributor.
                 No form, no file input, no "change" affordance. --}}
            <div class="w-32 sm:w-32 lg:w-40">
                @if($idPhotoUrl)
                    <img src="{{ $idPhotoUrl }}"
                        alt="ID photo"
                        class="w-full aspect-[3/4] object-cover rounded-lg border-2 border-gray-200">
                @else
                    <div class="w-full aspect-[3/4] rounded-lg border-2 border-dashed border-gray-200 bg-gray-50/40 flex flex-col items-center justify-center text-center p-3">
                        <x-lucide-camera class="w-8 h-8 text-gray-600 mb-1.5" />
                        <span class="text-[11px] text-gray-700 leading-tight">No photo uploaded</span>
                    </div>
                @endif
            </div>
        @else
            {{-- Interactive — dashboard owner uploads or changes their own photo. --}}
            <form method="POST"
                action="{{ route('profile.id-photo.update') }}"
                enctype="multipart/form-data"
                id="idPhotoForm"
                class="w-32 sm:w-32 lg:w-40">
                @csrf
                <label for="idPhotoInput" class="block cursor-pointer group" title="Click to upload or change your ID photo">
                    @if($idPhotoUrl)
                        <img src="{{ $idPhotoUrl }}"
                            alt="Your ID photo"
                            class="w-full aspect-[3/4] object-cover rounded-lg border-2 border-gray-200 group-hover:border-brand-300 transition-colors">
                        <span class="block mt-1.5 text-[11px] text-center text-brand-700 group-hover:text-brand-800 font-medium">
                            Change photo
                        </span>
                    @else
                        <div class="w-full aspect-[3/4] rounded-lg border-2 border-dashed border-gray-300 group-hover:border-brand-400 group-hover:bg-brand-50/40 transition-colors flex flex-col items-center justify-center text-center p-3">
                            <x-lucide-camera class="w-8 h-8 text-gray-600 group-hover:text-brand-800 mb-1.5" />
                            <span class="text-[11px] text-gray-800 group-hover:text-brand-800 font-medium leading-tight">
                                Upload ID photo
                            </span>
                            <span class="text-[11px] text-gray-600 mt-0.5">Passport style</span>
                        </div>
                    @endif
                </label>
                {{-- No auto-submit: app.js intercepts "change", opens the crop
                     modal, and submits the cropped JPEG. With JS off the form
                     still works as a plain upload. --}}
                <input id="idPhotoInput"
                    name="photo"
                    type="file"
                    accept="image/jpeg,image/png"
                    class="hidden">
                <noscript>
                    <button type="submit" class="mt-1.5 w-full text-[11px] text-center text-brand-700 font-medium">Upload</button>
                </noscript>
            </form>
            @error('photo')
                <p class="mt-2 text-xs text-red-600 max-w-[160px]">{{ $message }}</p>
            @enderror

            {{-- ── Crop / pan / zoom modal (Cropper.js, wired in app.js) ──────── --}}
            <div id="idPhotoCropModal"
                class="hidden fixed inset-0 z-50 items-center justify-center bg-slate-900/60 p-4"
                role="dialog" aria-modal="true" aria-label="Crop your ID photo">
                <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
                    <div class="px-5 py-4 border-b border-gray-200">
                        <p class="text-base font-bold text-gray-900">Crop your ID photo</p>
                        <p class="text-xs text-gray-600 mt-0.5">Drag to reposition, use the slider to zoom. The frame is passport style (3:4).</p>
                    </div>
                    <div class="bg-gray-900/5 p-4">
                        <div class="max-h-[60vh]">
                            {{-- Cropper replaces this img with its own canvas UI. --}}
                            <img id="idPhotoCropImage" alt="" class="block max-w-full">
                        </div>
                        <div class="flex items-center gap-3 mt-4">
                            <span class="text-xs text-gray-600 w-12 shrink-0">Zoom</span>
                            <input id="idPhotoCropZoom" type="range" min="-0.5" max="1.5" step="0.01" value="0"
                                class="flex-1 accent-brand-600">
                        </div>
                        {{-- Rotate ("360 adjustment") — drag to pan, slider to zoom,
                             these to rotate the photo in 90° steps. --}}
                        <div class="flex items-center gap-2 mt-3">
                            <span class="text-xs text-gray-600 w-12 shrink-0">Rotate</span>
                            <button type="button" id="idPhotoRotateLeft" title="Rotate left 90°"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-medium text-gray-700 transition-colors">
                                <x-lucide-undo-2 class="w-4 h-4" />
                                Left
                            </button>
                            <button type="button" id="idPhotoRotateRight" title="Rotate right 90°"
                                class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-xs font-medium text-gray-700 transition-colors">
                                <x-lucide-redo-2 class="w-4 h-4" />
                                Right
                            </button>
                        </div>
                    </div>
                    <div class="px-5 py-4 border-t border-gray-200 flex items-center justify-between gap-3">
                        <button type="button" id="idPhotoCropCancel"
                            class="px-4 py-2.5 rounded-lg border border-gray-300 bg-white hover:bg-gray-50 text-gray-700 text-sm font-semibold transition-colors">
                            Cancel
                        </button>
                        <button type="button" id="idPhotoCropSave"
                            class="flex-1 rounded-lg bg-brand-700 hover:bg-brand-800 text-white font-bold py-2.5 text-sm transition-colors">
                            Save photo
                        </button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
