<?php

namespace Botble\Marketplace\Models;

use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class VendorSubscription extends BaseModel
{
    protected $table = 'mp_vendor_subscriptions';

    protected $fillable = [
        'customer_id',
        'subscription_plan_id',
        'plan_data',
        'amount',
        'sub_total',
        'tax_amount',
        'tax_rate',
        'billing_data',
        'currency',
        'status',
        'starts_at',
        'ends_at',
        'auto_renew',
        'payment_id',
        'charge_id',
        'payment_channel',
        'rejected_reason',
        'cancelled_at',
        'renewed_from_id',
        'created_by_id',
        'created_by_type',
    ];

    protected $casts = [
        'status' => SubscriptionStatusEnum::class,
        'plan_data' => 'array',
        'amount' => 'float',
        'sub_total' => 'float',
        'tax_amount' => 'float',
        'tax_rate' => 'float',
        'billing_data' => 'array',
        'auto_renew' => 'bool',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::deleting(function (self $subscription): void {
            $subscription->logs()->delete();
            $subscription->notifications()->delete();
        });
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'customer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(VendorSubscriptionLog::class, 'vendor_subscription_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(VendorSubscriptionInvoice::class, 'vendor_subscription_id')->latest('id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(VendorSubscriptionNotification::class, 'vendor_subscription_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatusEnum::ACTIVE);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', SubscriptionStatusEnum::PENDING);
    }

    public function isPending(): bool
    {
        return $this->status == SubscriptionStatusEnum::PENDING;
    }

    public function isActive(): bool
    {
        return $this->status == SubscriptionStatusEnum::ACTIVE && ! $this->isExpired();
    }

    /**
     * A never-expiring plan. A pending row also has a null ends_at, but it has not
     * started yet, so it is not lifetime — it is simply undecided.
     */
    public function isLifetime(): bool
    {
        return $this->ends_at === null && $this->starts_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->ends_at !== null && $this->ends_at->isPast();
    }

    /**
     * Whether the subscription is past its end date plus the configured grace period.
     */
    public function isPastGracePeriod(int $graceDays = 0): bool
    {
        if ($this->ends_at === null) {
            return false;
        }

        return $this->ends_at->copy()->addDays($graceDays)->isPast();
    }

    public function daysUntilExpiry(): ?int
    {
        if ($this->ends_at === null) {
            return null;
        }

        return (int) Carbon::now()->startOfDay()->diffInDays($this->ends_at->copy()->startOfDay(), false);
    }

    /**
     * Read a quota or feature flag from the snapshot taken at purchase time,
     * falling back to the plan defaults when the snapshot predates the option.
     */
    public function option(string $key): int|float
    {
        $options = array_merge(
            SubscriptionPlan::defaultOptions(),
            (array) ($this->plan_data['options'] ?? [])
        );

        if (! array_key_exists($key, $options)) {
            throw new \InvalidArgumentException(sprintf('Unknown subscription plan option [%s].', $key));
        }

        // Snapshots written before an option existed fall back to the plan default above,
        // so a pre-upgrade subscription reads commission_* as -1 (inherit the global rate).
        return SubscriptionPlan::castOptionValue($key, $options[$key]);
    }

    /**
     * Quota/flag helpers only. Never call these with a commission_* key: -1 there means
     * "inherit the global rate", not "unlimited", and 0 is a real rate, not "not allowed".
     */
    public function allows(string $key): bool
    {
        return $this->option($key) > 0;
    }

    public function isUnlimited(string $key): bool
    {
        return $this->option($key) < 0;
    }

    /**
     * Whether the admin detail screen has any action to offer for this subscription.
     *
     * Single source of truth for the Actions panel: edit.blade.php uses it to decide
     * whether to render the sidebar at all, and partials/actions.blade.php gates its
     * buttons on the same two states. Widening this without adding a matching button — or
     * the reverse — gives you either an empty card or a button that never appears.
     */
    public function hasAdminActions(): bool
    {
        return $this->isPending() || $this->status == SubscriptionStatusEnum::ACTIVE;
    }

    public function planName(): string
    {
        return (string) ($this->plan_data['name'] ?? $this->plan?->name ?? '');
    }

}
