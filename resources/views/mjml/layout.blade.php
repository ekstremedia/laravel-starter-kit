@php
    // Branding/wrapper is editable from the dashboard (MailLayout). Callers may
    // pass a draft $layout (for live preview); otherwise use the saved one.
    $layout = $layout ?? \App\Domains\Notifications\Models\MailLayout::current();

    // Footer supports two layout-level placeholders (not per-email variables).
    $footer = str_replace(
        ['{{ year }}', '{{ app_name }}'],
        [date('Y'), config('app.name')],
        $layout->footer_text ?? ''
    );

    // CTA is only rendered for a label + a *safe* URL. Safe = http(s), or a
    // placeholder at the START of the string (so "javascript:{{ x }}" is
    // rejected even though it contains a placeholder).
    $isSafeActionUrl = false;
    if (is_string($actionUrl) && $actionUrl !== '') {
        $actionUrl = trim($actionUrl);
        $scheme = parse_url($actionUrl, PHP_URL_SCHEME);
        $scheme = is_string($scheme) ? strtolower($scheme) : null;

        if (str_contains($actionUrl, '{{')) {
            $isSafeActionUrl = preg_match('/^\s*\{\{\s*[^}]+\s*\}\}/', $actionUrl) === 1
                || in_array($scheme, ['http', 'https'], true);
        } else {
            $isSafeActionUrl = filter_var($actionUrl, FILTER_VALIDATE_URL) !== false
                && in_array($scheme, ['http', 'https'], true);
        }
    }
@endphp
<mjml>
  <mj-head>
    <mj-attributes>
      <mj-all font-family="{{ $layout->font_family }}" />
      <mj-text font-size="15px" line-height="1.6" color="{{ $layout->text_color }}" />
      <mj-button background-color="{{ $layout->button_color }}" border-radius="8px" font-size="15px" font-weight="600" inner-padding="12px 28px" />
    </mj-attributes>
    <mj-style>
      .footer-text div { color: {{ $layout->footer_color }} !important; font-size: 12px !important; }
    </mj-style>
  </mj-head>
  <mj-body background-color="{{ $layout->body_bg }}">
    <mj-section padding="30px 0 10px">
      <mj-column>
        @if($layout->header_mode === 'image' && $layout->header_logo_url)
        <mj-image src="{{ $layout->header_logo_url }}" alt="{{ config('app.name') }}" align="center" width="180px" padding="0" />
        @else
        <mj-text align="center" font-size="22px" font-weight="700" color="{{ $layout->brand_color }}">
          {{ config('app.name') }}
        </mj-text>
        @endif
      </mj-column>
    </mj-section>

    <mj-section background-color="{{ $layout->card_bg }}" border-radius="12px" padding="32px 40px">
      <mj-column>
        @if($heading)
        <mj-text font-size="20px" font-weight="700" color="{{ $layout->heading_color }}" padding-bottom="16px">
          {!! nl2br(e($heading)) !!}
        </mj-text>
        @endif

        <mj-text>
          {!! nl2br(e($body)) !!}
        </mj-text>

        @if($actionText && $isSafeActionUrl)
        <mj-button href="{{ $actionUrl }}" align="left" padding-top="20px">
          {{ $actionText }}
        </mj-button>
        @endif
      </mj-column>
    </mj-section>

    <mj-section padding="20px 0">
      <mj-column>
        <mj-text align="center" css-class="footer-text">
          {!! nl2br(e($footer)) !!}
        </mj-text>
      </mj-column>
    </mj-section>
  </mj-body>
</mjml>
