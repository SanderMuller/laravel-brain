<?php

use LaraMint\LaravelBrain\Analysis\FlowExtractor;
use LaraMint\LaravelBrain\Parser\PhpFileParser;
use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;

/** Flow steps for the first method of a class written inline. */
function flowFor(string $body): array
{
    $parsed = (new PhpFileParser)->parseCode(<<<PHP
        <?php

        namespace App;

        class Subject
        {
            public function handle()
            {
        {$body}
            }
        }
        PHP);

    $found = null;
    $traverser = new NodeTraverser;
    $traverser->addVisitor(new class($found) extends NodeVisitorAbstract
    {
        public function __construct(private mixed &$found) {}

        public function enterNode(Node $node): ?int
        {
            if ($node instanceof Node\Stmt\ClassMethod && $this->found === null) {
                $this->found = $node;
            }

            return null;
        }
    });
    $traverser->traverse($parsed['ast'] ?? []);

    return $found === null ? [] : (new FlowExtractor)->extract($found, $parsed['useMap'] ?? []);
}

it('shows the work inside a DB::transaction, not just the wrapper', function () {
    // The reported bug: a method whose body is wrapped in a transaction charted as one step and
    // nothing else, so everything the transaction actually did was invisible.
    $steps = flowFor('        \DB::transaction(function () {
            $this->payer->charge();
            $this->ledger->record();
        });');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['label'])->toContain('transaction')
        ->and($steps[0]['body'] ?? [])->toHaveCount(2);

    $labels = array_column($steps[0]['body'], 'label');
    expect($labels[0])->toContain('charge')
        ->and($labels[1])->toContain('record');
});

it('marks a step carrying a body as the type the viewer descends into', function () {
    // Both viewer renderers read `body` only for the `loop` type — a `call` carrying one is
    // dropped silently, which is the difference between fixing this and appearing to.
    $steps = flowFor('        \DB::transaction(function () {
            $this->payer->charge();
        });');

    expect($steps[0]['type'])->toBe('loop');
});

it('descends into an arrow function too', function () {
    $steps = flowFor('        \Cache::remember("k", 60, fn () => $this->repo->load());');

    expect($steps[0]['body'] ?? [])->toHaveCount(1)
        ->and($steps[0]['body'][0]['label'])->toContain('load')
        // An implicit return, as extractFromClosure() reads the same shape.
        ->and($steps[0]['body'][0]['type'])->toBe('return');
});

it('leaves a call without a callback exactly as it was', function () {
    $steps = flowFor('        $this->payer->charge();');

    expect($steps)->toHaveCount(1)
        ->and($steps[0]['type'])->toBe('call')
        ->and($steps[0])->not->toHaveKey('body');
});

it('keeps the calls a closure makes visible to the flow, nested one level', function () {
    // A collection callback is the same shape and just as invisible before this.
    $steps = flowFor('        collect($rows)->each(function ($row) {
            $this->importer->import($row);
        });');

    expect($steps[0]['body'] ?? [])->toHaveCount(1)
        ->and($steps[0]['body'][0]['label'])->toContain('import');
});

it('surfaces an N+1 that was hidden inside a callback', function () {
    // A query in a foreach inside a callback was invisible, so the N+1 marker never reached it.
    // Callback bodies are walked as not-in-a-loop, so the flag can only come from the real
    // foreach nested within — a transaction is not itself a loop and is not counted as one.
    $steps = flowFor('        \DB::transaction(function () {
            foreach ($this->rows as $row) {
                \App\Models\Thing::find($row->id);
            }
        });');

    $inner = $steps[0]['body'][0] ?? [];
    expect($inner['type'])->toBe('loop')
        ->and($inner['label'])->toContain('foreach')
        ->and($inner['body'][0]['n1'] ?? false)->toBeTrue();
});

it('does not call a callback that merely contains a query an N+1', function () {
    $steps = flowFor('        \DB::transaction(function () {
            \App\Models\Thing::find(1);
        });');

    expect($steps[0]['n1'] ?? false)->toBeFalse()
        ->and($steps[0]['body'][0]['n1'] ?? false)->toBeFalse();
});
