<?php

namespace Botble\SeoHelper\Entities;

use Botble\SeoHelper\Bases\MetaCollection as BaseMetaCollection;

/**
 * Meta tags rendered with the `property` attribute instead of `name`, without any
 * prefix - for OpenGraph-adjacent namespaces such as `article:*`, `profile:*` or
 * `product:*`.
 *
 * The OpenGraph collection cannot be reused for these because it forces an `og:`
 * prefix on every property it renders.
 */
class PropertyMetaCollection extends BaseMetaCollection
{
    protected $prefix = '';

    protected $nameProperty = 'property';
}
