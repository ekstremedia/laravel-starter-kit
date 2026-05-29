@php($l = $layout)
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background:{{ $l->body_bg }}; font-family:{{ $l->font_family }};">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{{ $l->body_bg }};">
        <tr>
            <td align="center" style="padding:32px 16px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px; background:{{ $l->card_bg }}; border-radius:8px; overflow:hidden;">
                    <!-- Header -->
                    <tr>
                        <td style="padding:24px 28px; border-bottom:3px solid {{ $l->brand_color }};">
                            @if($l->header_mode === 'logo' && $l->header_logo_url)
                                <img src="{{ $l->header_logo_url }}" alt="{{ $appName }}" style="max-height:36px;">
                            @else
                                <span style="font-size:18px; font-weight:700; color:{{ $l->heading_color }};">{{ $appName }}</span>
                            @endif
                        </td>
                    </tr>
                    <!-- Body -->
                    <tr>
                        <td style="padding:28px;">
                            <h1 style="margin:0 0 16px; font-size:20px; font-weight:600; color:{{ $l->heading_color }};">{{ $subjectLine }}</h1>
                            <div style="font-size:14px; line-height:1.6; color:{{ $l->text_color }};">{!! $bodyHtml !!}</div>
                        </td>
                    </tr>
                    <!-- Footer -->
                    <tr>
                        <td style="padding:18px 28px; border-top:1px solid rgba(0,0,0,0.06); font-size:12px; color:{{ $l->footer_color }};">
                            {{ $l->footer_text }}
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
