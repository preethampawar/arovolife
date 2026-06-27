{{-- Boolean toggle switch.
     Inputs: $fieldId, $key, $value (string 'true'|'false'), $readOnly (bool)
     The hidden <input name="value"> holds the value sent on submit. The
     toggle button flips it via the data-toggle-switch script in the parent
     view, which then submits the form. We do not depend on checkbox
     semantics because the spec wants a polished toggle UI. --}}
@php
    $isOn = $value === 'true' || $value === '1';
    $toggleLabel = $label ?? $key;
    $confirmQuestion = 'Turn the ' . $toggleLabel . ' setting ' . ($isOn ? 'off' : 'on') . '?';
@endphp
<form method="POST" action="{{ route('admin.settings.update', $key) }}"
      data-setting-form
      data-confirm="{{ $confirmQuestion }}"
      data-confirm-title="Confirm setting change"
      data-confirm-impact="Changes a platform-wide setting that affects all users on arovolife. The change is audit-logged and can be edited again later."
      class="flex items-center justify-end">
    @csrf
    <input type="hidden" name="value" value="{{ $isOn ? 'true' : 'false' }}">
    <button type="button"
            id="{{ $fieldId }}"
            role="switch"
            aria-checked="{{ $isOn ? 'true' : 'false' }}"
            aria-label="Toggle {{ $key }}"
            data-toggle-switch
            data-toggle-label="{{ $toggleLabel }}"
            @disabled($readOnly)
            class="relative inline-flex h-6 w-11 shrink-0 cursor-pointer rounded-full border-2 border-transparent
                   transition-colors duration-200 ease-in-out
                   focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2
                   disabled:cursor-not-allowed disabled:opacity-50
                   {{ $isOn ? 'bg-brand-500' : 'bg-gray-300' }}">
        <span class="sr-only">{{ $isOn ? 'On' : 'Off' }}</span>
        <span aria-hidden="true"
              class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0
                     transition duration-200 ease-in-out
                     {{ $isOn ? 'translate-x-5' : 'translate-x-0' }}"></span>
    </button>
    {{-- Fallback Save button so users without JS can still flip the value.
         Browser form-data rules: a button with name+value overrides the
         hidden input of the same name, so clicking this posts the inverse. --}}
    <noscript>
        <button type="submit" name="value" value="{{ $isOn ? 'false' : 'true' }}"
                class="ml-2 px-3 py-1 text-xs rounded bg-brand-500 text-white">
            {{ $isOn ? 'Turn off' : 'Turn on' }}
        </button>
    </noscript>
</form>
