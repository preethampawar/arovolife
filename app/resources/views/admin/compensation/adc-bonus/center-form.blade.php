@extends('admin.layouts.admin')
@section('title', $center ? 'Edit Center' : 'Add Center')
@section('heading', $center ? 'Edit Center' : 'Add Arete Development Center')

@section('content')

<div class="flex items-center gap-3 mb-6">
    <a href="{{ route('admin.compensation.adc-bonus.centers.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← All centers</a>
</div>

<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-6 max-w-xl">
    <div class="mb-4 text-sm text-blue-800 bg-blue-50 border border-blue-200 rounded-lg px-4 py-3">
        Add a company-approved Arete Development Center and assign it to the distributor who will earn the ADC Bonus.
    </div>

    <form method="POST" action="{{ route('admin.compensation.adc-bonus.centers.store') }}" class="space-y-4">
        @csrf

        @if($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-700">
            <ul class="list-disc list-inside space-y-1">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Center name <span class="text-red-500">*</span></label>
            <input type="text" name="name" value="{{ old('name') }}" required maxlength="200"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Location</label>
            <input type="text" name="location" value="{{ old('location') }}" maxlength="300"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
                Assigned distributor ADN <span class="text-red-500">*</span>
                <x-help-tip text="Enter the ADN of the distributor who will receive the ADC Bonus for this center." />
            </label>
            <input type="text" name="assigned_adn" value="{{ old('assigned_adn') }}" required
                   placeholder="e.g. ARV1000001"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm font-mono focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Approval date</label>
            <input type="date" name="approved_at" value="{{ old('approved_at') }}"
                   class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Notes</label>
            <textarea name="notes" rows="3"
                      class="w-full border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-400">{{ old('notes') }}</textarea>
        </div>

        <div class="pt-2 flex gap-3">
            <button type="submit" class="px-5 py-2 bg-brand-500 text-white text-sm rounded-lg hover:bg-brand-600 transition-colors">
                Save Center
            </button>
            <a href="{{ route('admin.compensation.adc-bonus.centers.index') }}"
               class="px-5 py-2 border border-gray-200 text-gray-600 text-sm rounded-lg hover:bg-gray-50 transition-colors">
                Cancel
            </a>
        </div>
    </form>
</div>

@endsection
