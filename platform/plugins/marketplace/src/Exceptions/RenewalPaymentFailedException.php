<?php

namespace Botble\Marketplace\Exceptions;

use RuntimeException;

/**
 * The vendor's balance could not cover an automatic renewal.
 *
 * Thrown inside the renewal transaction purely so the claim is rolled back with it —
 * SubscriptionRenewalService catches it and turns it into a dunning notice, so it never
 * escapes to the caller.
 */
class RenewalPaymentFailedException extends RuntimeException
{
}
