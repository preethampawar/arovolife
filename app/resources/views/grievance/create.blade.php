<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Raise a grievance — arovolife</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @include('partials._font-size-fouc')
    {{-- No analytics on this page. It is the anonymous-complaint form: sending
         a whistleblower's page view to a third party would undercut the
         anonymity we promise them two paragraphs down. --}}
</head>
<body class="min-h-full text-gray-900 antialiased wizard-stage">

    @include('partials.public-topnav')

    <div class="max-w-2xl mx-auto px-6 py-12 sm:py-16">

        <div class="text-center mb-8 lift-in" style="animation-delay: 60ms;">
            <p class="text-sm font-medium text-brand-700 uppercase tracking-wider mb-3">Grievance redressal</p>
            <h1 class="text-3xl sm:text-4xl font-bold text-gray-900 leading-tight">
                Raise a grievance
            </h1>
        </div>

        {{-- Form-purpose note: what this form is for and what happens next. --}}
        <div class="mb-8 rounded-xl border border-blue-200 bg-blue-50 px-5 py-4 text-sm leading-relaxed text-blue-900">
            <p class="font-semibold mb-1">What this form does</p>
            <p>
                It registers a formal complaint against arovolife and issues you a complaint number
                on the spot. We acknowledge every grievance within <strong>48 hours</strong>, give a first
                substantive response within <strong>5 working days</strong>, and resolve within
                <strong>30 days</strong> — or up to 60 days where a bank, payment gateway or statutory
                authority has to respond, with a progress update to you at least every 15 days.
            </p>
            <p class="mt-2">
                Anyone may use this form — you do not need an arovolife account. The full policy,
                including the escalation route to the National Consumer Helpline and the Central
                Consumer Protection Authority, is at
                <a href="{{ route('content.show', 'grievance') }}" class="underline font-medium">Grievance Redressal Policy</a>.
            </p>
        </div>

        @if ($errors->any())
            <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-5 py-4">
                <p class="text-sm font-semibold text-red-800 mb-2">Please fix the following:</p>
                <ul class="list-disc list-inside space-y-1 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('grievance.store') }}" enctype="multipart/form-data"
              class="space-y-6 rounded-2xl border border-gray-200 bg-white p-6 sm:p-8 shadow-sm"
              data-confirm="Submit this grievance?"
              data-confirm-title="Register a formal complaint"
              data-confirm-impact="A complaint number will be issued and our grievance team will be notified. You will be able to track it from the link we email you.">
            @csrf

            <div>
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1.5">
                    What is this about?
                    <x-help-tip text="Pick the closest match. If nothing fits, choose 'Something else' — the category only decides who picks it up first." />
                </label>
                <select id="category" name="category" required
                        class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                    <option value="">Choose a category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->value }}" @selected(old('category') === $category->value)>
                            {{ $category->label() }}
                        </option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="subject" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Summary
                    <x-help-tip text="One line describing the problem, e.g. 'Refund for order ORD-260801-00123 not received'." />
                </label>
                <input id="subject" name="subject" type="text" maxlength="255" required
                       value="{{ old('subject') }}"
                       class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
            </div>

            <div>
                <label for="body" class="block text-sm font-medium text-gray-700 mb-1.5">
                    What happened?
                    <x-help-tip text="Dates, order or complaint numbers, amounts and names help us resolve faster. Please do not include your full PAN, Aadhaar number, passwords or OTPs." />
                </label>
                <textarea id="body" name="body" rows="7" maxlength="5000" required
                          class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">{{ old('body') }}</textarea>
                <p class="mt-1.5 text-xs text-gray-600">
                    Never include passwords, OTPs or your full Aadhaar number. We will never ask for them.
                </p>
            </div>

            <div>
                <label for="attachments" class="block text-sm font-medium text-gray-700 mb-1.5">
                    Evidence <span class="font-normal text-gray-600">(optional)</span>
                    <x-help-tip text="Screenshots, statements or PDFs that show the problem. Stored privately and visible only to the officers handling your complaint." />
                </label>
                <input id="attachments" name="attachments[]" type="file" multiple
                       accept=".pdf,.jpg,.jpeg,.png,.webp,.heic"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm file:mr-3 file:rounded-md file:border-0 file:bg-brand-50 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-brand-700">
                <p class="mt-1.5 text-xs text-gray-600">
                    Up to {{ $maxAttachments }} files, {{ $maxKilobytes / 1024 }} MB each. PDF or image.
                </p>
            </div>

            <hr class="border-gray-200">

            <div class="rounded-lg bg-gray-50 px-4 py-3">
                {{-- An unchecked checkbox posts nothing, so the hidden field is
                     what makes "not anonymous" an actual value rather than an
                     absence. Without it the server cannot tell "did not tick"
                     from "did not answer". --}}
                <input type="hidden" name="is_anonymous" value="0">
                <label class="flex items-start gap-2.5 text-sm text-gray-700">
                    <input type="checkbox" name="is_anonymous" value="1" @checked(old('is_anonymous'))
                           class="mt-0.5 rounded border-gray-300 text-brand-700 focus:ring-brand-500"
                           onchange="document.getElementById('identity-fields').classList.toggle('opacity-40', this.checked)">
                    <span>
                        <span class="font-medium">File this anonymously.</span>
                        We will investigate as far as the evidence allows, but we will have no way to send you
                        the complaint number, updates or the outcome. Anonymity is from our records — our web
                        server still logs the connection, as any website's does.
                    </span>
                </label>
            </div>

            <div id="identity-fields" class="space-y-6 transition-opacity">
                <div>
                    <label for="name" class="block text-sm font-medium text-gray-700 mb-1.5">Your name</label>
                    <input id="name" name="name" type="text" maxlength="120" value="{{ old('name') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>

                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Email
                        <x-help-tip text="Where we send your complaint number and every update. Also the address you will use to track the complaint." />
                    </label>
                    <input id="email" name="email" type="email" maxlength="255" value="{{ old('email') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>

                <div>
                    <label for="phone" class="block text-sm font-medium text-gray-700 mb-1.5">
                        Mobile <span class="font-normal text-gray-600">(optional)</span>
                    </label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}"
                           class="w-full rounded-lg border-gray-300 text-sm focus:border-brand-500 focus:ring-brand-500">
                </div>
            </div>

            {{-- A notice, not a consent gate. The right to complain under DSR
                 2021 Rule 12 is unconditional, and handling a grievance is a
                 legal obligation under DPDP §7 rather than a consent-based
                 purpose — so refusing a complaint over an unticked box would be
                 both the wrong legal basis and an unlawful obstacle. --}}
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-xs leading-relaxed text-gray-600">
                <p class="mb-1.5 font-semibold text-gray-800">How we use what you write here</p>
                <p>
                    We collect your complaint, your contact details and anything you attach, and use them only
                    to investigate and resolve this grievance and to meet our reporting duties under the
                    Consumer Protection (Direct Selling) Rules 2021. Records are kept for
                    <strong>7 years</strong> from closure and then deleted. They are shared only with the
                    officers, vendors and authorities who need them — never for marketing.
                </p>
                <p class="mt-1.5">
                    Our Data Protection Officer is at
                    <a href="mailto:dpo@arovolife.com" class="underline">dpo@arovolife.com</a>. You may
                    complain to the Data Protection Board of India if you are unhappy with how we handle your
                    data. Full detail: <a href="{{ route('content.show', 'privacy') }}" class="underline">Privacy Policy</a>.
                </p>
                <label class="mt-2.5 flex items-start gap-2.5 text-gray-700">
                    <input type="checkbox" name="privacy_notice_read" value="1" @checked(old('privacy_notice_read'))
                           class="mt-0.5 rounded border-gray-300 text-brand-700 focus:ring-brand-500">
                    <span>I have read this notice. <span class="text-gray-600">(Optional — your complaint is accepted either way.)</span></span>
                </label>
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-brand-700 px-5 py-3 text-sm font-semibold text-white hover:bg-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-400">
                Submit grievance
            </button>
        </form>

        <p class="mt-6 text-center text-sm text-gray-600">
            Already filed one?
            <a href="{{ route('grievance.track') }}" class="font-medium text-brand-700 underline">Track your grievance</a>.
        </p>

        <div class="mt-10 rounded-xl border border-gray-200 bg-gray-50 px-5 py-4 text-sm text-gray-700">
            <p class="font-semibold mb-1.5">Other ways to reach us</p>
            <p>Email <a href="mailto:grievance@arovolife.com" class="underline">grievance@arovolife.com</a>,
               call <a href="tel:+918886662949" class="underline">+91 88866 62949</a> (10:00–18:00 IST, Mon–Sat),
               or write to the Grievance Officer at our registered office. Every channel lands in the same tracker.</p>
        </div>
    </div>

    <x-confirm-modal />
</body>
</html>
