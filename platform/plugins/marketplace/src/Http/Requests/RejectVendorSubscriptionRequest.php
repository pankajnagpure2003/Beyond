<?php

namespace Botble\Marketplace\Http\Requests;

use Botble\Support\Http\Requests\Request;

class RejectVendorSubscriptionRequest extends Request
{
    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:400',
        ];
    }
}
