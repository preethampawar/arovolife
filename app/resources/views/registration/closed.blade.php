@extends('layouts.app')

@section('title', 'Registration temporarily closed')

@section('content')
<div class="max-w-xl mx-auto py-16 px-6">
    <div class="rounded-2xl border border-amber-200 bg-amber-50 p-8 text-center">
        <h1 class="text-2xl font-semibold text-amber-900 mb-4">
            Registration is temporarily closed
        </h1>
        <p class="text-sm text-amber-800 mb-6">
            New distributor registration is paused right now. If you were partway
            through the wizard you can continue from where you left off using the
            link we emailed you, or by signing in on the same browser you started in.
        </p>
        <p class="text-sm text-amber-800">
            If you need help, please use the
            <a href="{{ route('contact.show') }}" class="underline">Contact Us</a>
            page and our team will get back to you.
        </p>
    </div>
</div>
@endsection
