<?php

namespace Botble\Marketplace\Http\Controllers\Fronts;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Services\GenerateSubscriptionInvoiceService;
use Illuminate\Http\Request;

/**
 * Serves a vendor their own subscription invoice PDF.
 *
 * The ownership check is the whole point of this controller: an invoice carries the
 * vendor's billing address and tax ID, so serving one on the strength of a guessable
 * route parameter alone would leak another vendor's details.
 */
class SubscriptionInvoiceController extends BaseController
{
    public function __invoke(
        VendorSubscriptionInvoice $invoice,
        Request $request,
        GenerateSubscriptionInvoiceService $generateInvoiceService
    ) {
        abort_unless((int) $invoice->customer_id === (int) auth('customer')->id(), 404);

        $generateInvoiceService->invoice($invoice);

        if ($request->input('type') === 'print') {
            return $generateInvoiceService->stream();
        }

        return $generateInvoiceService->download();
    }
}
