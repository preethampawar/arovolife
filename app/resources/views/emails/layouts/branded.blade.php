@php
    // Per-email overrides; default to neutral when not provided.
    $previewText  = $previewText  ?? ($subject ?? '');
    $accentColor  = $accentColor  ?? '#00b6ef';   // brand-500 (new arovolife blue)
    $accentDarker = $accentDarker ?? '#0a719f';   // brand-700
@endphp
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="en">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <meta name="color-scheme" content="light only">
    <meta name="supported-color-schemes" content="light only">
    <title>{{ $subject ?? 'arovolife' }}</title>
    <!--[if mso]>
    <style type="text/css">
        table, td, th { font-family: Arial, sans-serif !important; }
    </style>
    <![endif]-->
    <style type="text/css">
        /* Mobile-only overrides — wrapped in a media query so they don't affect
           desktop / Outlook (Outlook ignores @media). Bulletproof column-stack
           pattern for &lt;640px viewports. */
        @media screen and (max-width: 600px) {
            .ar-shell        { width: 100% !important; }
            .ar-pad-x        { padding-left: 20px !important; padding-right: 20px !important; }
            .ar-h1           { font-size: 22px !important; line-height: 30px !important; }
            .ar-btn          { display: block !important; width: 100% !important; box-sizing: border-box !important; }
        }
    </style>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #f4f6fa; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #1f2937;">
    {{-- Hidden preview text (preheader) — first thing shown in inbox previews. --}}
    <div style="display: none; max-height: 0; max-width: 0; overflow: hidden; mso-hide: all; opacity: 0; visibility: hidden; font-size: 1px; line-height: 1px; color: #f4f6fa;">{{ $previewText }}</div>

    {{-- Outer wrapper table — ensures full-width background colour fill --}}
    <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%" style="width: 100%; background-color: #f4f6fa;">
        <tr>
            <td align="center" valign="top" style="padding: 24px 12px;">

                {{-- Centered shell — 600px max --}}
                <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="600" class="ar-shell" style="width: 600px; max-width: 600px; background-color: #ffffff;">

                    {{-- Header band --}}
                    <tr>
                        <td align="left" valign="middle" bgcolor="{{ $accentColor }}" style="background-color: {{ $accentColor }}; padding: 18px 28px; border-top-left-radius: 8px; border-top-right-radius: 8px;">
                            <table role="presentation" border="0" cellspacing="0" cellpadding="0" width="100%">
                                <tr>
                                    <td align="left" valign="middle" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; font-size: 22px; line-height: 26px; font-weight: 700; color: #ffffff; letter-spacing: -0.3px;">
                                        arovolife
                                    </td>
                                    <td align="right" valign="middle" style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #cff1fd; text-transform: uppercase; letter-spacing: 1px; font-weight: 600;">
                                        Direct Selling, Done Right
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- Body --}}
                    <tr>
                        <td valign="top" class="ar-pad-x" style="padding: 32px 36px 28px 36px; background-color: #ffffff; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; font-size: 15px; line-height: 24px; color: #1f2937;">
                            @yield('content')
                        </td>
                    </tr>

                    {{-- Footer band --}}
                    <tr>
                        <td valign="top" bgcolor="#f4f6fa" class="ar-pad-x" style="background-color: #f4f6fa; padding: 22px 36px 28px 36px; border-bottom-left-radius: 8px; border-bottom-right-radius: 8px; border-top: 1px solid #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;">
                            <p style="margin: 0 0 6px 0; font-size: 12px; line-height: 18px; color: #4b5563; font-weight: 600;">
                                Arovolife Private Limited
                            </p>
                            <p style="margin: 0 0 4px 0; font-size: 11px; line-height: 17px; color: #6b7280;">
                                CIN U46909TS2026PTC210896 &middot; Registered in India
                            </p>
                            <p style="margin: 0 0 12px 0; font-size: 11px; line-height: 17px; color: #6b7280;">
                                Compliant with India's Consumer Protection (Direct Selling) Rules, 2021.
                            </p>
                            <p style="margin: 0; font-size: 11px; line-height: 17px; color: #9ca3af;">
                                Need help?
                                <a href="mailto:support@arovolife.com" style="color: {{ $accentDarker }}; text-decoration: underline;">support@arovolife.com</a>
                            </p>
                        </td>
                    </tr>

                </table>
                {{-- /shell --}}

            </td>
        </tr>
    </table>
</body>
</html>
