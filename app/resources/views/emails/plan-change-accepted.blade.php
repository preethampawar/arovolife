@extends('emails.layouts.branded', [
    'subject'      => 'You agreed to the arovolife Compensation Plan'.($label !== null ? ', version '.$label : ''),
    'previewText'  => 'Your record of the Compensation Plan changes you agreed to on '.$acceptedAt.'.',
    'accentColor'  => '#f5922a',
    'accentDarker' => '#c46e0e',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Your record of the plan changes you agreed to
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                On {{ $acceptedAt }} you clicked "I have read the changes and agree" for the arovolife Compensation Plan{{ $label !== null ? ', version '.$label : '' }}. Keep this email as your record of the changes you agreed to.
            </p>

            @if($fingerprint !== '')
                <p style="margin: 0 0 18px 0; font-size: 13px; line-height: 20px; color: #6b7280;">
                    Text fingerprint (SHA-256): <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace;">{{ $fingerprint }}</span>
                </p>
            @endif

            <p style="margin: 0 0 8px 0; font-size: 16px; line-height: 24px; font-weight: 700; color: #111827;">The changes</p>
            @forelse($entries as $entry)
                <p style="margin: 12px 0 4px 0; font-size: 14px; line-height: 22px; font-weight: 700; color: #111827;">{{ $entry['label'] }}</p>
                <div style="font-size: 14px; line-height: 22px; color: #374151;">{!! $entry['html'] !!}</div>
            @empty
                <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
                    The full plan is at the link below.
                </p>
            @endforelse

            @include('emails.partials.button', [
                'url'   => url('/p/compensation'),
                'label' => 'Read the full plan',
                'bg'    => '#f5922a',
                'bgD'   => '#c46e0e',
            ])

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>Team arovolife</strong>
            </p>
        </td>
    </tr>
</table>
@endsection
