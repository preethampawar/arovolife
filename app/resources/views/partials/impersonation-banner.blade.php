@if(session('impersonator_id'))
    <div class="bg-sunrise-800 text-white text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 py-2 flex flex-wrap items-center justify-between gap-2">
            <p class="font-medium">
                <span class="inline-flex items-center gap-1.5">
                    <x-lucide-circle-alert class="w-4 h-4" />
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
