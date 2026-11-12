<?php

namespace Botble\CookieConsent\Supports;

class ConsentColor
{
    /**
     * Pick the text color (white vs near-black) with the highest WCAG contrast
     * against the given accent color, so a CTA stays legible whatever brand
     * color the store sets. White on a mid-tone brand color (e.g. the default
     * orange) only reaches ~2.9:1 and fails the AA 4.5:1 threshold.
     */
    public static function accentTextColorFor(?string $accentColor): string
    {
        if (! $accentColor || ! preg_match('/^#?([a-fA-F0-9]{3}|[a-fA-F0-9]{6})$/', $accentColor)) {
            return '#ffffff';
        }

        $hex = ltrim($accentColor, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $luminance = 0.2126 * self::toLinear(hexdec(substr($hex, 0, 2)))
            + 0.7152 * self::toLinear(hexdec(substr($hex, 2, 2)))
            + 0.0722 * self::toLinear(hexdec(substr($hex, 4, 2)));

        $contrastWhite = (1.0 + 0.05) / ($luminance + 0.05);
        $contrastBlack = ($luminance + 0.05) / 0.05;

        return $contrastWhite >= $contrastBlack ? '#ffffff' : '#18181b';
    }

    protected static function toLinear(float $channel): float
    {
        $channel /= 255;

        return $channel <= 0.03928 ? $channel / 12.92 : pow(($channel + 0.055) / 1.055, 2.4);
    }
}
