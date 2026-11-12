<?php

namespace Botble\Marketplace\Http\Requests;

use Botble\Support\Http\Requests\Request;

class VendorSubscriptionRequest extends Request
{
    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:ec_customers,id',
            'subscription_plan_id' => 'required|exists:mp_subscription_plans,id',
            'ends_at' => 'nullable|date',
            'auto_renew' => 'sometimes|in:0,1',
        ];
    }
}
