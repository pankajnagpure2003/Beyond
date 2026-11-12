<?php

namespace Botble\Base\Supports\Mpdf;

use Mpdf\Container\ContainerInterface;

/**
 * Minimal mPDF service container used only to override selected internal services (currently
 * the HTTP client, to enforce the remote-host allow-list). Any service not explicitly provided
 * falls back to mPDF's own default implementation.
 */
final class ServiceContainer implements ContainerInterface
{
    /**
     * @param array<string, mixed> $services
     */
    public function __construct(protected array $services = [])
    {
    }

    public function has($id): bool
    {
        return isset($this->services[$id]);
    }

    public function get($id): mixed
    {
        return $this->services[$id] ?? null;
    }
}
