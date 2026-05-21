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
                      upload affordance. Default false. --}}
@php
    $readonly = $readonly ?? false;
@endphp

<div class="grid grid-cols-1 sm:grid-cols-[1fr_140px] lg:grid-cols-[1fr_160px] gap-6">
    {{-- LEFT: 15-row stats list ───────────────────────────────────────── --}}
    <dl class="grid grid-cols-[140px_1fr] sm:grid-cols-[160px_1fr] gap-x-3 gap-y-2 text-sm">
        @php
            $rows = [
                ['label' => 'Name',                    'value' => $idCardStats['name']],
                ['label' => 'ID Number',               'value' => $idCardStats['adn'],                'class' => 'font-mono text-brand-600 tracking-wider'],
                ['label' => 'Registration Date',       'value' => $idCardStats['registration_date']?->format('d M Y')],
                ['label' => 'Franchise',               'value' => $idCardStats['franchise']],
                ['label' => 'Region',                  'value' => $idCardStats['region']],
                ['label' => 'Verification',            'value' => $idCardStats['verification_label'], 'render' => 'pill', 'pill_class' => $idCardStats['verification_class']],
                ['label' => 'Activation Date',         'value' => $idCardStats['activation_date']?->format('d M Y')],
                ['label' => 'Personal Sales Position', 'value' => $idCardStats['personal_sales_position']],
                ['label' => 'Left Team',               'value' => number_format((int) $idCardStats['left_team'])],
                ['label' => 'Right Team',              'value' => number_format((int) $idCardStats['right_team'])],
                ['label' => 'Total Team',              'value' => number_format((int) $idCardStats['total_team'])],
                ['label' => 'Highest Rank',            'value' => $idCardStats['highest_rank']],
                ['label' => 'Current Rank',            'value' => $idCardStats['current_rank']],
                ['label' => 'Total Personal BV',       'value' => $idCardStats['total_personal_bv']],
                ['label' => 'Total Withdrawal Income', 'value' => $idCardStats['total_withdrawal_income']],
            ];
        @endphp

        @foreach($rows as $row)
            <dt class="text-gray-700 truncate">{{ $row['label'] }}</dt>
            <dd class="font-medium text-gray-900 truncate {{ $row['class'] ?? '' }}">
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
                        <svg class="w-8 h-8 text-gray-500 mb-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.776 48.776 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/>
                        </svg>
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
                        <span class="block mt-1.5 text-[11px] text-center text-brand-600 group-hover:text-brand-700 font-medium">
                            Change photo
                        </span>
                    @else
                        <div class="w-full aspect-[3/4] rounded-lg border-2 border-dashed border-gray-300 group-hover:border-brand-400 group-hover:bg-brand-50/40 transition-colors flex flex-col items-center justify-center text-center p-3">
                            <svg class="w-8 h-8 text-gray-600 group-hover:text-brand-500 mb-1.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.827 6.175A2.31 2.31 0 0 1 5.186 7.23c-.38.054-.757.112-1.134.175C2.999 7.58 2.25 8.507 2.25 9.574V18a2.25 2.25 0 0 0 2.25 2.25h15A2.25 2.25 0 0 0 21.75 18V9.574c0-1.067-.75-1.994-1.802-2.169a47.865 47.865 0 0 0-1.134-.175 2.31 2.31 0 0 1-1.64-1.055l-.822-1.316a2.192 2.192 0 0 0-1.736-1.039 48.776 48.776 0 0 0-5.232 0 2.192 2.192 0 0 0-1.736 1.039l-.821 1.316Z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 12.75a4.5 4.5 0 1 1-9 0 4.5 4.5 0 0 1 9 0ZM18.75 10.5h.008v.008h-.008V10.5Z"/>
                            </svg>
                            <span class="text-[11px] text-gray-800 group-hover:text-brand-700 font-medium leading-tight">
                                Upload ID photo
                            </span>
                            <span class="text-[11px] text-gray-600 mt-0.5">Passport style</span>
                        </div>
                    @endif
                </label>
                <input id="idPhotoInput"
                    name="photo"
                    type="file"
                    accept="image/jpeg,image/png"
                    class="hidden"
                    onchange="document.getElementById('idPhotoForm').submit();">
            </form>
            @error('photo')
                <p class="mt-2 text-xs text-red-600 max-w-[160px]">{{ $message }}</p>
            @enderror
        @endif
    </div>
</div>
