<?php

namespace Botble\Base\Supports;

use Twig\Sandbox\SecurityNotAllowedMethodError;
use Twig\Sandbox\SecurityPolicyInterface;

/**
 * Twig security policy for user-editable templates (invoice/PDF templates, email templates)
 * rendered through {@see TwigCompiler}.
 *
 * Enabling the sandbox is what closes the arrow-callable RCE class - Twig core rejects any
 * non-Closure callable passed to filter/map/sort/reduce/find while sandboxed, so payloads like
 * {{ ["id"]|map("system")|join }} throw instead of executing.
 *
 * On top of that this policy adds defense-in-depth for method calls: templates need arbitrary
 * Eloquent property/accessor access (order.total, customer.name), so tags, filters, functions
 * and properties stay allowed, but a denylist of dangerous methods is blocked. This stops an
 * editor from reaching state-changing / code-reaching gadgets on in-scope objects, e.g.
 * {{ order.delete() }} or {{ model.getConnection().statement('...') }}.
 *
 * This is intentionally a denylist, not a complete allow-list (an allow-list is impractical
 * because legitimate templates call arbitrary model accessors and collection helpers). It
 * raises the bar rather than claiming to enumerate every gadget.
 */
class TwigSecurityPolicy implements SecurityPolicyInterface
{
    /**
     * Method names (lowercase) blocked on any object reached from a template.
     * Covers Eloquent persistence/mutation, query & connection reach (arbitrary SQL),
     * dynamic dispatch, container/service resolution, event dispatch and filesystem/process.
     *
     * @var array<int, string>
     */
    protected array $disallowedMethods = [
        // Eloquent persistence & mutation
        'delete', 'deletequietly', 'deleteorfail', 'forcedelete', 'forcedeletequietly',
        'restore', 'restorequietly', 'save', 'savequietly', 'saveorfail',
        'update', 'updatequietly', 'updateorfail', 'updateorcreate',
        'create', 'forcecreate', 'createorfirst', 'firstorcreate', 'firstornew',
        'insert', 'insertgetid', 'insertorignore', 'upsert',
        'fill', 'forcefill', 'push', 'pushquietly',
        'increment', 'incrementquietly', 'decrement', 'decrementquietly',
        'touch', 'touchquietly', 'truncate',
        'setattribute', 'setrawattributes', 'setappends', 'setrelation', 'unsetrelation',
        'makevisible', 'makehidden', 'unguard', 'reguard',
        // Query / connection reach (arbitrary SQL)
        'getconnection', 'getconnectionresolver', 'setconnection', 'resolveconnection',
        'newquery', 'newmodelquery', 'newquerywithoutscopes', 'query', 'onwriteconnection',
        'getpdo', 'getreadpdo', 'statement', 'select', 'selectone', 'unprepared', 'cursor',
        'raw', 'whereraw', 'orderbyraw', 'havingraw', 'groupbyraw', 'fromraw',
        // Dynamic dispatch
        '__call', '__callstatic', '__invoke', '__get', '__set', '__unset',
        'call', 'callstatic', 'forwardcallto', 'macro', 'mixin', 'flushmacros',
        // Container / service resolution
        'make', 'makewith', 'resolve', 'build', 'bound', 'bind', 'singleton', 'instance',
        'flush', 'terminate',
        // Event dispatch
        'dispatch', 'dispatchsync', 'dispatchnow', 'fire', 'until',
        // Filesystem / process
        'system', 'exec', 'passthru', 'shell_exec', 'proc_open', 'popen', 'eval', 'assert',
        'putenv', 'file_put_contents', 'file_get_contents', 'fopen', 'fwrite', 'unlink',
        'mkdir', 'rmdir', 'copy', 'rename', 'chmod',
    ];

    public function checkSecurity($tags, $filters, $functions): void
    {
    }

    public function checkMethodAllowed($obj, $method): void
    {
        if (in_array(strtolower($method), $this->disallowedMethods, true)) {
            throw new SecurityNotAllowedMethodError(
                sprintf('Calling "%s" method is not allowed in this template.', $method),
                $obj::class,
                $method
            );
        }
    }

    public function checkPropertyAllowed($obj, $property): void
    {
    }
}
