{{-- Google Analytics 4 (gtag.js).
     Emitted only when GOOGLE_ANALYTICS_ID is configured
     (config('arovolife.analytics.google_id')). Skipped on the admin
     console — internal staff actions should not inflate public-funnel metrics,
     and admin pages render PII we don't want sent to GA.

     INTENTIONALLY NOT included on auth/reset-password — the reset token is
     in the URL path and GA4's page_location would ship it to Google. --}}
@php $googleAnalyticsId = config('arovolife.analytics.google_id'); @endphp
@if(!empty($googleAnalyticsId))
<script>
(function () {
    'use strict';

    var GA_ID = {!! json_encode($googleAnalyticsId) !!};

    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    window.gtag('config', GA_ID, { anonymize_ip: true });

    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
    document.head.appendChild(s);
})();
</script>
@endif
