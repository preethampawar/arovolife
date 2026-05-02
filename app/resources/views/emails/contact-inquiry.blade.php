@extends('emails.layouts.branded', ['subject' => 'New contact form submission — '.$inquiry->purpose, 'previewText' => 'A new contact form submission has arrived from '.$inquiry->name.'.'])

@section('content')

<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;">
            <p class="ar-h1" style="margin: 0 0 6px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                New contact form submission
            </p>
            <p style="margin: 0 0 22px 0; font-size: 14px; line-height: 22px; color: #6b7280;">
                Submitted {{ $inquiry->created_at->format('d M Y, H:i') }} IST.
                Reason: <span style="color: #1f2937; font-weight: 600;">{{ $reasonLabel }}</span>.
            </p>
        </td>
    </tr>

    {{-- Key/value table — purpose, name, email, phone --}}
    <tr>
        <td>
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="border: 1px solid #e5e7eb; border-radius: 6px; overflow: hidden;">
                @foreach([
                    'Purpose'       => str_replace('_', ' ', $inquiry->purpose),
                    'Full name'     => $inquiry->name,
                    'Email'         => $inquiry->email,
                    'Phone'         => $inquiry->phone_e164,
                    'Address'       => $inquiry->address,
                ] as $label => $value)
                <tr>
                    <td valign="top" width="120" style="padding: 10px 14px; background-color: #f9fafb; border-bottom: 1px solid #f3f4f6; font-size: 12px; line-height: 18px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">
                        {{ $label }}
                    </td>
                    <td valign="top" style="padding: 10px 14px; border-bottom: 1px solid #f3f4f6; font-size: 14px; line-height: 20px; color: #111827; word-break: break-word;">
                        {{ $value }}
                    </td>
                </tr>
                @endforeach
                <tr>
                    <td valign="top" width="120" style="padding: 10px 14px; background-color: #f9fafb; font-size: 12px; line-height: 18px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">
                        Privacy consent
                    </td>
                    <td valign="top" style="padding: 10px 14px; font-size: 13px; line-height: 20px; color: #111827;">
                        @if($inquiry->privacy_consent_at)
                            <span style="color: #3f9228; font-weight: 600;">&#10003; Recorded</span> {{ $inquiry->privacy_consent_at->format('d M Y H:i') }}
                        @else
                            <span style="color: #b91c1c; font-weight: 600;">&#9888; Missing</span>
                        @endif
                    </td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- Message body --}}
    <tr>
        <td style="padding-top: 22px;">
            <p style="margin: 0 0 8px 0; font-size: 12px; line-height: 18px; color: #6b7280; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px;">
                Message
            </p>
            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="border: 1px solid #e5e7eb; background-color: #f9fafb; border-radius: 6px;">
                <tr>
                    <td style="padding: 14px 16px; font-size: 14px; line-height: 22px; color: #111827; white-space: pre-wrap;">{{ $inquiry->message }}</td>
                </tr>
            </table>
        </td>
    </tr>

    {{-- CTA --}}
    <tr>
        <td style="padding-top: 22px;">
            @include('emails.partials.button', [
                'url'   => $adminUrl,
                'label' => 'Open in admin inbox',
            ])
        </td>
    </tr>

    <tr>
        <td style="padding-top: 22px;">
            <p style="margin: 0; font-size: 12px; line-height: 18px; color: #9ca3af;">
                Inquiry ID #{{ $inquiry->id }} &middot; from IP {{ $inquiry->ip }}
            </p>
        </td>
    </tr>
</table>

@endsection
