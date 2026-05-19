@extends('admin.layouts.admin')
@section('title', 'Feature flags')
@section('heading', 'Feature flags')

@section('content')
<div class="max-w-3xl">
    <p class="text-sm text-gray-600 mb-6">
        Runtime toggles for rollouts and killswitches. Every change writes an
        <code class="px-1 rounded bg-gray-100 text-gray-800">audit_log</code>
        entry of action
        <code class="px-1 rounded bg-gray-100 text-gray-800">feature_flag.toggled</code>.
    </p>

    @if (session('status'))
        <div class="mb-6 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
            {{ session('status') }}
        </div>
    @endif

    <div class="space-y-4">
        @foreach ($flags as $key => $flag)
            <div class="rounded-2xl border border-gray-200 bg-white p-5 flex items-start justify-between">
                <div class="pr-4">
                    <div class="flex items-center gap-2 mb-1">
                        <code class="text-xs text-gray-500">{{ $key }}</code>
                        @if ($flag['active'])
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800">Active</span>
                        @else
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-amber-100 text-amber-800">Inactive</span>
                        @endif
                    </div>
                    <div class="text-base font-medium text-gray-900">{{ $flag['label'] }}</div>
                    <div class="text-sm text-gray-600 mt-1">{{ $flag['description'] }}</div>
                </div>
                <form method="POST" action="{{ route('admin.feature-flags.toggle', $key) }}" class="shrink-0">
                    @csrf
                    @if ($flag['active'])
                        <input type="hidden" name="action" value="deactivate">
                        <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-amber-600 text-white hover:bg-amber-700 font-semibold transition-colors">
                            Deactivate
                        </button>
                    @else
                        <input type="hidden" name="action" value="activate">
                        <button type="submit" class="px-3 py-1.5 text-sm rounded-md bg-green-600 text-white hover:bg-green-700 font-semibold transition-colors">
                            Activate
                        </button>
                    @endif
                </form>
            </div>
        @endforeach
    </div>
</div>
@endsection
