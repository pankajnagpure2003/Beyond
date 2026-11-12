<?php

namespace Botble\Marketplace\Models;

use Botble\Base\Models\BaseModel;
use Botble\Ecommerce\Models\Concerns\HasUniqueCode;
use Botble\Ecommerce\Models\Customer;
use Botble\Marketplace\Enums\SubscriptionInvoiceStatusEnum;
use Botble\Payment\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * A billing document for one subscription charge.
 *
 * Everything on the row is a snapshot taken when the charge happened — narrative,
 * tax rate, totals and billing address. Nothing here is recalculated on read, so an
 * admin editing the tax rate or a vendor moving house cannot rewrite an issued invoice.
 */
class VendorSubscriptionInvoice extends BaseModel
{
    use HasUniqueCode;

    protected $table = 'mp_vendor_subscription_invoices';

    protected $fillable = [
        'code',
        'vendor_subscription_id',
        'customer_id',
        'title',
        'description',
        'sub_total',
        'tax_rate',
        'tax_amount',
        'amount',
        'currency',
        'status',
        'payment_id',
        'paid_at',
        'billing_name',
        'billing_email',
        'billing_phone',
        'billing_address',
        'billing_country',
        'billing_state',
        'billing_city',
        'billing_zip_code',
        'billing_tax_id',
    ];

    protected $casts = [
        'status' => SubscriptionInvoiceStatusEnum::class,
        'sub_total' => 'float',
        'tax_rate' => 'float',
        'tax_amount' => 'float',
        'amount' => 'float',
        'paid_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $invoice): void {
            if (! $invoice->code) {
                $invoice->code = static::generateUniqueCode();
            }
        });
    }

    /**
     * Numbered independently of ec_invoices so the subscription series never interleaves
     * with order invoices.
     *
     * The counter comes from the highest code already issued in this series, not from the
     * table id: ids gap whenever an unrelated row is removed or an insert fails, and an
     * invoice series with holes in it is exactly what an accountant queries.
     */
    public static function generateUniqueCode(): string
    {
        $prefix = static::codePrefix();

        $highest = static::query()
            ->where('code', 'LIKE', $prefix . '%')
            ->orderByDesc(DB::raw('LENGTH(code)'))
            ->orderByDesc('code')
            ->value('code');

        $next = $highest
            ? ((int) substr((string) $highest, strlen($prefix))) + 1
            : 1;

        // Walk forward past anything already taken; a number is never reused, so a
        // cancelled invoice keeps its place in the series. HasUniqueCode still retries the
        // insert if two requests land on the same number concurrently.
        do {
            $code = $prefix . str_pad((string) $next, 6, '0', STR_PAD_LEFT);
            $next++;
        } while (static::query()->where('code', $code)->exists());

        return $code;
    }

    protected static function codePrefix(): string
    {
        return (string) setting('marketplace_subscription_invoice_prefix') ?: 'SUB-';
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(VendorSubscription::class, 'vendor_subscription_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class, 'payment_id');
    }

    public function scopeForCustomer(Builder $query, int|string $customerId): Builder
    {
        return $query->where('customer_id', $customerId);
    }

    /** @return array<string, string|null> the billing block, for the PDF template */
    public function billingAddress(): array
    {
        return [
            'name' => $this->billing_name,
            'email' => $this->billing_email,
            'phone' => $this->billing_phone,
            'address' => $this->billing_address,
            'country' => $this->billing_country,
            'state' => $this->billing_state,
            'city' => $this->billing_city,
            'zip_code' => $this->billing_zip_code,
            'tax_id' => $this->billing_tax_id,
        ];
    }
}
