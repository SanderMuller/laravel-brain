<?php

use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeFinder;

/**
 * Parse an inline sample and map each static-call class to its resolved FQCN.
 * The source is a string (not a fixture file), so its group-use import is not
 * normalised away by Pint.
 *
 * @return array<string, string> written name => resolved FQCN (or '(unresolved)')
 */
function staticCallResolutions(): array
{
    $code = <<<'PHP'
    <?php
    namespace App\Domain\Billing;
    use App\Models\{User, Order};
    use App\Support\Formatter as Fmt;
    class SampleResolution {
        public function run() {
            User::find(1);            // group-use import
            Order::create([]);        // group-use import
            Fmt::money(1);            // aliased import
            Sibling::make();          // same-namespace sibling
            \App\Other\Thing::go();   // fully-qualified
            self::helper();           // reserved keyword
        }
    }
    PHP;

    $parsed = (new PhpFileParser)->parseCode($code);
    $out = [];
    foreach ((new NodeFinder)->findInstanceOf($parsed['ast'], Node\Expr\StaticCall::class) as $call) {
        if ($call->class instanceof Node\Name) {
            $out[$call->class->toString()] = PhpFileParser::resolvedName($call->class) ?? '(unresolved)';
        }
    }

    return $out;
}

it('resolves a group-use imported class to its full FQCN', function () {
    $names = staticCallResolutions();

    expect($names['User'] ?? null)->toBe('App\\Models\\User');
    expect($names['Order'] ?? null)->toBe('App\\Models\\Order');
});

it('resolves an aliased import to the real class', function () {
    expect(staticCallResolutions()['Fmt'] ?? null)->toBe('App\\Support\\Formatter');
});

it('resolves a same-namespace sibling against the current namespace', function () {
    expect(staticCallResolutions()['Sibling'] ?? null)->toBe('App\\Domain\\Billing\\Sibling');
});

it('resolves a fully-qualified name consistently, without a leading backslash', function () {
    expect(staticCallResolutions()['App\\Other\\Thing'] ?? null)->toBe('App\\Other\\Thing');
});

it('reports the reserved self keyword as unresolved for the caller to handle', function () {
    expect(staticCallResolutions()['self'] ?? null)->toBe('(unresolved)');
});

it('does not abort on a semantically-invalid but parseable file (duplicate use alias)', function () {
    // NameResolver raises on a duplicate alias; the Collecting handler must
    // swallow it so the file still yields an AST instead of throwing.
    $parsed = (new PhpFileParser)->parseCode('<?php namespace A; use B\\C; use D\\E as C; class X {}');

    expect($parsed['ast'])->not->toBeNull();
});

it('returns null for a Name whose AST never went through the parser', function () {
    // Guarantees the fallback contract: callers can safely `?? useMap`.
    expect(PhpFileParser::resolvedName(new Node\Name('App\\Unparsed')))->toBeNull();
});

it('is additive — the original node is untouched, toString() unchanged', function () {
    $parsed = (new PhpFileParser)->parseCode('<?php namespace A\\B; use C\\{D}; class X { function y() { D::z(); Sib::w(); } }');
    $written = array_map(
        fn ($c) => $c->class->toString(),
        array_filter(
            (new NodeFinder)->findInstanceOf($parsed['ast'], Node\Expr\StaticCall::class),
            fn ($c) => $c->class instanceof Node\Name
        )
    );

    expect($written)->toContain('D')->toContain('Sib');
});
