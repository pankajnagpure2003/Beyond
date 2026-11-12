<?php

namespace Tests\Feature\Base;

use Illuminate\Foundation\Console\RouteListCommand;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Guards against a plugin command silently taking over a framework command name.
 *
 * Illuminate\Console\Command::__construct() prefers an inherited $signature over the
 * subclass's own $name. When Laravel 13 moved RouteListCommand from $name to $signature,
 * RouteTranslationsListCommand - which extends it - inherited the name "route:list" and
 * replaced the framework command with one that requires a locale argument, so
 * `php artisan route:list` failed with "The locale is required." and route:trans:list
 * disappeared. Any future framework command that gains a $signature can do this again.
 *
 * The language plugin is not always active - CI runs the suite a second time with every
 * plugin deactivated - so the two plugin-specific tests skip when its command is absent.
 */
class ArtisanCommandNameCollisionTest extends TestCase
{
    private const TRANSLATED_ROUTE_LIST_COMMAND = 'Botble\\Language\\Commands\\RouteTranslationsListCommand';

    protected function skipWithoutLanguagePlugin(): void
    {
        if (! class_exists(self::TRANSLATED_ROUTE_LIST_COMMAND)) {
            $this->markTestSkipped('The language plugin is not activated.');
        }
    }

    public function test_route_list_is_still_laravels_own_command(): void
    {
        $this->skipWithoutLanguagePlugin();

        $commands = Artisan::all();

        $this->assertArrayHasKey('route:list', $commands);
        $this->assertInstanceOf(RouteListCommand::class, $commands['route:list']);
        $this->assertNotInstanceOf(self::TRANSLATED_ROUTE_LIST_COMMAND, $commands['route:list']);
        $this->assertFalse(
            $commands['route:list']->getDefinition()->hasArgument('locale'),
            'route:list must not require a locale argument.'
        );
    }

    public function test_translated_route_list_command_keeps_its_own_name(): void
    {
        $this->skipWithoutLanguagePlugin();

        $commands = Artisan::all();

        $this->assertArrayHasKey('route:trans:list', $commands);
        $this->assertInstanceOf(self::TRANSLATED_ROUTE_LIST_COMMAND, $commands['route:trans:list']);
        $this->assertTrue($commands['route:trans:list']->getDefinition()->hasArgument('locale'));

        // The whole point of renaming after parent::__construct() instead of declaring a
        // fresh $signature: the parent's filter options stay inherited, no duplication.
        foreach (['json', 'method', 'name', 'path', 'sort'] as $option) {
            $this->assertTrue(
                $commands['route:trans:list']->getDefinition()->hasOption($option),
                "route:trans:list lost the inherited --$option option."
            );
        }
    }

    public function test_no_registered_command_is_registered_under_a_foreign_name(): void
    {
        $mismatched = [];

        foreach (Artisan::all() as $name => $command) {
            if ($command->getName() !== $name && ! in_array($name, $command->getAliases(), true)) {
                $mismatched[] = sprintf('%s registered as "%s" but named "%s"', $command::class, $name, $command->getName());
            }
        }

        $this->assertSame([], $mismatched);
    }
}
