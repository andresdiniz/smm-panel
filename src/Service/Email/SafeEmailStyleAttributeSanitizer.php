<?php

declare(strict_types=1);

namespace App\Service\Email;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

final class SafeEmailStyleAttributeSanitizer implements AttributeSanitizerInterface
{
    private const ALLOWED_PROPERTIES = [
        'background-color',
        'border',
        'border-bottom',
        'border-collapse',
        'border-color',
        'border-radius',
        'border-spacing',
        'border-top',
        'color',
        'font-family',
        'font-size',
        'font-style',
        'font-weight',
        'height',
        'line-height',
        'margin',
        'margin-bottom',
        'margin-left',
        'margin-right',
        'margin-top',
        'max-width',
        'min-width',
        'padding',
        'padding-bottom',
        'padding-left',
        'padding-right',
        'padding-top',
        'text-align',
        'text-decoration',
        'vertical-align',
        'width',
    ];

    public function getSupportedElements(): ?array
    {
        return null;
    }

    public function getSupportedAttributes(): ?array
    {
        return ['style'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        $safeDeclarations = [];

        foreach (explode(';', $value) as $declaration) {
            $parts = explode(':', $declaration, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $property = strtolower(trim($parts[0]));
            $propertyValue = trim($parts[1]);
            if (!in_array($property, self::ALLOWED_PROPERTIES, true)
                || $propertyValue === ''
                || preg_match('/(?:url|expression|javascript|behavior|@import)/i', $propertyValue)
                || !preg_match('/^[a-z0-9\s#.,%()\/\'"-]+$/i', $propertyValue)
            ) {
                continue;
            }

            $safeDeclarations[] = $property.':'.$propertyValue;
        }

        return $safeDeclarations === [] ? null : implode(';', $safeDeclarations);
    }
}
