@extends('emails.layouts.branded', [
    'subject'      => 'Changes to the arovolife Compensation Plan — please read',
    'previewText'  => 'This email is your written notice of changes to the arovolife Compensation Plan.',
    'accentColor'  => '#f5922a',
    'accentDarker' => '#c46e0e',
])

@section('content')
<table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
    <tr>
        <td>
            <p class="ar-h1" style="margin: 0 0 18px 0; font-size: 22px; line-height: 28px; font-weight: 700; color: #111827;">
                Changes to the Compensation Plan
            </p>
            <p style="margin: 0 0 14px 0; font-size: 15px; line-height: 24px; color: #374151;">
                Dear {{ $name !== '' ? $name : 'Distributor' }} (ADN
                <span style="font-family: 'SFMono-Regular', Menlo, Consolas, monospace; color: #c46e0e; font-weight: 600;">{{ $adn }}</span>),
            </p>
            <p style="margin: 0 0 18px 0; font-size: 15px; line-height: 24px; color: #374151;">
                This email is your written notice of changes to the arovolife Compensation Plan, under section 12 of the plan.
            </p>

            <p style="margin: 0 0 8px 0; font-size: 16px; line-height: 24px; font-weight: 700; color: #111827;">What has changed</p>
            @forelse($entries as $entry)
                <p style="margin: 12px 0 4px 0; font-size: 14px; line-height: 22px; font-weight: 700; color: #111827;">{{ $entry['label'] }}</p>
                <div style="font-size: 14px; line-height: 22px; color: #374151;">{!! $entry['html'] !!}</div>
            @empty
                <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
                    The Compensation Plan has changed. Please read the full plan at the link below.
                </p>
            @endforelse

            <p style="margin: 18px 0 8px 0; font-size: 16px; line-height: 24px; font-weight: 700; color: #111827;">When the changes apply</p>
            <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
                These changes already apply. Each one applies from the day after it was published on the plan page, unless it gives a later date, and only to sales and bonus periods from then on. A change never applies to the past.
            </p>

            <p style="margin: 18px 0 8px 0; font-size: 16px; line-height: 24px; font-weight: 700; color: #111827;">What you can do</p>
            <ul style="margin: 0 0 14px 18px; padding: 0; font-size: 14px; line-height: 22px; color: #374151;">
                <li>Read the full plan: <a href="{{ url('/p/compensation') }}" style="color: #c46e0e;">{{ url('/p/compensation') }}</a></li>
                <li>If you do not agree, you can end your agreement with arovolife by giving us written notice, under section 12 of the Direct Seller Agreement. You can send it by email to <a href="mailto:{{ config('arovolife.support_email') }}" style="color: #c46e0e;">{{ config('arovolife.support_email') }}</a> or through <a href="{{ url('/contact-us') }}" style="color: #c46e0e;">Contact us</a>, or hand it in or send it by registered post to our registered office, as section 22 of the Agreement provides. If you end your agreement, you can return products in saleable condition within 30 days under the buy-back terms in section 8 of the Agreement.</li>
                @if($coolingOffEndsAt !== null)
                    <li>You have 30 days from the date of approval to cancel your Direct Seller agreement and receive a full refund of any product purchases made during this period. This is your cooling-off period under the Consumer Protection (Direct Selling) Rules, 2021. Your cooling-off period ends on {{ $coolingOffEndsAt }}.</li>
                @endif
            </ul>

            <p style="margin: 0 0 14px 0; font-size: 14px; line-height: 22px; color: #374151;">
                If you keep your account, these changes apply to you from the day they take effect,. If you have a question or a complaint, use Grievance Redressal:
                <a href="{{ url('/p/grievance') }}" style="color: #c46e0e;">{{ url('/p/grievance') }}</a>.
            </p>

            @include('emails.partials.button', [
                'url'   => url('/p/compensation'),
                'label' => 'Read the full plan',
                'bg'    => '#f5922a',
                'bgD'   => '#c46e0e',
            ])

            <p style="margin: 22px 0 0 0; font-size: 14px; line-height: 22px; color: #374151;">
                Regards,<br>
                <strong>arovolife Private Limited</strong> (CIN U46909TS2026PTC210896)
            </p>
        </td>
    </tr>
</table>
@endsection
