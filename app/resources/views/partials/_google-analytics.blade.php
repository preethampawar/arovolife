{{-- Google Analytics 4 (gtag.js), behind a cookie banner.
     Emitted only when GOOGLE_ANALYTICS_ID is configured
     (config('arovolife.analytics.google_id')). Skipped on the admin
     console — internal staff actions should not inflate public-funnel metrics,
     and admin pages render PII we don't want sent to GA.

     GA loads only after the visitor chooses "Allow analytics" in the banner
     (DPDP Act §6; Privacy Policy §3 and §9). No choice, "No thanks", a
     do-not-track or Global Privacy Control signal: gtag.js never loads, and
     any `_ga` cookie already in the browser is deleted, so an identifier set
     before the banner existed cannot link old visits to a later "Allow". The
     choice lives only in the `arovo_analytics_consent` cookie, stamped with
     GA_CONSENT_VERSION — bump it when what we send Google changes, or when
     Privacy Policy §7/§8/§9 change materially for GA, and every visitor is
     asked again. Nothing on the server reads that cookie, so every
     visitor gets the same HTML.

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
{{-- Not a modal: it never blocks the page, and closing it is not consent.
     Both buttons carry the same weight (CCPA dark-patterns guidelines). --}}
<template id="arovo-cookie-banner">
    <div role="region" aria-label="Cookie choice"
         class="no-print print:hidden fixed inset-x-0 bottom-0 z-50 p-3 sm:p-4">
        <div class="mx-auto max-w-3xl rounded-lg border border-gray-200 bg-white p-4 text-sm text-gray-700 shadow-lg">
            <p data-cookie-status class="mb-2 font-medium text-gray-900" hidden></p>
            <p>
                arovolife would like to use Google Analytics cookies to see, in aggregate, how visitors use this site.
                If you allow them, Google LLC receives the general address of the pages you view, your device type and
                your approximate location, and may process this outside India. They are optional and stay off unless
                you allow them. You can change your choice at any time from "Cookie settings" at the foot of the page.
                <a href="{{ route('content.show', 'privacy') }}" class="underline hover:text-gray-900">Privacy Policy §9</a>
            </p>
            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" data-cookie-choice="granted"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50">
                    Allow analytics
                </button>
                <button type="button" data-cookie-choice="denied"
                        class="rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-900 hover:bg-gray-50">
                    No thanks
                </button>
            </div>
        </div>
    </div>
</template>
<script>
(function () {
    'use strict';

    var GA_ID = {!! json_encode($googleAnalyticsId) !!};
    var GA_PATH = {!! json_encode($gaPath) !!};
    var GA_TITLE = {!! json_encode($gaTitle) !!};
    var GA_CONSENT_COOKIE = 'arovo_analytics_consent';
    var GA_CONSENT_VERSION = '2026-10-26';

    // Every domain level a `_ga` cookie may sit on: gtag's cookie_domain
    // 'auto' picks the highest one it can write, e.g. `.arovolife.com`.
    function deleteGaCookies() {
        var parts = location.hostname.split('.');
        var domains = [''];
        for (var i = 0; i < parts.length - 1; i++) {
            domains.push('; domain=.' + parts.slice(i).join('.'));
        }
        document.cookie.split(';').forEach(function (pair) {
            var name = pair.split('=')[0].trim();
            if (name === '_ga' || name.indexOf('_ga_') === 0) {
                domains.forEach(function (domain) {
                    document.cookie = name + '=; max-age=0; path=/' + domain;
                });
            }
        });
    }

    // Privacy Policy §9: GA does not load under do-not-track or GPC.
    if (navigator.doNotTrack === '1' || window.doNotTrack === '1' || navigator.globalPrivacyControl === true) {
        deleteGaCookies();
        return;
    }

    function readChoice() {
        var match = document.cookie.match(new RegExp('(?:^|; )' + GA_CONSENT_COOKIE + '=([^;]*)'));
        var value = match ? match[1].split('.') : [];
        // A choice made under an older version is no choice at all.
        return value[1] === GA_CONSENT_VERSION ? value[0] : null;
    }

    function saveChoice(choice) {
        document.cookie = GA_CONSENT_COOKIE + '=' + choice + '.' + GA_CONSENT_VERSION
            + '; max-age=31536000; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function loadGa() {
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
    }

    var choice = readChoice();
    if (choice === 'granted') {
        loadGa();
    } else {
        deleteGaCookies();
    }

    var banner = null;

    function padBody() {
        if (banner) {
            document.body.style.paddingBottom = banner.offsetHeight + 'px';
        }
    }
    window.addEventListener('resize', padBody);

    function closeBanner() {
        if (banner) {
            banner.remove();
            banner = null;
            document.body.style.paddingBottom = '';
        }
    }

    // `reopened` comes from "Cookie settings": it shows the current choice.
    function openBanner(reopened) {
        if (banner) {
            return;
        }
        banner = document.getElementById('arovo-cookie-banner').content.firstElementChild.cloneNode(true);
        if (reopened) {
            var status = banner.querySelector('[data-cookie-status]');
            status.textContent = choice === 'granted' ? 'You have allowed analytics.' : 'You have not allowed analytics.';
            status.hidden = false;
        }
        banner.querySelectorAll('[data-cookie-choice]').forEach(function (button) {
            button.addEventListener('click', function () {
                var picked = button.getAttribute('data-cookie-choice');
                var withdrawn = choice === 'granted' && picked === 'denied';
                saveChoice(picked);
                if (withdrawn) {
                    // gtag.js is already running on this page; a reload is
                    // the only sure way to stop it. replace() always GETs, so
                    // a page that answered a POST never asks to resubmit.
                    deleteGaCookies();
                    location.replace(location.pathname + location.search);
                    return;
                }
                if (picked === 'granted' && choice !== 'granted') {
                    loadGa();
                }
                choice = picked;
                closeBanner();
            });
        });
        document.body.appendChild(banner);
        // Keep the banner off the page's own last controls (orientation
        // video, quiz submit, mobile CTAs), and again after a rotation or
        // resize changes its height.
        padBody();
        if (reopened) {
            banner.querySelector('[data-cookie-choice]').focus();
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-cookie-settings]').forEach(function (link) {
            link.hidden = false;
            link.addEventListener('click', function () { openBanner(true); });
        });
        if (choice === null) {
            openBanner(false);
        }
    });
})();
</script>
@endif
