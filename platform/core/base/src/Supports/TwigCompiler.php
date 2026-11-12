<?php

namespace Botble\Base\Supports;

use Twig\Environment;
use Twig\Extension\ExtensionInterface;
use Twig\Extension\SandboxExtension;

/**
 * @mixin \Twig\Environment
 */
class TwigCompiler
{
    protected TwigLoader $loader;

    protected Environment $env;

    public function __construct(array $options = [])
    {
        $this->loader = new TwigLoader();
        $this->env = new Environment($this->loader, $options);

        $this->env->addExtension(new TwigExtension());

        // Enable sandbox mode so user-editable templates cannot pass arbitrary
        // PHP callables (e.g. {{ [...]|map('system') }}) to Twig's filter/map/
        // sort/reduce filters, which would otherwise allow remote code execution.
        $this->env->addExtension(new SandboxExtension(new TwigSecurityPolicy(), true));
    }

    public function compile(string $content, array $data = []): string
    {
        $this->loader->setContent($content);

        return $this->env->render($this->loader->hashedContent(), $data);
    }

    public function addExtension(ExtensionInterface $extension): self
    {
        $this->env->addExtension($extension);

        return $this;
    }

    public function getExtensions(): array
    {
        return $this->env->getExtensions();
    }

    public function __call(string $name, array $arguments)
    {
        return $this->env->{$name}(...$arguments);
    }
}
