<section class="order-tracking">
    {{-- The form renders its own container and card, see `plugins/ecommerce::forms.card`. --}}
    {!! $form->renderForm() !!}

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-xl-6 col-lg-8">
                @if ($order)
                    @include(EcommerceHelper::viewPath('includes.order-tracking-detail'))
                @elseif (request()->filled(['order_id', EcommerceHelper::isOrderTrackingUsingPhone() ? 'phone' : 'email']))
                    <p class="text-center text-danger">{{ __('Order not found!') }}</p>
                @endif
            </div>
        </div>
    </div>
</section>
