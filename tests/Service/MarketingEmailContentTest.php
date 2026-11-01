<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MarketingEmailContent;
use PHPUnit\Framework\TestCase;

final class MarketingEmailContentTest extends TestCase
{
    public function testSanitizesHtmlAndRewritesWebLinksForTracking(): void
    {
        $content = new MarketingEmailContent();
        $body = $content->sanitize(
            '<h2 style="color:#01696f;background-image:url(javascript:alert(1))" onclick="alert(1)">Oferta</h2>'
            .'<script>alert(2)</script>'
            .'<a href="https://example.com/oferta">Ver oferta</a>'
        );
        $links = [];

        $body = $content->rewriteLinks(
            $body,
            static fn (string $linkId): string => 'https://site.test/marketing/click/'.$linkId,
            $links,
        );

        self::assertStringContainsString('style="color:#01696f"', $body);
        self::assertStringNotContainsString('background-image', $body);
        self::assertStringNotContainsString('onclick', $body);
        self::assertStringNotContainsString('<script', $body);
        self::assertStringContainsString('href="https://site.test/marketing/click/0"', $body);
        self::assertSame(['https://example.com/oferta'], array_values($links));
    }

    public function testEscapesPlainTextInsteadOfInterpretingItAsHtml(): void
    {
        $content = new MarketingEmailContent();

        $body = $content->sanitize('Desconto <3 hoje');

        self::assertSame('Desconto &lt;3 hoje', $body);
    }
}
