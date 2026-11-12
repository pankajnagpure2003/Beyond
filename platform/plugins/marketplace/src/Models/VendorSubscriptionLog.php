<?php

namespace Botble\Marketplace\Models;

use Botble\ACL\Models\User;
use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorSubscriptionLog extends BaseModel
{
    public const TYPE_CLAIMED = 'claimed';

    public const TYPE_PAID = 'paid';

    public const TYPE_ADMIN_APPROVED = 'admin_approved';

    public const TYPE_ADMIN_REJECTED = 'admin_rejected';

    public const TYPE_ADMIN_ASSIGNED = 'admin_assigned';

    public const TYPE_ACTIVATED = 'activated';

    public const TYPE_RENEWED = 'renewed';

    public const TYPE_AUTO_RENEWED = 'auto_renewed';

    public const TYPE_REMINDER_SENT = 'reminder_sent';

    public const TYPE_EXPIRED = 'expired';

    public const TYPE_CANCELLED = 'cancelled';

    /** A vendor moving from one plan to another, as opposed to a first purchase. */
    public const TYPE_CHANGED_PLAN = 'changed_plan';

    /** A vendor ending their own subscription early, as opposed to admin cancellation. */
    public const TYPE_VENDOR_CANCELLED = 'vendor_cancelled';

    /** An automatic renewal was attempted and could not be charged. */
    public const TYPE_RENEWAL_FAILED = 'renewal_failed';

    protected $table = 'mp_vendor_subscription_logs';

    protected $fillable = [
        'vendor_subscription_id',
        'type',
        'data',
        'user_id',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'vendor_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function label(): string
    {
        return trans('plugins/marketplace::subscription.logs.' . $this->type);
    }
}
