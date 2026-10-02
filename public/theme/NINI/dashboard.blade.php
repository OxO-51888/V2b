<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#ff408a">
    @php
        $metaPlainText = static function ($value) {
            return trim(preg_replace('/\s+/u', ' ', strip_tags(html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        };
        $metaTitle = $metaPlainText($title);
        $metaDescription = $metaTitle . '提供快速、稳定的网络加速服务，支持手机、电脑等多种设备，满足日常浏览、影音娱乐和跨境办公需求。先试用，再选购适合自己的套餐，轻松连接更大的世界。';
        $metaUrlParts = parse_url((string) config('v2board.app_url', ''));
        if (!is_array($metaUrlParts) || empty($metaUrlParts['host']) || !in_array(strtolower($metaUrlParts['scheme'] ?? ''), ['http', 'https'], true)) {
            $metaUrlParts = parse_url(url('/'));
        }
        $metaOrigin = strtolower($metaUrlParts['scheme']) . '://' . $metaUrlParts['host'] . (isset($metaUrlParts['port']) ? ':' . $metaUrlParts['port'] : '');
        $metaCanonical = $metaOrigin . '/';
        $metaImage = $metaOrigin . '/theme/' . rawurlencode($theme) . '/assets/retouched/gift-soft-v2-lossless.png';
    @endphp
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow,max-image-preview:large">
    <link rel="canonical" href="{{ $metaCanonical }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="zh_CN">
    <meta property="og:site_name" content="{{ $metaTitle }}">
    <meta property="og:title" content="{{ $metaTitle }}">
    <meta property="og:description" content="{{ $metaDescription }}">
    <meta property="og:url" content="{{ $metaCanonical }}">
    <meta property="og:image" content="{{ $metaImage }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1145">
    <meta property="og:image:height" content="1374">
    <meta property="og:image:alt" content="{{ $metaTitle }} - 粉色看板娘">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $metaTitle }}">
    <meta name="twitter:description" content="{{ $metaDescription }}">
    <meta name="twitter:image" content="{{ $metaImage }}">
    <meta name="twitter:image:alt" content="{{ $metaTitle }} - 粉色看板娘">
    <title>{{$title}}</title>
    <script>
        window.settings = {
            title: @json($title),
            assets_path: @json('/theme/' . $theme . '/assets'),
            version: @json($version),
            description: @json($description),
            logo: @json($logo),
            community_enabled: @json((bool) config('community.enabled', false)),
            tos_url: @json(config('v2board.tos_url')),
            stop_register: @json((int) config('v2board.stop_register', 0)),
            ticket_status: @json((int) config('v2board.ticket_status', 0)),
            theme_config: @json($theme_config)
        };

        (function () {
            var path = location.hash.replace(/^#\/?/, '').split('?')[0];
            var theme = window.settings.theme_config || {};
            var image = theme.landing_enabled !== 'off' && (!path || path === 'landing')
                ? 'gift' : ['login','register','forgetpassword','forget'].includes(path) ? 'mascot' : '';
            if (!image) return;
            var link = document.createElement('link');
            link.rel = 'preload'; link.as = 'image'; link.type = 'image/png';
            link.href = window.settings.assets_path + '/retouched/' + (image === 'mascot' ? 'mascot-soft-v3-optimized.png' : 'gift-soft-v2-lossless.png');
            link.setAttribute('fetchpriority', 'high'); document.head.appendChild(link);
        })();
    </script>
    <link rel="preload" href="/theme/{{$theme}}/assets/fonts/subsets/HYLeMiao.common.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/theme.css?v={{$version}}-nini-1.2.0-731d0bfb5302">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/commerce.css?v={{$version}}-nini-1.2.0-850a158ff3fd">
    <link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/support.css?v={{$version}}-nini-1.2.0-7652bf48b92f">
<link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/fidelity.css?v={{$version}}-nini-1.2.0-b5c812267dc0"><link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/motion.css?v={{$version}}-nini-1.2.0-a153b55f1664"><link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/landing.css?v={{$version}}-nini-1.2.0-0254623e7372"><link rel="stylesheet" href="/theme/{{$theme}}/assets/v1/viewport.css?v={{$version}}-nini-1.2.0-1b6ed23ad8e7"><script src="/theme/{{$theme}}/assets/v1/viewport.js?v={{$version}}-nini-1.2.0-1e9365ed5634"></script></head>
<body>
    <div id="nini-root"><div class="boot-screen">正在加载 {{$title}}…</div></div>
    <script src="/theme/{{$theme}}/assets/vendor/purify.min.js?v=3.2.6"></script>
    <script src="/theme/{{$theme}}/assets/vendor/marked.umd.js?v=15.0.12"></script>
    <script src="/theme/{{$theme}}/assets/vendor/qrcode.min.js?v=1.0.0"></script>
    <script src="/theme/{{$theme}}/assets/v1/core.js?v={{$version}}-nini-1.2.0-a9206ccdca96"></script>
    <script src="/theme/{{$theme}}/assets/v1/commerce.js?v={{$version}}-nini-1.2.0-8e312840ca00"></script>
    <script src="/theme/{{$theme}}/assets/v1/support.js?v={{$version}}-nini-1.2.0-233e61269da7"></script>
    <script src="/theme/{{$theme}}/assets/v1/reference-lettering.js?v={{$version}}-nini-1.2.0-c7923f48a634"></script>
    <script src="/theme/{{$theme}}/assets/v1/fidelity.js?v={{$version}}-nini-1.2.0-02f899cef6fa"></script>
    <script src="/theme/{{$theme}}/assets/v1/motion.js?v={{$version}}-nini-1.2.0-4c8fd705ce04"></script><script src="/theme/{{$theme}}/assets/v1/landing.js?v={{$version}}-nini-1.2.0-26ca8e1eeca2"></script>
    <script>Nini.boot();</script>
    {!! $theme_config['custom_html'] ?? '' !!}
</body>
</html>
