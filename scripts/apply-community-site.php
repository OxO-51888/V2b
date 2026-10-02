<?php

$root = dirname(__DIR__);
$options = getopt('', ['site:']);
$site = $options['site'] ?? null;
if ($site === null) {
    $autoload = $root . '/vendor/autoload.php';
    if (is_file($autoload)) {
        require $autoload;
        Dotenv\Dotenv::createImmutable($root)->safeLoad();
    }
    $site = $_ENV['COMMUNITY_SITE'] ?? $_SERVER['COMMUNITY_SITE'] ?? getenv('COMMUNITY_SITE');
    if (!$site && is_file($root . '/bootstrap/cache/config.php')) {
        $config = require $root . '/bootstrap/cache/config.php';
        $site = $config['community']['site'] ?? '';
    }
}
if (!in_array($site, ['gm', 'nnm', 'ovo', 'clash', 'pianyi', 'yiyuan'], true)) {
    exit(0);
}

function writeCommunityAsset(string $path, string $content): void
{
    $temporary = tempnam(dirname($path), '.community-site-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot stage community asset');
    }
    try {
        if (file_put_contents($temporary, $content) !== strlen($content)) {
            throw new RuntimeException('Cannot write community asset');
        }
        chmod($temporary, fileperms($path) & 0777);
        if (!rename($temporary, $path)) {
            throw new RuntimeException('Cannot publish community asset');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

function replaceCommunityVersion(string $path, string $pattern, string $replacement): string
{
    $content = file_get_contents($path);
    $updated = preg_replace($pattern, $replacement, $content, -1, $count);
    if ($updated === null || $count !== 1) {
        throw new RuntimeException('Community asset version marker missing: ' . basename($path));
    }
    return $updated;
}

$indexPath = $root . '/public/community/index.html';
$index = file_get_contents($root . '/resources/community/sites/' . $site . '.html');
if ($index === false) {
    throw new RuntimeException('Community site page is missing');
}
$version = substr(hash('sha256', $index), 0, 12);
$supportPath = $root . '/public/theme/NINI/assets/v1/support.js';
$support = replaceCommunityVersion($supportPath, '~(/community/index\.html\?client=nini&embedded=1&v=)[^\x27\x22]+~', '${1}' . $version);
$defaultPath = $root . '/public/theme/default/assets/umi.js';
$default = replaceCommunityVersion($defaultPath, '~(/community/index\.html\?client=default&embedded=1&v=)[^\x22]+~', '${1}' . $version);
$dashboardPath = $root . '/public/theme/NINI/dashboard.blade.php';
$dashboard = replaceCommunityVersion($dashboardPath, '~(/assets/v1/support\.js\?v=\{\{\$version\}\}-nini-[^\x22]+-)\w{12}(?=\x22)~', '${1}' . substr(hash('sha256', $support), 0, 12));

writeCommunityAsset($indexPath, $index);
writeCommunityAsset($supportPath, $support);
writeCommunityAsset($defaultPath, $default);
writeCommunityAsset($dashboardPath, $dashboard);
echo 'Community site assets applied: ' . $site . PHP_EOL;
