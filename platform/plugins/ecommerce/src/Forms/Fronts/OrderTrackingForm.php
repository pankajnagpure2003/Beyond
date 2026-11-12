<?php

namespace Botble\Ecommerce\Forms\Fronts;

use Botble\Base\Forms\Fields\TextField;
use Botble\Base\Forms\FormAbstract;
use Botble\Ecommerce\Facades\EcommerceHelper;
use Botble\Ecommerce\Forms\Fronts\FieldOptions\TextFieldOption;
use Botble\Ecommerce\Http\Requests\Fronts\OrderTrackingRequest;

class OrderTrackingForm extends CardForm
{
    public static function formTitle(): string
    {
        return __('Track Your Order');
    }

    public function setup(): void
    {
        parent::setup();

        $this
            ->setMethod('GET')
            ->setValidatorClass(OrderTrackingRequest::class)
            ->setUrl(route('public.orders.tracking'))
            ->icon('ti ti-truck-delivery')
            ->heading(__('Track Your Order'))
            ->description(__('To track your order please enter your Order ID in the box below and press the "Track" button. This was given to you on your receipt and in the confirmation email you should have received.'))
            ->when(
                theme_option('order_tracking_background'),
                fn (self $form, string $background) => $form->banner($background)
            )
            ->add(
                'order_id',
                TextField::class,
                TextFieldOption::make()
                    ->label(__('Order ID'))
                    ->required()
                    ->placeholder(__('Order ID'))
                    ->icon('ti ti-id-badge-2')
            )
            ->when(EcommerceHelper::isOrderTrackingUsingPhone(), function (FormAbstract $form): void {
                $form->add(
                    'phone',
                    TextField::class,
                    TextFieldOption::make()
                        ->label(__('Phone number'))
                        ->required()
                        ->placeholder(__('Phone number'))
                        ->icon('ti ti-phone')
                        // `type="tel"` is always overwritten by Form::text, so the numeric
                        // keypad on mobile is requested through inputmode instead.
                        ->addAttribute('inputmode', 'tel')
                        ->addAttribute('autocomplete', 'tel')
                );
            }, function (FormAbstract $form): void {
                $form->add(
                    'email',
                    TextField::class,
                    TextFieldOption::make()
                        ->label(__('Email address'))
                        ->required()
                        ->placeholder(__('Email address'))
                        ->icon('ti ti-mail')
                        ->addAttribute('inputmode', 'email')
                        ->addAttribute('autocomplete', 'email')
                );
            })
            ->submitButton(__('Track Now'), 'ti ti-arrow-narrow-right');
    }
}
