{{-- Front face of the arovolife Family Card — shared by the dashboard tile
     (compact) and the printable page. Same frame, waves and logo as the
     membership card. Never shows family members' names: the card carries
     only the fixed "1 + 5" (client decision 2026-10-11, R-117). The
     not-an-insurance-policy line sits on the FRONT on purpose — a "1 + 5"
     card reads like a family-floater e-card without it. --}}
@php $compact = $compact ?? false; @endphp
<div class="mc-card relative overflow-hidden w-full {{ $compact ? '' : 'max-w-[540px]' }} aspect-[1.586/1]
            {{ $compact ? 'rounded-xl border-[3px] p-4' : 'rounded-2xl border-4 p-6' }} border-brand-400
            bg-gradient-to-br from-white via-brand-50/60 to-leaf-50/40
            shadow-xl shadow-brand-500/10 ring-1 ring-inset ring-brand-200/40
            flex flex-col">
    <span class="arovo-watermark pointer-events-none select-none absolute -right-6 -bottom-6
                 {{ $compact ? 'text-[4.5rem]' : 'text-[7rem]' }} font-bold text-brand-100/40 leading-none tracking-tight rotate-[-8deg]">arovolife</span>
    <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 bottom-0 {{ $compact ? 'h-12' : 'h-20' }}"
         viewBox="-40 0 620 80" preserveAspectRatio="none" aria-hidden="true">
        <path d="M-40,38 C80,55 200,20 280,38 C380,58 480,18 580,38 L580,90 L-40,90 Z"
              fill="var(--color-leaf-200)" opacity="0.55"/>
        <path d="M-40,52 C100,68 220,32 320,50 C420,68 500,30 580,50 L580,90 L-40,90 Z"
              fill="var(--color-brand-300)" opacity="0.42"/>
    </svg>
    <svg class="arovo-wave pointer-events-none select-none absolute -left-6 -right-6 top-0 {{ $compact ? 'h-9' : 'h-14' }}"
         viewBox="-40 0 620 56" preserveAspectRatio="none" aria-hidden="true">
        <path d="M-40,0 L580,0 L580,36 C460,16 340,52 270,34 C200,16 80,52 -40,36 Z"
              fill="var(--color-leaf-200)" opacity="0.55"/>
        <path d="M-40,0 L580,0 L580,22 C450,6 330,30 260,18 C190,6 80,30 -40,22 Z"
              fill="var(--color-brand-300)" opacity="0.42"/>
    </svg>
    <div class="relative flex-1 flex items-center {{ $compact ? 'gap-3' : 'gap-5' }}">
        <div class="w-[34%] shrink-0 flex items-center justify-center">
            <img src="{{ asset('assets/arovolife-logos/arovolife-blue-logo.png') }}" alt="arovolife"
                 class="{{ $compact ? 'max-h-12' : 'max-h-20' }} w-auto object-contain">
        </div>
        <div class="flex-1 min-w-0">
            <h2 class="{{ $compact ? 'text-sm mb-2' : 'text-lg sm:text-xl mb-3' }} font-bold text-brand-700 border-b-2 border-brand-200 pb-1">arovolife Family Card</h2>
            <dl class="{{ $compact ? 'space-y-1 text-xs' : 'space-y-1.5 text-sm' }}">
                <div class="flex gap-2">
                    <dt class="text-brand-700 font-semibold shrink-0">ID :</dt>
                    <dd class="font-mono font-medium text-gray-900 tracking-wide">{{ $adn }}</dd>
                </div>
                <div class="flex gap-2 min-w-0">
                    <dt class="text-brand-700 font-semibold shrink-0">Name :</dt>
                    <dd class="font-medium text-gray-900 uppercase leading-tight break-words min-w-0">{{ $name }}</dd>
                </div>
                <div class="flex gap-2">
                    <dt class="text-brand-700 font-semibold shrink-0">Family :</dt>
                    <dd class="font-medium text-gray-900">1 + 5 family members</dd>
                </div>
            </dl>
        </div>
    </div>
    <div class="relative pt-2 border-t border-brand-200/40 text-center">
        <p class="{{ $compact ? 'text-[9px]' : 'text-[10px] sm:text-[11px]' }} text-gray-700 font-medium">
            Not an insurance policy. Gives no health cover or treatment.
        </p>
        @unless($compact)
            <p class="text-[10px] text-gray-600 mt-1 leading-snug">
                +91 96405 61976 <span class="text-gray-600">|</span> support@arovolife.com<br>
                www.arovolife.com
            </p>
        @endunless
    </div>
</div>
