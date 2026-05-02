@if(session('impersonator_id'))
    <div class="bg-sunrise-500 text-white text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2 flex flex-wrap items-center justify-between gap-2">
            <p class="font-medium">
                <span class="inline-flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                    Admin impersonation —
                </span>
                you're viewing as <strong>{{ auth()->user()->full_name ?? auth()->user()->email }}</strong>.
            </p>
            <form method="POST" action="{{ route('admin.impersonate.stop') }}">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-white text-sunrise-700 text-xs font-bold hover:bg-sunrise-50 transition-colors">
                    Return to admin →
                </button>
            </form>
        </div>
    </div>
@endif
