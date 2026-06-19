<!DOCTYPE html>
<html>

<head>
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/components.chunk.css?v={{$version}}">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/umi.css?v={{$version}}">
    @php ($themeColor = $theme_config['theme_color'] ?? 'default')
    @php ($themeCssPath = public_path("theme/{$theme}/assets/theme/{$themeColor}.css"))
    @php ($themeOverrideCssPath = public_path("theme/{$theme}/assets/theme/{$themeColor}-overrides.css"))
    @php ($preloadThemeColors = array_unique(['default', 'darkblue']))
    @foreach ($preloadThemeColors as $preloadThemeColor)
        @continue ($preloadThemeColor === $themeColor)
        @php ($preloadThemeCssPath = public_path("theme/{$theme}/assets/theme/{$preloadThemeColor}.css"))
        @php ($preloadThemeOverrideCssPath = public_path("theme/{$theme}/assets/theme/{$preloadThemeColor}-overrides.css"))
        @if (file_exists($preloadThemeCssPath))
            <link rel="preload" as="style" href="/theme/{{$theme}}/assets/theme/{{$preloadThemeColor}}.css?v={{$version}}-{{filemtime($preloadThemeCssPath)}}">
        @endif
        @if (file_exists($preloadThemeOverrideCssPath))
            <link rel="preload" as="style" href="/theme/{{$theme}}/assets/theme/{{$preloadThemeColor}}-overrides.css?v={{$version}}-{{filemtime($preloadThemeOverrideCssPath)}}">
        @endif
    @endforeach
    @if (file_exists($themeCssPath))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/theme/{{$themeColor}}.css?v={{$version}}-{{filemtime($themeCssPath)}}">
    @endif
    @if (file_exists($themeOverrideCssPath))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/theme/{{$themeColor}}-overrides.css?v={{$version}}-{{filemtime($themeOverrideCssPath)}}">
    @endif
    @if (file_exists(public_path("/theme/{$theme}/assets/custom.css")))
        <link rel="stylesheet" href="/theme/{{$theme}}/assets/custom.css?v={{$version}}-{{filemtime(public_path("theme/{$theme}/assets/custom.css"))}}">
    @endif
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,minimum-scale=1,user-scalable=no">
    @php ($colors = [
        'darkblue' => '#e184ad',
        'black' => '#343a40',
        'default' => '#0665d0',
        'green' => '#319795'
    ])
    <meta name="theme-color" content="{{$colors[$themeColor] ?? $colors['default']}}">
    {{-- dashboard_inline_pink_header_unify --}}
    @if ($themeColor === 'darkblue')
        <style>
            html body #page-container #page-header,
            html body #page-container #page-header > .content-header,
            html body #page-container #sidebar > .smini-hidden,
            html body #page-container #sidebar > .smini-hidden.bg-header-dark,
            html body #page-container #sidebar > .smini-hidden > .content-header,
            html body #page-container #sidebar > .smini-hidden > .content-header.bg-white-10,
            html body #page-container #sidebar > .smini-hidden > .content-header > a,
            html body #page-container #sidebar > .smini-hidden > .content-header > a > span {
                background: #e184ad !important;
                background-color: #e184ad !important;
                background-image: none !important;
                box-shadow: none !important;
                opacity: 1 !important;
                filter: none !important;
                mix-blend-mode: normal !important;
            }
        </style>
    @endif

    <title>{{$title}}</title>
    <!-- <link rel="stylesheet" href="https://fonts.googleapis.com/css?family=Nunito+Sans:300,400,400i,600,700"> -->
    <script>window.routerBase = "/";</script>
    <script>
        window.settings = {
            title: '{{$title}}',
            assets_path: '/theme/{{$theme}}/assets',
            theme: {
                sidebar: '{{$theme_config['theme_sidebar']}}',
                header: '{{$theme_config['theme_header']}}',
                color: '{{$themeColor}}',
            },
            version: '{{$version}}',
            background_url: '{{$theme_config['background_url']}}',
            description: '{{$description}}',
            i18n: [
                'zh-CN',
                'en-US',
                'ja-JP',
                'vi-VN',
                'ko-KR',
                'zh-TW',
                'fa-IR'
            ],
            logo: '{{$logo}}'
        }
    </script>
    <script src="/theme/{{$theme}}/assets/i18n/zh-CN.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/zh-TW.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/en-US.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/ja-JP.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/vi-VN.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/ko-KR.js?v={{$version}}"></script>
    <script src="/theme/{{$theme}}/assets/i18n/fa-IR.js?v={{$version}}"></script>
</head>

<body>
<div id="root"></div>
{!! $theme_config['custom_html'] !!}
<script src="/theme/{{$theme}}/assets/vendors.async.js?v={{$version}}"></script>
<script src="/theme/{{$theme}}/assets/components.async.js?v={{$version}}"></script>
<script src="/theme/{{$theme}}/assets/umi.js?v={{$version}}-{{filemtime(public_path('theme/'.$theme.'/assets/umi.js'))}}"></script>
@if (file_exists(public_path("/theme/{$theme}/assets/custom.js")))
    <script src="/theme/{{$theme}}/assets/custom.js?v={{$version}}"></script>
@endif</body>

</html>
