{{-- The legal pages as they read on the site: the editor surface and the plan-change preview share it. --}}
@push('styles')
<style>
    .policy-editor-surface, .policy-editor-surface:focus, .policy-editor-surface:focus-visible { min-height: 360px; outline: none; box-shadow: none; }
    .policy-editor-surface > :first-child { margin-top: 0; }
    .policy-editor-surface h1 { font-size: 1.625rem; font-weight: 700; margin: 1.75rem 0 0.75rem; color: #111827; }
    .policy-editor-surface h2 { font-size: 1.5rem; font-weight: 700; margin: 1.25rem 0 0.5rem; color: #111827; }
    .policy-editor-surface h3 { font-size: 1.2rem; font-weight: 600; margin: 1rem 0 0.5rem; color: #111827; }
    .policy-editor-surface h4 { font-size: 1.05rem; font-weight: 600; margin: 0.875rem 0 0.375rem; color: #111827; }
    .policy-editor-surface p  { margin: 0.75rem 0; line-height: 1.65; color: #374151; }
    .policy-editor-surface ul { list-style: disc; padding-left: 1.5rem; margin: 0.75rem 0; }
    .policy-editor-surface ol { list-style: decimal; padding-left: 1.5rem; margin: 0.75rem 0; }
    .policy-editor-surface li { margin: 0.25rem 0; color: #374151; }
    .policy-editor-surface li p, .policy-editor-surface td p, .policy-editor-surface th p { margin: 0; }
    .policy-editor-surface a  { color: #127a70; text-decoration: underline; }
    .policy-editor-surface blockquote { border-left: 3px solid #2ab3a6; padding-left: 1rem; margin: 1rem 0; color: #4b5563; font-style: italic; }
    .policy-editor-surface strong { font-weight: 600; color: #111827; }
    .policy-editor-surface code { background: #f3f4f6; padding: 0.1rem 0.3rem; border-radius: 0.25rem; font-size: 0.875em; }
    .policy-editor-surface hr { margin: 1.5rem 0; border-color: #e5e7eb; }
    .policy-editor-surface table { width: 100%; border-collapse: collapse; margin: 1rem 0; font-size: 0.9375rem; table-layout: fixed; }
    .policy-editor-surface th, .policy-editor-surface td { border: 1px solid #e5e7eb; padding: 0.5rem 0.75rem; text-align: left; vertical-align: top; color: #374151; position: relative; }
    .policy-editor-surface th { background: #f9fafb; font-weight: 600; color: #111827; }
    .policy-editor-surface .selectedCell { background: #e6f6f4; }
</style>
@endpush
