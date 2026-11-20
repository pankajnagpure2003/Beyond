<div class="modal fade" id="product-auth-required-modal" tabindex="-1" aria-labelledby="productAuthModalLabel" aria-hidden="true" style="z-index: 100050;">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px; margin: 1.75rem auto;">
        <div class="modal-content" style="border-radius: 18px; border: none; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); overflow: hidden; background: #ffffff;">
            <div class="modal-header border-0 pb-0" style="padding: 20px 24px 0;">
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ __('Close') }}" style="font-size: 13px; opacity: 0.7;"></button>
            </div>
            <div class="modal-body text-center" style="padding: 10px 32px 32px;">
                <div style="width: 72px; height: 72px; margin: 0 auto 18px; border-radius: 50%; background: linear-gradient(135deg, rgba(9, 137, 255, 0.12) 0%, rgba(9, 137, 255, 0.05) 100%); display: flex; align-items: center; justify-content: center; color: #0989ff; box-shadow: 0 8px 16px rgba(9, 137, 255, 0.15);">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                        <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                    </svg>
                </div>

                <h4 id="productAuthModalLabel" style="font-size: 22px; font-weight: 700; color: #010f1c; margin-bottom: 10px;">
                    {{ __('Login or Register') }}
                </h4>

                <p style="font-size: 14px; color: #55585b; line-height: 1.6; margin-bottom: 24px;">
                    {{ __('Please login to your account or register to view full product details, specifications, and prices.') }}
                </p>

                <div class="d-flex flex-column gap-2" style="gap: 12px !important;">
                    <a id="product-modal-login-btn" href="{{ route('customer.login') }}" class="tp-btn tp-btn-2 text-center w-100" style="padding: 13px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; text-decoration: none; display: block; box-shadow: 0 4px 14px rgba(9, 137, 255, 0.3);">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: -3px; margin-right: 6px;">
                            <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path>
                            <polyline points="10 17 15 12 10 7"></polyline>
                            <line x1="15" y1="12" x2="3" y2="12"></line>
                        </svg>
                        {{ __('Login') }}
                    </a>

                    @if (EcommerceHelper::isCustomerRegistrationEnabled())
                        <a id="product-modal-register-btn" href="{{ route('customer.register') }}" class="btn tp-btn-border text-center w-100" style="padding: 12px 24px; border-radius: 8px; font-size: 15px; font-weight: 600; text-decoration: none; border: 1.5px solid #0989ff; color: #0989ff; background: transparent; display: block; transition: all 0.2s ease;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="display: inline-block; vertical-align: -3px; margin-right: 6px;">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                                <circle cx="8.5" cy="7" r="4"></circle>
                                <line x1="20" y1="8" x2="20" y2="14"></line>
                                <line x1="23" y1="11" x2="17" y2="11"></line>
                            </svg>
                            {{ __('Register / Create Account') }}
                        </a>
                    @endif
                </div>

                <div class="mt-3" style="margin-top: 18px !important;">
                    <button type="button" class="btn btn-link text-muted" data-bs-dismiss="modal" style="font-size: 13px; text-decoration: none; color: #767a7d; padding: 4px 8px;">
                        {{ __('Continue Browsing') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        const baseLoginUrl = "{{ route('customer.login') }}";
        const baseRegisterUrl = "{{ route('customer.register') }}";

        function isProductDetailUrl(href) {
            if (!href || href === '#' || href.startsWith('javascript:') || href.startsWith('tel:') || href.startsWith('mailto:')) {
                return false;
            }
            try {
                const target = new URL(href, window.location.origin);
                if (target.origin !== window.location.origin) {
                    return false;
                }
                const path = target.pathname.replace(/^\/+|\/+$/g, '');
                const segments = path.split('/');
                if (segments.length >= 2 && segments[0] === 'products') {
                    return true;
                }
            } catch (err) {}
            return false;
        }

        function openAuthModal(targetUrl) {
            const modalEl = document.getElementById('product-auth-required-modal');
            if (!modalEl) return;

            const loginBtn = document.getElementById('product-modal-login-btn');
            const registerBtn = document.getElementById('product-modal-register-btn');

            if (targetUrl) {
                const redirectParam = '?redirect=' + encodeURIComponent(targetUrl);
                if (loginBtn) {
                    loginBtn.href = baseLoginUrl + redirectParam;
                }
                if (registerBtn) {
                    registerBtn.href = baseRegisterUrl + redirectParam;
                }
            }

            // Bootstrap 5 modal
            if (window.bootstrap && window.bootstrap.Modal) {
                let modalInstance = window.bootstrap.Modal.getInstance(modalEl);
                if (!modalInstance) {
                    modalInstance = new window.bootstrap.Modal(modalEl);
                }
                modalInstance.show();
            } else if (window.jQuery && typeof window.jQuery(modalEl).modal === 'function') {
                window.jQuery(modalEl).modal('show');
            } else {
                modalEl.classList.add('show');
                modalEl.style.display = 'block';
                modalEl.removeAttribute('aria-hidden');
                modalEl.setAttribute('aria-modal', 'true');
                if (!document.getElementById('product-modal-backdrop')) {
                    const backdrop = document.createElement('div');
                    backdrop.id = 'product-modal-backdrop';
                    backdrop.className = 'modal-backdrop fade show';
                    document.body.appendChild(backdrop);
                }
            }
        }

        // Global click delegator
        document.addEventListener('click', function (e) {
            // Check for Quick View button click
            const quickViewBtn = e.target.closest('[data-bs-target="#product-quick-view-modal"], .tp-product-quick-view-btn, [data-url*="ajax/quick-view"]');
            if (quickViewBtn) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();

                // Find corresponding product URL if possible
                let targetUrl = '';
                const productCard = quickViewBtn.closest('.tp-product-item, .tp-product-item-2, .tp-product-item-3, .tp-product-item-4, .tp-product-item-5');
                if (productCard) {
                    const productLink = productCard.querySelector('a[href*="/products/"]');
                    if (productLink && isProductDetailUrl(productLink.getAttribute('href'))) {
                        targetUrl = productLink.getAttribute('href');
                    }
                }
                openAuthModal(targetUrl);
                return false;
            }

            // Check for product link click
            const link = e.target.closest('a');
            if (!link) return;

            const href = link.getAttribute('href');
            if (isProductDetailUrl(href)) {
                e.preventDefault();
                e.stopPropagation();
                openAuthModal(href);
                return false;
            }
        }, true); // Use capture phase so we intercept before Bootstrap or other libraries

        // Close handling for fallback
        document.addEventListener('click', function (e) {
            if (e.target.matches('[data-bs-dismiss="modal"]') || e.target.closest('[data-bs-dismiss="modal"]')) {
                const modalEl = document.getElementById('product-auth-required-modal');
                if (modalEl && modalEl.style.display === 'block') {
                    modalEl.classList.remove('show');
                    modalEl.style.display = 'none';
                    const backdrop = document.getElementById('product-modal-backdrop');
                    if (backdrop) backdrop.remove();
                }
            }
        });
    })();
</script>
