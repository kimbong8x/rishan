<?php
declare(strict_types=1);

/**
 * 国家税务总局政策法规库的同源代理。
 * 浏览器直接跨域读取官方页面会受 CORS 限制，因此由本站服务器获取、解析后返回简洁 JSON。
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=900, stale-while-revalidate=1800');

const POLICY_SOURCE_URL = 'https://fgk.chinatax.gov.cn/zcfgk/c100006/listflfg.html';
const POLICY_SOURCE_ORIGIN = 'https://fgk.chinatax.gov.cn';
const CACHE_SECONDS = 1800;

$cacheFile = __DIR__ . DIRECTORY_SEPARATOR . '.policy-feed-cache.json';

function respond(array $payload, int $status = 200): void {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function getCachedFeed(string $cacheFile): ?array {
    if (!is_file($cacheFile) || (time() - filemtime($cacheFile)) > CACHE_SECONDS) {
        return null;
    }
    $data = json_decode((string) file_get_contents($cacheFile), true);
    return is_array($data) && !empty($data['items']) ? $data : null;
}

function fetchSource(string $url): ?string {
    $userAgent = 'Hanjiari-Policy-Feed/1.0 (+https://www.hanjiari.com)';
    if (function_exists('curl_init')) {
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $content = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return is_string($content) && $status >= 200 && $status < 300 ? $content : null;
    }

    $context = stream_context_create(['http' => [
        'timeout' => 12,
        'header' => "User-Agent: {$userAgent}\r\nAccept: text/html,application/xhtml+xml\r\n",
    ]]);
    $content = @file_get_contents($url, false, $context);
    return is_string($content) && $content !== '' ? $content : null;
}

function textOf(DOMXPath $xpath, DOMNode $context, string $query): string {
    $node = $xpath->query($query, $context)->item(0);
    return $node ? trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '') : '';
}

function parsePolicies(string $html): array {
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    $xpath = new DOMXPath($dom);
    $links = $xpath->query('//li[.//p[contains(concat(" ", normalize-space(@class), " "), " bt ")]]//a[contains(@href, "/content.html")]');
    $items = [];

    foreach ($links as $link) {
        if (count($items) >= 3) break;
        $title = trim(preg_replace('/\s+/u', ' ', $link->textContent) ?? '');
        $hrefAttribute = $link->attributes->getNamedItem('href');
        $href = $hrefAttribute ? trim((string) $hrefAttribute->nodeValue) : '';
        $li = $link;
        while ($li && strtolower($li->nodeName) !== 'li') $li = $li->parentNode;
        if ($title === '' || $href === '' || !$li instanceof DOMNode) continue;
        $items[] = [
            'title' => $title,
            'number' => textOf($xpath, $li, './/p[contains(concat(" ", normalize-space(@class), " "), " fwzh ")]'),
            'date' => textOf($xpath, $li, './/p[contains(concat(" ", normalize-space(@class), " "), " cwrq ")]'),
            'url' => strpos($href, 'http') === 0 ? $href : POLICY_SOURCE_ORIGIN . $href,
        ];
    }
    libxml_clear_errors();
    return $items;
}

$cached = getCachedFeed($cacheFile);
if ($cached) respond($cached);

$source = fetchSource(POLICY_SOURCE_URL);
if ($source !== null) {
    $items = parsePolicies($source);
    if ($items) {
        $payload = [
            'items' => $items,
            'updatedAt' => gmdate('Y-m-d H:i') . ' UTC',
            'source' => POLICY_SOURCE_URL,
        ];
        @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        respond($payload);
    }
}

// 官方站临时不可访问时，如有过期缓存仍返回，保证前端展示连续。
if (is_file($cacheFile)) {
    $stale = json_decode((string) file_get_contents($cacheFile), true);
    if (is_array($stale) && !empty($stale['items'])) {
        $stale['stale'] = true;
        respond($stale);
    }
}

respond(['items' => [], 'error' => '无法获取官方法规库内容'], 503);
