<?php

namespace Botble\Marketplace\Models;

use Botble\Base\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One reserved slot for one notification about one subscription.
 *
 * The unique index on (vendor_subscription_id, type, dedupe_key) *is* the dedupe
 * mechanism: a row is inserted to claim the right to send before the mail is handed over,
 * so a crash between claim and send loses the notification rather than repeating it.
 * See SubscriptionNotificationLedger.
 */
class VendorSubscriptionNotification extends BaseModel
{
    /** An expiry warning; the dedupe key is the reminder window in days. */
    public const TYPE_EXPIRING = 'expiring';

    /** An auto-renew attempt that failed; the dedupe key is the period's end date. */
    public const TYPE_RENEWAL_FAILED = 'renewal_failed';

    protected $table = 'mp_vendor_subscription_notifications';

    /** claimed_at / sent_at carry the timing; created_at / updated_at would be noise. */
    public $timestamps = false;

    protected $fillable = [
        'vendor_subscription_id',
        'type',
        'dedupe_key',
        'claimed_at',
        'sent_at',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'vendor_subscription_id');
    }
}
