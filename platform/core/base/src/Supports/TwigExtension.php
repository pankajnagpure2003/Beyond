<?php

namespace Botble\Base\Supports;

use BackedEnum;
use Illuminate\Support\Str;
use Stringable;
use Twig\Extension\AbstractExtension;
use Twig\Extension\ExtensionInterface;
use Twig\TwigFilter;
use UnitEnum;

class TwigExtension extends AbstractExtension implements ExtensionInterface
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('trans', [$this, 'trans']),
        ];
    }

    /**
     * Laravel's translator only substitutes :placeholder. Older or customer-overridden
     * language files (lang/vendor/...) may still use Twig-style {{ placeholder }}. Email templates
     * are compiled only once, so those would reach the recipient as raw text - support them here.
     */
    public function trans(?string $key = null, array $replace = [], ?string $locale = null): mixed
    {
        if (empty($replace)) {
            return trans($key, $replace, $locale);
        }

        $line = trans($key, [], $locale);

        if (! is_string($line) || ! str_contains($line, '{{')) {
            return trans($key, $replace, $locale);
        }

        // Only values that can be rendered as text are substituted (enums as Laravel does)
        $values = array_map(
            fn ($value) => (string) $value,
            array_filter(
                array_map(fn ($value) => match (true) {
                    $value instanceof BackedEnum => $value->value,
                    $value instanceof UnitEnum => $value->name,
                    default => $value,
                }, $replace),
                fn ($value) => $value === null || is_scalar($value) || $value instanceof Stringable
            )
        );

        // Normalize {{ key }} to :key on the raw line (before any value is inserted), so values
        // containing "{{ ... }}" are never expanded, then substitute in a single pass like Laravel.
        $line = preg_replace_callback(
            '/{{\s*([A-Za-z0-9_]+)\s*}}/',
            fn (array $matches) => array_key_exists($matches[1], $values) ? ':' . $matches[1] : $matches[0],
            $line
        ) ?? $line;

        $replacements = [];

        foreach ($values as $name => $value) {
            $replacements[':' . Str::ucfirst($name)] = Str::ucfirst($value);
            $replacements[':' . Str::upper($name)] = Str::upper($value);
            $replacements[':' . $name] = $value;
        }

        return strtr($line, $replacements);
    }
}
