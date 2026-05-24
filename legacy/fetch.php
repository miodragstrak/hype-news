<?php

const REQUEST_TIMEOUT_SECONDS = 4;
const MAX_ARTICLES_PER_SITE = 8;
const ARTICLES_PER_SOURCE = 3;
const MAX_TITLE_LENGTH = 180;

if (!extension_loaded('dom')) {
    fwrite(
        STDERR,
        "Missing PHP extension: dom. Install it with `sudo apt install php-xml` or `sudo apt install php8.4-xml`.\n"
    );
    exit(1);
}

function normalize(string $text): string
{
    $text = mb_strtolower($text);

    return trim(
        preg_replace(
            '/[^a-z0-9šđčćž ]/iu',
            '',
            $text
        )
    );
}

function cleanTitle(string $title): string
{
    $title = preg_replace('/\s+/u', ' ', $title);
    $title = preg_replace('/\d{1,2}\.\d{1,2}\.\d{4}\.?$/u', '', $title);

    return trim($title);
}

function normalizeDate(string $value): string
{
    $value = trim($value);

    if ($value === '') {
        return '';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return '';
    }

    return date(DATE_ATOM, $timestamp);
}

function publishedTimestamp(array $article): int
{
    $timestamp = strtotime($article['published_at'] ?? '');

    return $timestamp === false ? 0 : $timestamp;
}

function fetchHtml(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'header' => "User-Agent: HypeNewsFetcher/1.0\r\n",
            'timeout' => REQUEST_TIMEOUT_SECONDS,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $html = @file_get_contents($url, false, $context);

    if ($html === false) {
        fwrite(STDERR, "Could not fetch: {$url}\n");
        return '';
    }

    return $html;
}

function absoluteUrl(string $base, string $path): string
{
    $path = trim($path);

    if ($path === '') {
        return '';
    }

    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }

    $parts = parse_url($base);

    if (!isset($parts['scheme'], $parts['host'])) {
        return $path;
    }

    if (str_starts_with($path, '//')) {
        return $parts['scheme'] . ':' . $path;
    }

    return $parts['scheme'] . '://' . $parts['host'] . '/' . ltrim($path, '/');
}

function makeXPath(string $html): ?DOMXPath
{
    if ($html === '') {
        return null;
    }

    $dom = new DOMDocument();

    libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML($html);
    libxml_clear_errors();

    if (!$loaded) {
        return null;
    }

    return new DOMXPath($dom);
}

function firstSrcsetUrl(string $srcset): string
{
    $candidates = array_filter(array_map('trim', explode(',', $srcset)));

    if ($candidates === []) {
        return '';
    }

    // Use the first candidate to keep the cached JSON small and predictable.
    $parts = preg_split('/\s+/', reset($candidates));

    return $parts[0] ?? '';
}

function firstMetaContent(DOMXPath $xpath, string $property): string
{
    $nodes = $xpath->query('//meta[@property="' . $property . '"]');

    if (!$nodes || !$nodes->length) {
        return '';
    }

    $meta = $nodes->item(0);

    if (!$meta instanceof DOMElement) {
        return '';
    }

    return trim($meta->getAttribute('content'));
}

function publishedAtFromNode(DOMXPath $xpath, DOMNode $node): string
{
    $times = $xpath->query('.//time[@datetime]', $node);

    if (!$times || !$times->length) {
        return '';
    }

    $time = $times->item(0);

    if (!$time instanceof DOMElement) {
        return '';
    }

    return normalizeDate($time->getAttribute('datetime'));
}

function extractArticleMetadata(string $url): array
{
    $xpath = makeXPath(fetchHtml($url));

    if ($xpath === null) {
        return [
            'image' => '',
            'published_at' => '',
        ];
    }

    // Some WordPress themes omit thumbnails/dates from listings, but keep them
    // in article-level metadata. Fetch the article page only when needed.
    $publishedAt = '';
    $document = $xpath->document;

    if ($document instanceof DOMDocument) {
        $publishedAt = publishedAtFromNode($xpath, $document);
    }

    if ($publishedAt === '') {
        $publishedAt = normalizeDate(firstMetaContent($xpath, 'article:published_time'));
    }

    return [
        'image' => absoluteUrl($url, firstMetaContent($xpath, 'og:image')),
        'published_at' => $publishedAt,
    ];
}

function firstImageFromArticle(DOMXPath $xpath, DOMNode $article, string $siteUrl): string
{
    // Respect the requested priority across all images in the card. Lazy-loaded
    // WordPress themes often leave src empty but expose data-src or srcset.
    foreach (['data-src', 'data-lazy-src', 'src', 'srcset'] as $attr) {
        $imgs = $xpath->query('.//img[@' . $attr . ']', $article);

        if (!$imgs) {
            continue;
        }

        foreach ($imgs as $img) {
            if (!$img instanceof DOMElement) {
                continue;
            }

            $value = $img->getAttribute($attr);

            if ($attr === 'srcset') {
                $value = firstSrcsetUrl($value);
            }

            if ($value !== '') {
                return absoluteUrl($siteUrl, $value);
            }
        }
    }

    return '';
}

function firstLinkUrl(DOMXPath $xpath, DOMNode $node, string $siteUrl): string
{
    if ($node instanceof DOMElement && $node->tagName === 'a') {
        return absoluteUrl($siteUrl, $node->getAttribute('href'));
    }

    $link = $xpath->query('.//a', $node);

    if (!$link || !$link->length) {
        return '';
    }

    $a = $link->item(0);

    if (!$a instanceof DOMElement) {
        return '';
    }

    return absoluteUrl($siteUrl, $a->getAttribute('href'));
}

function addArticle(
    array &$articles,
    DOMXPath $xpath,
    DOMNode $titleNode,
    DOMNode $imageNode,
    string $siteUrl,
    string $source
): void {
    if (count($articles) >= MAX_ARTICLES_PER_SITE) {
        return;
    }

    $title = cleanTitle($titleNode->textContent);

    if (mb_strlen($title) < 20 || mb_strlen($title) > MAX_TITLE_LENGTH) {
        return;
    }

    $url = firstLinkUrl($xpath, $titleNode, $siteUrl);

    if ($url === '') {
        $url = firstLinkUrl($xpath, $imageNode, $siteUrl);
    }

    if ($url === '') {
        return;
    }

    $image = firstImageFromArticle($xpath, $imageNode, $siteUrl);
    $publishedAt = publishedAtFromNode($xpath, $imageNode);

    if ($image === '' || $publishedAt === '') {
        $metadata = extractArticleMetadata($url);
        $image = $image !== '' ? $image : $metadata['image'];
        $publishedAt = $publishedAt !== '' ? $publishedAt : $metadata['published_at'];
    }

    if ($publishedAt === '') {
        // Cron should still produce a usable item if a remote site omits dates.
        $publishedAt = date(DATE_ATOM);
    }

    $key = normalize($title);

    if (!isset($articles[$key])) {
        $articles[$key] = [
                'title' => $title,
                'url' => $url,
                'image' => $image,
                'published_at' => $publishedAt,
                'source' => $source,
            ];
    }
}

function fetchSite(string $siteUrl, string $source): array
{
    $xpath = makeXPath(fetchHtml($siteUrl));

    if ($xpath === null) {
        return [];
    }

    $articles = [];
    $nodes = $xpath->query('//article | //div[contains(@class,"post")] | //div[contains(@class,"item")]');

    if (!$nodes) {
        return [];
    }

    foreach ($nodes as $article) {
        $titleNode = $xpath->query('.//h2|.//h3', $article);

        if (!$titleNode || !$titleNode->length) {
            continue;
        }

        addArticle($articles, $xpath, $titleNode->item(0), $article, $siteUrl, $source);
    }

    $headingLinks = $xpath->query('//h2[a] | //h3[a]');

    if ($headingLinks) {
        foreach ($headingLinks as $heading) {
            addArticle($articles, $xpath, $heading, $heading, $siteUrl, $source);
        }
    }

    return array_values($articles);
}

function sortNewestFirst(array &$articles): void
{
    usort(
        $articles,
        fn (array $a, array $b): int => publishedTimestamp($b) <=> publishedTimestamp($a)
    );
}

function removeSelectedArticle(array &$selectedBySource, string $source, string $key): void
{
    foreach ($selectedBySource[$source] as $index => $article) {
        if (normalize($article['title']) === $key) {
            unset($selectedBySource[$source][$index]);
            $selectedBySource[$source] = array_values($selectedBySource[$source]);
            return;
        }
    }
}

function sourceNeedsMore(array $selectedBySource): bool
{
    foreach ($selectedBySource as $selected) {
        if (count($selected) < ARTICLES_PER_SOURCE) {
            return true;
        }
    }

    return false;
}

function selectBalancedArticles(array $sources): array
{
    $selectedBySource = [];
    $positions = [];
    $seen = [];

    foreach ($sources as $source => &$articles) {
        sortNewestFirst($articles);
        $selectedBySource[$source] = [];
        $positions[$source] = 0;
    }

    unset($articles);

    while (sourceNeedsMore($selectedBySource)) {
        $addedInRound = false;

        foreach ($sources as $source => $articles) {
            while (
                count($selectedBySource[$source]) < ARTICLES_PER_SOURCE
                &&
                isset($articles[$positions[$source]])
            ) {
                $article = $articles[$positions[$source]];
                $positions[$source]++;
                $key = normalize($article['title']);

                if (!isset($seen[$key])) {
                    $seen[$key] = [
                        'source' => $source,
                        'article' => $article,
                    ];
                    $selectedBySource[$source][] = $article;
                    $addedInRound = true;
                    break;
                }

                $existing = $seen[$key];

                if (publishedTimestamp($article) <= publishedTimestamp($existing['article'])) {
                    // Older duplicate loses its slot; keep scanning this source
                    // so the city receives its next available unique article.
                    continue;
                }

                // Newer duplicate wins. Remove the older selected article from
                // its city; that city will backfill from its next candidate.
                removeSelectedArticle($selectedBySource, $existing['source'], $key);
                $seen[$key] = [
                    'source' => $source,
                    'article' => $article,
                ];
                $selectedBySource[$source][] = $article;
                $addedInRound = true;
                break;
            }
        }

        if (!$addedInRound) {
            break;
        }
    }

    $result = array_merge(...array_values($selectedBySource));
    sortNewestFirst($result);

    return $result;
}

$result = selectBalancedArticles([
    'Bor' => fetchSite('https://boronline.rs', 'Bor'),
    'Zaječar' => fetchSite('https://zajecaronline.com', 'Zaječar'),
]);

if ($result === []) {
    fwrite(STDERR, "No articles found. Existing cache file was not changed.\n");
    exit(1);
}

file_put_contents(
    __DIR__ . '/cache/news.json',
    json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
);

echo "DONE\n";
