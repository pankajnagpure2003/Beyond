{{-- Kept visually subordinate and last on the page: irreversible, and auto-renew is the
     answer for almost everyone who lands here. --}}
<div class="card mt-3 border-danger">
    <div class="card-header">
        <h4 class="card-title mb-0 text-danger">
            {{ trans('plugins/marketplace::subscription.vendor.cancel_title') }}
        </h4>
    </div>
    <div class="card-body">
        <p class="text-muted">{{ trans('plugins/marketplace::subscription.vendor.cancel_description') }}</p>

        <form method="POST" action="{{ route('marketplace.vendor.subscriptions.cancel') }}" class="row g-2 align-items-end">
            @csrf
            <div class="col-sm-auto">
                <label class="form-label" for="cancel-confirmation">
                    {{ trans('plugins/marketplace::subscription.vendor.cancel_confirm_label', [
                        'word' => trans('plugins/marketplace::subscription.vendor.cancel_confirm_word'),
                    ]) }}
                </label>
                {{-- The placeholder repeats the word the controller checks for: an empty
                     box beside a red button otherwise reads as a second button. --}}
                <input
                    type="text"
                    class="form-control"
                    id="cancel-confirmation"
                    name="confirmation"
                    placeholder="{{ trans('plugins/marketplace::subscription.vendor.cancel_confirm_word') }}"
                    autocomplete="off"
                    required
                >
            </div>
            <div class="col-sm-auto">
                <button type="submit" class="btn btn-danger">
                    {{ trans('plugins/marketplace::subscription.vendor.cancel') }}
                </button>
            </div>
        </form>
    </div>
</div>
