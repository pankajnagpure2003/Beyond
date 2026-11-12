<?php

namespace Botble\Marketplace\Forms\Settings\Concerns;

use Botble\Marketplace\Facades\MarketplaceHelper;

/**
 * A collapsible fieldset that stays open for SEVERAL marketplace modes, which hybrid needs:
 * the commission block belongs to commission + hybrid, the subscription block to
 * subscription + hybrid.
 *
 * Core's HasFieldset::addOpenCollapsible() cannot express this - it compares with
 * `$value != $currentValue` (an array is never loosely equal to a string, so the block would
 * always render hidden) and builds the fieldset name with `$name . '_' . $value`, which
 * array-to-string converts. So we call addOpenFieldset() directly.
 *
 * The client side already supports it: core.js does
 * `Array.isArray(bbValue) ? bbValue.some(...) : isEqual(...)` on the parsed data-bb-value, and
 * jQuery's .data() JSON-decodes the attribute for us. Verified present in both the source and
 * the shipped public/vendor build, so no core patch and no extra JS.
 */
trait HasModeCollapsible
{
    /**
     * @param  array<int, string>  $modes  Modes this block should be visible for.
     */
    protected function addOpenModeCollapsible(array $modes): static
    {
        $modes = array_values($modes);

        $attributes = [
            'data-bb-collapse' => 'true',
            'data-bb-trigger' => '[name=mode]',
            'data-bb-value' => json_encode($modes),
        ];

        // Initial render only; from then on core.js toggles it on every select change.
        if (! in_array(MarketplaceHelper::getMode(), $modes, true)) {
            $attributes['style'] = 'display: none';
        }

        return $this->addOpenFieldset($this->modeFieldsetName($modes), $attributes);
    }

    /**
     * @param  array<int, string>  $modes
     */
    protected function addCloseModeCollapsible(array $modes): static
    {
        return $this->addCloseFieldset($this->modeFieldsetName(array_values($modes)));
    }

    /**
     * @param  array<int, string>  $modes
     */
    protected function modeFieldsetName(array $modes): string
    {
        return 'mode_' . implode('_', $modes);
    }
}
