<meta name="theme-color" content="{{ config('pwa.theme_color', '#0f172a') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('pwa.short_name', 'FurimaDeck') }}">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<link rel="manifest" href="{{ route('pwa.manifest') }}">
<link rel="apple-touch-icon" href="{{ asset('images/icons/icon-192.png') }}">
