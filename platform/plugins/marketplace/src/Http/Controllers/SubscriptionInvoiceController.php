<?php

namespace Botble\Marketplace\Http\Controllers;

use Botble\Base\Http\Controllers\BaseController;
use Botble\Marketplace\Models\VendorSubscriptionInvoice;
use Botble\Marketplace\Services\GenerateSubscriptionInvoiceService;
use Illuminate\Http\Request;

/**
 * Serves any subscription invoice PDF to an administrator.
 *
 * Access is gated by the route's permission rather than by ownership — an admin who can
 * see the subscription list can see the paperwork behind it.
 */
class SubscriptionInvoiceController extends BaseController
{
    public function __invoke(
        VendorSubscriptionInvoice $invoice,
        Request $request,
        GenerateSubscriptionInvoiceService $generateInvoiceService
    ) {
        $generateInvoiceService->invoice($invoice);

        if ($request->input('type') === 'print') {
            return $generateInvoiceService->stream();
        }

        return $generateInvoiceService->download();
    }
}
