<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\Email\SafeEmailStyleAttributeSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

final class MarketingEmailContent
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = new HtmlSanitizerConfig();
        foreach ([
            'a' => ['href', 'title', 'style'],
            'b' => ['style'],
            'blockquote' => ['style'],
            'br' => [],
            'center' => ['style'],
            'div' => ['style'],
            'em' => ['style'],
            'h1' => ['style'],
            'h2' => ['style'],
            'h3' => ['style'],
            'h4' => ['style'],
            'hr' => ['style'],
            'i' => ['style'],
            'img' => ['src', 'alt', 'width', 'height', 'style'],
            'li' => ['style'],
            'ol' => ['style'],
            'p' => ['style'],
            'span' => ['style'],
            'strong' => ['style'],
            'table' => ['border', 'cellpadding', 'cellspacing', 'role', 'width', 'style'],
            'tbody' => [],
            'td' => ['align', 'colspan', 'rowspan', 'valign', 'width', 'style'],
            'tfoot' => [],
            'th' => ['align', 'colspan', 'rowspan', 'valign', 'width', 'style'],
            'thead' => [],
            'tr' => ['align', 'valign', 'style'],
            'u' => ['style'],
            'ul' => ['style'],
        ] as $element => $attributes) {
            $config = $config->allowElement($element, $attributes);
        }

        $config = $config
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowMediaSchemes(['https'])
            ->forceHttpsUrls()
            ->withMaxInputLength(40_000)
            ->allowAttribute('style', '*')
            ->withAttributeSanitizer(new SafeEmailStyleAttributeSanitizer());

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $body): string
    {
        if (!preg_match('/<\s*\/?\s*[a-z][^>]*>/i', $body)) {
            return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        }

        return $this->sanitizer->sanitize($body);
    }

    /**
     * @param callable(string): string $rewriteUrl
     * @param array<string, string> $links
     */
    public function rewriteLinks(string $html, callable $rewriteUrl, array &$links): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previousErrorMode = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<?xml encoding="UTF-8"><html><body>'.$html.'</body></html>', LIBXML_NONET | LIBXML_HTML_NODEFDTD);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorMode);
        }

        $anchors = [];
        foreach ($dom->getElementsByTagName('a') as $anchor) {
            $anchors[] = $anchor;
        }

        foreach ($anchors as $anchor) {
            $url = $anchor->getAttribute('href');
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (!in_array($scheme, ['http', 'https'], true)) {
                continue;
            }
            if (parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null) {
                $anchor->removeAttribute('href');
                continue;
            }

            $linkId = (string) count($links);
            $links[$linkId] = $url;
            $anchor->setAttribute('href', $rewriteUrl($linkId));
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if (!$body) {
            return $html;
        }

        $result = '';
        foreach ($body->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return $result;
    }
}
