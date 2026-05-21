{{-- Google Analytics 4 (gtag.js). Emitted only when the measurement ID
     is configured in the environment (GOOGLE_ANALYTICS_ID env var,
     surfaced via config('arovolife.analytics.google_id')). Skipped on the
     admin console — internal staff actions shouldn't inflate public-funnel
     metrics, and admin pages render PII we don't want sent to GA.

     DPDP NOTE: gtag stores IP + device fingerprint, which qualify as
     personal data under the DPDP Act 2023. Before public launch this
     partial should be wrapped in a cookie-consent gate that defers
     `dataLayer.push` until the user has explicitly accepted analytics
     cookies. Until then, the snippet ships unconditionally on environments
     that set GOOGLE_ANALYTICS_ID. --}}
@php $googleAnalyticsId = config('arovolife.analytics.google_id'); @endphp
@if(!empty($googleAnalyticsId))
<script async src="https://www.googletagmanager.com/gtag/js?id={{ $googleAnalyticsId }}"></script>
<script>
    window.dataLayer = window.dataLayer || [];
    function gtag(){dataLayer.push(arguments);}
    gtag('js', new Date());
    gtag('config', {!! json_encode($googleAnalyticsId) !!});
</script>
@endif
