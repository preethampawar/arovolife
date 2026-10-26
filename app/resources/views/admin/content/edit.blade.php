@extends('admin.layouts.admin')
@section('title', 'Edit — ' . $page->title)
@section('heading', 'Edit: ' . $page->title)

@section('content')

<div class="max-w-4xl">

    @if($policyPage)
    <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 mb-6 text-sm text-amber-900" role="note">
        <p class="font-semibold mb-1 flex items-center gap-1.5">{{ svg('lucide-triangle-alert', 'w-4 h-4 shrink-0', ['aria-hidden' => 'true']) }} This is a legal page. Edit with care.</p>
        <ul class="list-disc pl-5 space-y-1 leading-relaxed">
            <li>If the page is published, your changes are live for everyone as soon as you save.</li>
            <li>Keep the page's headings and tables. Use the buttons above the text to add them. Colours, fonts and images pasted in from elsewhere are removed when you save.</li>
            <li>Once you save a change here, this page is the master copy. Publishing from the code (<code>content:publish</code>, a re-seed or a platform reset) no longer overwrites it.</li>
            @if($isPlanPage)
            @if($planNoticeOn)
            <li><strong>Every distributor will be shown the change when they next sign in, and emailed after 3 days if they have not confirmed.</strong> A change applies from the start of the day after it is published (saved as Published), unless the change gives a later date, and never to the past (plan §12).</li>
            @else
            <li class="font-semibold text-red-800">The plan-change notice is switched off, so distributors are NOT shown or emailed changes you publish here. Ask the developer to switch it on before you publish a change.</li>
            @endif
            <li>For each change, do two things. First, put today's date in the version line at the very bottom, written exactly like <em>v2026-11-02</em> (<em>disclosure version: v2026-11-02</em>). Second, at the top of the "Changes to this plan, by version" list, add a <strong>Small heading</strong> containing exactly that same text, <em>v2026-11-02</em>, and under it the change in plain words. If a change was already published today, use tomorrow's date for the next one; it takes effect from tomorrow anyway. If the two do not match, distributors are not told and the save shows a red warning.</li>
            <li>Change the plan settings themselves (rates, slabs, caps) only at a week or month end, after that period's bonus run, and save this page no later than that change.</li>
            @endif
        </ul>
    </div>
    @else
    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 mb-6 text-sm text-blue-900">
        <p class="font-semibold mb-1">Edit content page</p>
        <p class="leading-relaxed">Update the title and body of this public page. Changes are live immediately once saved if the page is published.</p>
    </div>
    @endif

    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('admin.content.index') }}" class="text-sm text-gray-600 hover:text-gray-900">{{ svg('lucide-arrow-left', 'w-3.5 h-3.5 inline-block align-[-2px]', ['aria-hidden' => 'true']) }} Back to Content Pages</a>
        @if($page->status === 'published')
        <a href="{{ route('content.show', $page->slug) }}" target="_blank"
           class="text-sm text-brand-700 hover:text-brand-800">View live ↗</a>
        @endif
    </div>

    <form method="POST" action="{{ route('admin.content.update', $page) }}" class="bg-white rounded-2xl border border-gray-200 p-8"
        data-confirm="Save these changes?"
        data-confirm-title="Confirm save"
        data-confirm-impact="{{ $isPlanPage ? ($planNoticeOn ? 'Saves the Compensation Plan. If it is published and you changed the text, every distributor is shown the change at their next sign-in, and it applies from the start of tomorrow unless it gives a later date.' : 'Saves the Compensation Plan. The plan-change notice is switched off, so distributors will NOT be told about a change you publish.') : ($policyPage ? 'Saves this legal page. If it is published, the live page updates immediately, and this copy becomes the master that code publishing no longer overwrites.' : 'Saves your edits to this content page. If it is published, the live page updates immediately. You can edit it again later.') }}">
        @method('PATCH')
        @include('admin.content._form', ['submitLabel' => 'Save Changes'])
    </form>

    @if($page->status !== 'archived')
    <form method="POST" action="{{ route('admin.content.destroy', $page) }}" class="mt-8 bg-white rounded-2xl border border-red-200 p-6"
        data-confirm="Archive this page?"
        data-confirm-title="Confirm archive"
        data-confirm-impact="The page is hidden from the public but kept in the database and audit log. This is reversible — the row is not deleted.">
        @csrf
        @method('DELETE')
        <h3 class="text-sm font-semibold text-red-700 mb-2">Archive this page</h3>
        <p class="text-xs text-gray-600 mb-4">Archived pages are hidden from the public but kept in the database and audit log. This does not delete the row.</p>
        <button type="submit"
            class="px-4 py-2 rounded-lg bg-white border border-red-200 text-red-700 hover:bg-red-50 text-sm font-medium transition-colors">
            Archive Page
        </button>
    </form>
    @endif

</div>

@endsection
