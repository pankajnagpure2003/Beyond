<?php

namespace Botble\Marketplace\Http\Requests;

use Botble\Base\Enums\BaseStatusEnum;
use Botble\Marketplace\Enums\SubscriptionDurationUnitEnum;
use Botble\Marketplace\Models\SubscriptionPlan;
use Botble\Support\Http\Requests\Request;
use Illuminate\Validation\Rule;

class SubscriptionPlanRequest extends Request
{
    public function rules(): array
    {
        $optionRules = [];

        foreach (array_keys(SubscriptionPlan::defaultOptions()) as $option) {
            // Commission rates are money, not quotas: they must accept decimals. -1 means
            // "inherit the global rate", hence min:-1 rather than min:0 across the board.
            $optionRules['options.' . $option] = match ($option) {
                'commission_percentage' => 'sometimes|nullable|numeric|min:-1|max:100',
                'commission_fixed' => 'sometimes|nullable|numeric|min:-1',
                default => 'sometimes|nullable|integer|min:-1',
            };
        }

        return array_merge([
            'name' => 'required|string|max:191',
            'description' => 'nullable|string|max:2000',
            'price' => 'required|numeric|min:0',
            'duration_value' => 'required|integer|min:1|max:1000',
            'duration_unit' => ['required', Rule::in(SubscriptionDurationUnitEnum::values())],
            'is_default' => 'sometimes|in:0,1',
            'order' => 'sometimes|integer|min:0',
            'status' => ['required', Rule::in(BaseStatusEnum::values())],
            'options' => 'sometimes|array',
        ], $optionRules);
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            // A paid default plan would silently charge every vendor who has no subscription.
            if ($this->boolean('is_default') && (float) $this->input('price') > 0) {
                $validator->errors()->add(
                    'is_default',
                    trans('plugins/marketplace::subscription.plans.default_must_be_free')
                );
            }
        });
    }
}
