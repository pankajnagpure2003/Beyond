<?php

namespace Botble\Ecommerce\Forms\Fronts\FieldOptions;

use Botble\Base\Facades\BaseHelper;

/**
 * Render an icon inside the input, on the leading edge.
 *
 * The markup is positioned by the `.auth-input-icon` rules in `front-card.css`,
 * shared by every form built on `Botble\Ecommerce\Forms\Fronts\CardForm`.
 */
trait HasIcon
{
    public function icon(string $name): static
    {
        $this
            ->prepend(
                sprintf(
                    '<div class="position-relative"><span class="auth-input-icon input-group-text">%s</span>',
                    BaseHelper::renderIcon($name)
                )
            )
            ->append('</div>')
            ->cssClass('form-control ps-5');

        return $this;
    }
}
