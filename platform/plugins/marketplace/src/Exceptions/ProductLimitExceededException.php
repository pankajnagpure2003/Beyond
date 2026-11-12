<?php

namespace Botble\Marketplace\Exceptions;

use RuntimeException;

/**
 * A vendor tried to create a product beyond their plan's allowance.
 *
 * Extends RuntimeException so the data-synchronize importer's chunk-level
 * `catch (Exception)` still acts as a backstop if a caller forgets to handle it.
 */
class ProductLimitExceededException extends RuntimeException
{
    public function __construct(public readonly ?int $limit = null, public readonly int $used = 0)
    {
        parent::__construct(trans('plugins/marketplace::subscription.vendor.limit_reached'));
    }
}
