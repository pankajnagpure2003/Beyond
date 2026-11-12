@if (AdminHelper::themeMode() === 'system')
    {{-- Follows the OS color scheme. Runs in <head>, before the page paints, and tracks later OS changes. --}}
    <script>
        (function () {
            var root = document.documentElement;
            var query = window.matchMedia('(prefers-color-scheme: dark)');
            var apply = function () {
                root.setAttribute('data-bs-theme', query.matches ? 'dark' : 'light');
            };

            apply();

            // A live switch must skip CSS transitions: Chrome keeps the old color on
            // elements that transition a light-dark() value (links, buttons, pagination).
            query.addEventListener('change', function () {
                var style = document.createElement('style');
                style.textContent = '*,*::before,*::after{transition:none!important}';
                document.head.appendChild(style);
                apply();
                window.getComputedStyle(document.body).color;
                requestAnimationFrame(function () {
                    style.remove();
                });
            });
        })();
    </script>
@endif
