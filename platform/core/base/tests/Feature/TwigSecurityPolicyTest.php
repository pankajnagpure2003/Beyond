<?php

namespace Botble\Base\Tests\Feature;

use Botble\Base\Supports\TwigCompiler;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use Twig\Error\RuntimeError;
use Twig\Sandbox\SecurityNotAllowedMethodError;

/**
 * Guards finding #2: the sandbox must block dangerous method calls on objects reachable from a
 * user-editable template, while still allowing property/accessor access that real templates need.
 */
class TwigSecurityPolicyTest extends TestCase
{
    protected function render(string $template): string
    {
        return (new TwigCompiler(['autoescape' => false]))
            ->compile($template, ['order' => new TwigSecurityPolicyTestOrder()]);
    }

    public function test_property_and_accessor_access_is_allowed(): void
    {
        $this->assertSame('100.00', trim($this->render('{{ order.total }}')));
        $this->assertSame('Order #1', trim($this->render('{{ order.getName() }}')));
        // Attribute shorthand resolving to getName().
        $this->assertSame('Order #1', trim($this->render('{{ order.name }}')));
        $this->assertSame('3', trim($this->render('{{ order.items() }}')));
    }

    public function test_state_changing_method_is_blocked(): void
    {
        $this->expectException(SecurityNotAllowedMethodError::class);

        $this->render('{{ order.delete() }}');
    }

    public function test_connection_reach_method_is_blocked(): void
    {
        $this->expectException(SecurityNotAllowedMethodError::class);

        $this->render('{{ order.getConnection() }}');
    }

    public function test_arrow_callable_rce_remains_blocked(): void
    {
        $this->expectException(RuntimeError::class);

        $this->render('{{ ["id"]|map("system")|join }}');
    }

    /**
     * The denylist must not be bypassable through Twig's alternate method-call syntaxes
     * (attribute(), subscript, property-style, case changes, arrow filters).
     */
    #[DataProvider('bypassVectors')]
    public function test_denylist_resists_bypass_vectors(string $template): void
    {
        $output = null;
        $blocked = false;

        try {
            $output = $this->render($template);
        } catch (\Twig\Error\Error) {
            // SecurityNotAllowedMethodError (a Twig\Error) or a SyntaxError for invalid syntax.
            $blocked = true;
        }

        $this->assertTrue($blocked, "Bypass not blocked: {$template}");
        $this->assertNull($output, "Dangerous method leaked output: {$template}");
    }

    public static function bypassVectors(): array
    {
        return [
            'direct call' => ['{{ order.delete() }}'],
            'property-style' => ['{{ order.delete }}'],
            'attribute() function' => ["{{ attribute(order, 'delete') }}"],
            'uppercase' => ['{{ order.DELETE() }}'],
            'subscript' => ["{{ order['delete']() }}"],
            'arrow map filter' => ['{{ [order]|map(o => o.delete())|join }}'],
            'connection reach' => ['{{ order.getConnection() }}'],
            'connection via attribute()' => ["{{ attribute(order, 'getConnection') }}"],
        ];
    }
}

class TwigSecurityPolicyTestOrder
{
    public string $total = '100.00';

    public function getName(): string
    {
        return 'Order #1';
    }

    public function items(): int
    {
        return 3;
    }

    public function delete(): string
    {
        return 'DELETED';
    }

    public function getConnection(): string
    {
        return 'PDO';
    }
}
