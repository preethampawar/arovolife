@php
    $url   = $url   ?? '#';
    $label = $label ?? 'Continue';
    $bg    = $bg    ?? '#00b6ef';   // brand-500 (new arovolife blue)
    $bgD   = $bgD   ?? '#0a719f';   // brand-700 (border / VML)
@endphp
{{-- Bulletproof button — VML for Outlook + table-cell for everyone else.
     Keeps a clean rendered button across all major email clients. --}}
<table role="presentation" border="0" cellspacing="0" cellpadding="0" align="left" style="margin: 8px 0;">
    <tr>
        <td align="center" bgcolor="{{ $bg }}" style="background-color: {{ $bg }}; border-radius: 6px;">
            <!--[if mso]>
            <v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:w="urn:schemas-microsoft-com:office:word" href="{{ $url }}" style="height:42px; v-text-anchor:middle; width:220px;" arcsize="14%" stroke="f" fillcolor="{{ $bg }}">
                <w:anchorlock/>
                <center style="color:#ffffff; font-family:Arial, sans-serif; font-size:14px; font-weight:700;">{{ $label }}</center>
            </v:roundrect>
            <![endif]-->
            <!--[if !mso]><!-- -->
            <a href="{{ $url }}" target="_blank"
               class="ar-btn"
               style="display: inline-block; padding: 12px 28px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; font-size: 14px; font-weight: 700; line-height: 18px; color: #ffffff; text-decoration: none; border-radius: 6px; background-color: {{ $bg }}; mso-padding-alt: 0;">
                {{ $label }}
            </a>
            <!--<![endif]-->
        </td>
    </tr>
</table>
