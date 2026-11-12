<?php

namespace Botble\Marketplace\Supports;

/**
 * The commission a single vendor pays, after the plan override has been reconciled with the
 * global settings. Both values are already resolved: no -1 sentinel survives construction,
 * so nothing downstream may reinterpret them.
 */
final class CommissionRate
{
    public readonly float $percentage;

    public readonly float $fixed;

    public function __construct(float $percentage, float $fixed)
    {
        // A negative rate would credit the vendor on every sale. The -1 sentinel is decoded
        // in CommissionFeeResolver; anything still negative here is a bug, so clamp it.
        $this->percentage = max(0.0, $percentage);
        $this->fixed = max(0.0, $fixed);
    }

    /**
     * No commission at all - the marketplace earns from subscriptions instead.
     */
    public static function none(): self
    {
        return new self(0.0, 0.0);
    }

    public function isNone(): bool
    {
        return $this->percentage <= 0.0 && $this->fixed <= 0.0;
    }
}
