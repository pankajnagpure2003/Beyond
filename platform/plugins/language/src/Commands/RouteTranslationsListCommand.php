<?php

namespace Botble\Language\Commands;

use Botble\Language\LanguageManager;
use Botble\Language\Traits\TranslatedRouteCommandContext;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Foundation\Console\RouteListCommand;
use Illuminate\Routing\Router;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;

#[AsCommand(name: 'route:trans:list')]
class RouteTranslationsListCommand extends RouteListCommand implements PromptsForMissingInput
{
    use TranslatedRouteCommandContext;

    protected $description = 'List all registered routes for specific locales';

    public function __construct(Router $router)
    {
        // The parent declares a $signature ('route:list ...') since Laravel 13, and
        // Command::__construct() lets an inherited signature win over $name - which made
        // this command replace the framework's own route:list. Let the parent build its
        // full option set first, then rename and add the locale argument on top.
        parent::__construct($router);

        // The description is already applied by Command::__construct(); only the name
        // and the extra argument need correcting here.
        $this
            ->setName('route:trans:list')
            ->addArgument('locale', InputArgument::REQUIRED, 'The locale to list routes for.');
    }

    public function handle(): int
    {
        $locale = $this->argument('locale');

        if (! $this->isSupportedLocale($locale)) {
            $this->components->error("Unsupported locale: '$locale'.");

            return self::FAILURE;
        }

        $this->loadFreshApplicationRoutes($locale);

        parent::handle();

        return self::SUCCESS;
    }

    protected function loadFreshApplicationRoutes(string $locale): void
    {
        $app = require $this->getBootstrapPath() . '/app.php';

        $key = LanguageManager::ENV_ROUTE_KEY;

        if (function_exists('putenv')) {
            putenv("{$key}={$locale}");
        }

        $app->make(Kernel::class)->bootstrap();

        if (function_exists('putenv')) {
            putenv("{$key}=");
        }

        $this->router = $app['router'];
    }
}
