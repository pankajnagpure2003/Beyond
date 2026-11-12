@once
    <script>
        var lazyLoadShortcodeBlocks = function() {
            document.querySelectorAll('.shortcode-lazy-loading').forEach(function(element) {
                var name = element.getAttribute('data-name');
                var attributes = JSON.parse(element.getAttribute('data-attributes'));
                var shortcodeId = element.getAttribute('data-shortcode-id');

                const url = '{{ route('public.ajax.render-ui-block') }}';

                {{--
                    Do NOT send a CSRF token from here, and do not print csrf_token() into this
                    page. The endpoint already opts out of CSRF verification
                    (routes/fronts.php: withoutMiddleware(PreventRequestForgery::class)) and only
                    renders public content, so a token adds nothing.

                    It also has a cost: PublicCacheControl treats any page whose body contains a
                    CSRF marker as uncacheable, so inlining a token here silently made every page
                    with a lazy shortcode non-cacheable.
                    See platform/core/base/src/Http/Middleware/PublicCacheControl.php
                --}}

                const urlParams = new URLSearchParams(window.location.search);
                const refLang = urlParams.get('ref_lang');

                document.body.classList.add('lazy-loading-active');

                const requestBody = {
                    name,
                    shortcodeId,
                    attributes: {
                        ...attributes
                    }
                };

                if (refLang) {
                    requestBody.ref_lang = refLang;
                }

                fetch(url, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'Accept': 'application/json'
                        },
                        body: JSON.stringify(requestBody)
                    })
                    .then(response => {
                        if (!response.ok) {
                            throw new Error('Network response was not ok');
                        }
                        return response.json();
                    })
                    .then(({
                        error,
                        data
                    }) => {
                        if (error) {
                            return;
                        }

                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = data;
                        const firstChild = tempDiv.firstElementChild;
                        if (firstChild) {
                            firstChild.classList.add('shortcode-lazy-loading-loaded');

                            const shortcodeId = element.getAttribute('data-shortcode-id');
                            if (shortcodeId && !firstChild.getAttribute('data-shortcode-id')) {
                                firstChild.setAttribute('data-shortcode-id', shortcodeId);
                                firstChild.setAttribute('data-shortcode-name', name);
                            }

                            tempDiv.querySelectorAll('.wow').forEach(function(el) {
                                el.classList.remove('wow');
                                el.style.visibility = 'visible';
                            });

                            data = tempDiv.innerHTML;
                        }

                        const scripts = tempDiv.querySelectorAll('script');

                        element.outerHTML = data;

                        scripts.forEach(function(oldScript) {
                            const newScript = document.createElement('script');
                            if (oldScript.src) {
                                newScript.src = oldScript.src;
                            } else {
                                newScript.textContent = oldScript.textContent;
                            }
                            Array.from(oldScript.attributes).forEach(function(attr) {
                                newScript.setAttribute(attr.name, attr.value);
                            });
                            document.body.appendChild(newScript);
                        });

                        document.dispatchEvent(new CustomEvent('shortcode.loaded', {
                            detail: {
                                name,
                                attributes,
                                html: data
                            }
                        }));

                        if (typeof Theme !== 'undefined' && typeof Theme.lazyLoadInstance !== 'undefined') {
                            Theme.lazyLoadInstance.update()
                        }

                        setTimeout(function() {
                            const remainingLoaders = document.querySelectorAll(
                                '.shortcode-lazy-loading');
                            if (remainingLoaders.length === 0) {
                                document.body.classList.remove('lazy-loading-active');
                            }
                        }, 100);
                    })
                    .catch(error => {
                        console.error('Fetch error:', error);
                        document.body.classList.remove('lazy-loading-active');
                    });
            });
        };

        window.addEventListener('load', function() {
            lazyLoadShortcodeBlocks();
        });
    </script>
@endonce
