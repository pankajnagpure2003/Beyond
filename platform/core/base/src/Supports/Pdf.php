<?php

namespace Botble\Base\Supports;

use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\Facade\Pdf as PdfFacade;
use Barryvdh\DomPDF\PDF as DomPDF;
use Botble\Base\Facades\BaseHelper;
use Botble\Base\Supports\Mpdf\HostRestrictedHttpClient;
use Botble\Base\Supports\Mpdf\ServiceContainer;
use Botble\Media\Facades\RvMedia;
use Closure;
use Dompdf\Adapter\CPDF;
use Dompdf\Image\Cache;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mpdf\Http\SocketHttpClient;
use Mpdf\Mpdf;
use Psr\Log\NullLogger;
use Throwable;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;
use Twig\Extension\DebugExtension;

class Pdf
{
    protected string $templatePath;

    protected ?string $destinationPath = null;

    protected array|string $paperSize;

    protected string $content;

    protected array $data = [];

    protected ?string $supportLanguage = null;

    protected ?Closure $formatContentUsing = null;

    protected array $twigExtensions = [];

    protected string $processingLibrary = 'dompdf';

    public function templatePath(string $templatePath): static
    {
        $this->templatePath = $templatePath;

        return $this;
    }

    public function destinationPath(string $destinationPath): static
    {
        $this->destinationPath = $destinationPath;

        return $this;
    }

    public function data(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function paperSize(array|string $paperSize): static
    {
        $this->paperSize = $paperSize;

        return $this;
    }

    public function paperSizeA4(): static
    {
        return $this->paperSize(CPDF::$PAPER_SIZES['a4']);
    }

    public function paperSizeHalfLetter(): static
    {
        return $this->paperSize(CPDF::$PAPER_SIZES['half-letter']);
    }

    public function supportLanguage(string $language): static
    {
        $this->supportLanguage = $language;

        return $this;
    }

    public function supportArabic(): static
    {
        return $this->supportLanguage('arabic');
    }

    public function formatContentUsing(Closure $closure): static
    {
        $this->formatContentUsing = $closure;

        return $this;
    }

    public function twigExtensions(array $extensions): static
    {
        $this->twigExtensions = $extensions;

        return $this;
    }

    public function compile(): DomPDF
    {
        // Check if libxml extension is available
        if (! extension_loaded('libxml')) {
            throw new \Exception('The libxml extension is required for PDF generation. Please install the PHP libxml extension or switch to mPDF in your settings.');
        }

        $fontsPath = storage_path('fonts');

        if (! File::isDirectory($fontsPath)) {
            File::makeDirectory($fontsPath);
        }

        $this->content = $this->getContent($this->templatePath, $this->destinationPath, true);

        Cache::$error_message = null;

        $pdf = PdfFacade::setWarnings(false)
            ->setOption('chroot', [public_path(), base_path()])
            ->setOption('tempDir', storage_path('app'))
            ->setOption('logOutputFile', false)
            ->setOption('isRemoteEnabled', $this->isRemoteEnabled());

        // Restrict remote (http/https) resource fetching to an allow-list of hosts so that
        // attacker-controlled data rendered into a template (e.g. an <img> injected via a
        // customer name/address) cannot make the server fetch arbitrary internal URLs such as
        // cloud metadata endpoints (169.254.169.254) - i.e. server-side request forgery.
        // Returning null from the filter disables the allow-list (fetch any host).
        if (($allowedRemoteHosts = $this->getAllowedRemoteHosts()) !== null) {
            $pdf->setOption('allowedRemoteHosts', $allowedRemoteHosts);
        }

        return $pdf
            ->loadHTML($this->content, 'UTF-8')
            ->setPaper($this->paperSize ?? CPDF::$PAPER_SIZES['a4']);
    }

    /**
     * Whether DomPDF is allowed to fetch remote (http/https) resources at all.
     * Kept enabled by default because templates legitimately reference the site logo and
     * media images by URL; deployments can force it off via the filter.
     */
    protected function isRemoteEnabled(): bool
    {
        return (bool) apply_filters('core_base_pdf_is_remote_enabled', true);
    }

    /**
     * Hosts DomPDF may fetch remote resources from. Defaults to the application's own host(s),
     * the configured storage disk host, and the resolved media host (including any CDN custom
     * domain) - which together cover where invoice logos/product images legitimately live.
     *
     * Return null (via the filter) to allow every host - only do this if you trust every value
     * rendered into your PDF templates, as it re-opens the SSRF surface.
     *
     * @return array<int, string>|null
     */
    protected function getAllowedRemoteHosts(): ?array
    {
        $sources = [
            url(''),
            config('app.url'),
            config('filesystems.disks.' . config('filesystems.default') . '.url'),
            // Resolves the real media base URL, honouring cloud storage CDN custom domains
            // (DO Spaces / Wasabi / Backblaze / BunnyCDN) so remote media images still load.
            $this->getMediaHostSource(),
        ];

        $hosts = [];

        foreach ($sources as $source) {
            if (! $source) {
                continue;
            }

            $host = parse_url((string) $source, PHP_URL_HOST);

            if ($host) {
                $hosts[] = strtolower($host);
            }
        }

        $hosts = array_values(array_unique($hosts)) ?: null;

        $filtered = apply_filters('core_base_pdf_allowed_remote_hosts', $hosts);

        // Explicit null = allow every host (documented opt-out).
        if ($filtered === null) {
            return null;
        }

        // Fail closed: a filter returning an unexpected type must not silently disable the
        // allow-list (DomPDF would then fetch any host; mPDF's client expects ?array). Fall
        // back to the computed hosts and normalize entries to lowercase strings.
        if (! is_array($filtered)) {
            return $hosts;
        }

        $filtered = array_values(array_filter(array_map(
            fn ($host) => is_string($host) ? strtolower($host) : null,
            $filtered
        )));

        return $filtered ?: $hosts;
    }

    /**
     * Resolve the base URL RvMedia uses for stored files (may be a CDN custom domain).
     * Returns null when the media component is unavailable or resolution fails.
     */
    protected function getMediaHostSource(): ?string
    {
        if (! class_exists(RvMedia::class)) {
            return null;
        }

        try {
            return RvMedia::url('probe.png') ?: null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build the mPDF service container that enforces the same remote-host allow-list as the
     * DomPDF path. mPDF has no native host allow-list, so we override its HTTP client with a
     * guarded decorator - closing the SSRF surface on the mPDF branch too (finding #7).
     *
     * Returns null when no restriction applies (remote enabled and allow-list opted out), in
     * which case mPDF keeps its own default HTTP client.
     */
    protected function buildMpdfSecurityContainer(): ?ServiceContainer
    {
        $remoteEnabled = $this->isRemoteEnabled();
        $allowedHosts = $this->getAllowedRemoteHosts();

        if ($remoteEnabled && $allowedHosts === null) {
            return null;
        }

        // Remote disabled => block every host; otherwise restrict to the allow-list.
        $hosts = $remoteEnabled ? $allowedHosts : [];

        $client = new HostRestrictedHttpClient(new SocketHttpClient(new NullLogger()), $hosts);

        return new ServiceContainer(['httpClient' => $client]);
    }

    public function getContent(string $templatePath, ?string $customizedPath = null, bool $compiled = false): string
    {
        if (! $customizedPath) {
            $customizedPath = storage_path('app/templates/' . basename($templatePath));
        }

        if (File::exists($customizedPath)) {
            $content = BaseHelper::getFileData($customizedPath, false);
        } else {
            $content = File::exists($templatePath) ? BaseHelper::getFileData($templatePath, false) : '';
        }

        $content = (string) $content;

        if ($content && $compiled) {
            $defaultData = [
                'settings' => [
                    'font_family' => apply_filters('pdf_font_family', 'DejaVu Sans'),
                    'font_css' => apply_filters('pdf_font_css', null),
                    'extra_css' => apply_filters('pdf_extra_css', null),
                    'header_html' => apply_filters('pdf_header_html', null),
                    'footer_html' => apply_filters('pdf_footer_html', null),
                ],
            ];

            $data = array_replace_recursive($defaultData, $this->data);

            switch ($this->supportLanguage) {
                case 'bangladesh':
                    $data['settings']['font_family'] = 'FreeSerif';
                    $data['settings']['header_html'] .= view('core/base::pdf.style-bangladesh')->render();

                    break;
                case 'chinese':
                    $data['settings']['font_family'] = 'msyh';
                    $data['settings']['header_html'] .= view('core/base::pdf.style-chinese')->render();

                    break;
            }

            $content = $this->compileContent($content, $data);

            if ($this->formatContentUsing) {
                $content = call_user_func($this->formatContentUsing, $content);
            }

            if ($this->getProcessingLibrary() == 'dompdf') {
                $currencies = [
                    '₼' => 'azeri-manat',
                    '₹' => 'indian-rupee',
                    '৳' => 'bangladeshi-taka',
                    '₺' => 'turkish-lira',
                    '﷼' => 'iranian-rial',
                    '₾' => 'georgian-lari',
                    '₿' => 'bitcoin',
                ];
            } else {
                $currencies = [
                    '﷼' => 'iranian-rial',
                ];
            }

            foreach ($currencies as $currency => $icon) {
                if (! str_contains($content, $currency)) {
                    continue;
                }

                $svgPath = base_path("platform/core/base/public/images/pdf-symbols/{$icon}.svg");

                if (! is_file($svgPath)) {
                    continue;
                }

                $img = sprintf(
                    '<img src="%s" alt="%s" style="height: 0.85em; vertical-align: middle;">',
                    e($svgPath),
                    e($icon)
                );

                // Glue the image to the adjacent number so DomPDF can't wrap
                // the currency symbol onto its own line.
                $content = preg_replace_callback(
                    '/([0-9][0-9.,]*\h?)?' . preg_quote($currency, '/') . '(\h?[0-9][0-9.,]*)?/u',
                    function (array $matches) use ($img): string {
                        $before = $matches[1] ?? '';
                        $after = $matches[2] ?? '';

                        if ($before === '' && $after === '') {
                            return $img;
                        }

                        return '<span style="white-space: nowrap;">' . $before . $img . $after . '</span>';
                    },
                    $content
                );
            }

            if ($this->getProcessingLibrary() == 'dompdf' && $this->supportLanguage === 'arabic') {
                $content = $this->compileArabic($content);
            }

            $this->logBlockedRemoteHosts($content);
        }

        return $content;
    }

    /**
     * Warn (in the application log) when the rendered PDF references remote resources from hosts
     * the allow-list will block, so an admin can see which host to permit via the filter instead
     * of silently getting a missing image. Runs for both the DomPDF and mPDF engines.
     */
    protected function logBlockedRemoteHosts(string $content): void
    {
        $remoteEnabled = $this->isRemoteEnabled();
        $allowedHosts = $this->getAllowedRemoteHosts();

        // Unrestricted (remote enabled + allow-list opted out) => nothing is blocked.
        if ($remoteEnabled && $allowedHosts === null) {
            return;
        }

        if (! preg_match_all('#https?://[^\s"\'<>()]+#i', $content, $matches)) {
            return;
        }

        $blocked = [];

        foreach ($matches[0] as $url) {
            $host = parse_url($url, PHP_URL_HOST);

            if (! $host) {
                continue;
            }

            $host = strtolower($host);

            if (! $remoteEnabled || ($allowedHosts !== null && ! in_array($host, $allowedHosts, true))) {
                $blocked[$host] = true;
            }
        }

        if (! $blocked) {
            return;
        }

        Log::warning(sprintf(
            'PDF generation blocked remote resources from host(s): %s. If these are legitimate, '
            . 'allow them via the "core_base_pdf_allowed_remote_hosts" filter (or re-enable remote '
            . 'fetching via "core_base_pdf_is_remote_enabled").',
            implode(', ', array_keys($blocked))
        ));
    }

    protected function compileContent(string $content, array $data = []): string
    {
        $twigCompiler = new TwigCompiler([
            'autoescape' => false,
            'debug' => true,
        ]);

        $twigCompiler->addExtension(new DebugExtension());

        foreach ($this->twigExtensions as $extension) {
            $twigCompiler->addExtension($extension);
        }

        return $twigCompiler->compile($content, $data);
    }

    protected function compileArabic(string $content): string
    {
        if (! class_exists(Arabic::class)) {
            return $content;
        }

        $arabic = new Arabic();
        $p = $arabic->arIdentify($content);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            try {
                $utf8ar = $arabic->utf8Glyphs(substr($content, $p[$i - 1], $p[$i] - $p[$i - 1]));
                $content = substr_replace($content, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
            } catch (Throwable) {
                continue;
            }
        }

        return $content;
    }

    public function setProcessingLibrary(string $library): static
    {
        $this->processingLibrary = $library;

        return $this;
    }

    public function getProcessingLibrary(): string
    {
        return $this->processingLibrary;
    }

    public function compileMpdf(string $fileName, string $mode = 'D'): ?string
    {
        $format = $this->convertPaperSizeForMpdf($this->paperSize ?? 'A4');

        $config = [
            'mode' => 'utf-8',
            'format' => $format,
            'tempDir' => storage_path('app'),
        ];

        $mpdf = new Mpdf($config, $this->buildMpdfSecurityContainer());

        $mpdf->autoLangToFont = true;

        $inlineCss = new CssToInlineStyles();

        $content = $this->getContent($this->templatePath, $this->destinationPath, true);

        $content = $inlineCss->convert($content);

        $mpdf->WriteHTML($content);

        return $mpdf->Output($fileName, $mode);
    }

    protected function convertPaperSizeForMpdf(array|string $paperSize): array|string
    {
        if (is_string($paperSize)) {
            return $paperSize;
        }

        if (count($paperSize) === 4) {
            $widthMm = ($paperSize[2] - $paperSize[0]) * 0.352778;
            $heightMm = ($paperSize[3] - $paperSize[1]) * 0.352778;

            return [round($widthMm, 2), round($heightMm, 2)];
        }

        if (count($paperSize) === 2) {
            return $paperSize;
        }

        return 'A4';
    }

    public function stream(string $fileName = 'document.pdf'): Response|string|null
    {
        if ($this->getProcessingLibrary() == 'mpdf' || ! extension_loaded('libxml')) {
            return $this->compileMpdf($fileName, 'I');
        }

        return $this->compile()->stream($fileName);
    }

    public function download(string $fileName): Response|string|null
    {
        if ($this->getProcessingLibrary() == 'mpdf' || ! extension_loaded('libxml')) {
            return $this->compileMpdf($fileName);
        }

        return $this->compile()->download($fileName);
    }

    public function save(string $filePath): DomPDF|string|null
    {
        if ($this->getProcessingLibrary() == 'mpdf' || ! extension_loaded('libxml')) {
            return $this->compileMpdf($filePath, 'F');
        }

        return $this->compile()->save($filePath);
    }
}
