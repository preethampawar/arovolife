{{-- Google Analytics 4 (gtag.js).
     Emitted only when GOOGLE_ANALYTICS_ID is configured
     (config('arovolife.analytics.google_id')). Skipped on the admin
     console — internal staff actions should not inflate public-funnel metrics,
     and admin pages render PII we don't want sent to GA.

     INTENTIONALLY NOT included on auth/reset-password — the reset token is
     in the URL path and GA4's page_location would ship it to Google.
     Skipped for the same reason on any signed link (account / spouse
     activation, KYC re-upload): `signature` in the query string is a live
     credential for up to 30 days.

     Google is sent the route pattern, never the real address: `/tree/{adn}`,
     not `/tree/ARV00012345`. ADNs, order numbers and cart codes travel in our
     paths, and Privacy Policy §7 promises they never reach Google. A page
     whose route has parameters sends the pattern as its title too, since a
     title can repeat what the path carries.

     The one exception is a public catalogue or policy page: its slug names a
     company product or document, never a person, so the real slug and title
     are sent and each product reports as its own page. Add a route here only
     if every one of its parameters is company content. --}}
@php
    $googleAnalyticsId = config('arovolife.analytics.google_id');
    $gaRoute = request()->route();
    $gaPublicParams = ['shop.product' => ['slug'], 'content.show' => ['slug']];
    $gaPath = '/(not-found)';
    $gaTitle = $gaPath;
    if ($gaRoute !== null) {
        $gaKept = $gaPublicParams[$gaRoute->getName()] ?? [];
        $gaPath = '/'.ltrim($gaRoute->uri(), '/');
        foreach ($gaKept as $gaParam) {
            $gaPath = str_replace('{'.$gaParam.'}', rawurlencode((string) $gaRoute->originalParameter($gaParam)), $gaPath);
        }
        $gaTitle = array_diff($gaRoute->parameterNames(), $gaKept) !== [] ? $gaPath : null;
    }
@endphp
@if(!empty($googleAnalyticsId) && ! request()->has('signature'))
<script>
(function () {
    'use strict';

    var GA_ID = {!! json_encode($googleAnalyticsId) !!};
    var GA_PATH = {!! json_encode($gaPath) !!};
    var GA_TITLE = {!! json_encode($gaTitle) !!};

    // Privacy Policy §9 promises GA does not load under do-not-track.
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1') {
        return;
    }

    window.dataLayer = window.dataLayer || [];
    window.gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag('js', new Date());
    // Google signals and ad personalisation off: §9 says this data never
    // feeds advertising profiles.
    // A same-origin referrer is one of our own paths, so it is cut to the
    // origin for the same reason as page_location.
    // Campaign tags are the one part of the query string kept: they are
    // ours, set on links we publish, and carry no visitor data.
    var campaign = new URLSearchParams();
    new URLSearchParams(location.search).forEach(function (value, key) {
        if (/^utm_(source|medium|campaign|term|content)$/.test(key)) {
            campaign.append(key, value);
        }
    });
    var query = campaign.toString();

    var referrer = document.referrer;
    if (referrer.indexOf(location.origin + '/') === 0) {
        referrer = location.origin + '/';
    }

    window.gtag('config', GA_ID, {
        page_location: location.origin + GA_PATH + (query ? '?' + query : ''),
        page_title: GA_TITLE || document.title,
        page_referrer: referrer,
        anonymize_ip: true,
        allow_google_signals: false,
        allow_ad_personalization_signals: false
    });

    var s = document.createElement('script');
    s.async = true;
    s.src = 'https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(GA_ID);
    document.head.appendChild(s);
})();
</script>
@endif
