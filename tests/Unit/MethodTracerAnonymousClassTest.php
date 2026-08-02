<?php

declare(strict_types=1);

use LaraMint\LaravelBrain\Analysis\MethodTracer;

it('traces the real method body, not a same-named method of an anonymous class inside it', function () {
    $root = sys_get_temp_dir().'/brain-anon-'.uniqid();
    mkdir($root.'/app/Jobs', 0o777, true);

    // handle() dispatches a job and returns an anonymous class that also declares handle().
    // Collecting the class's methods must stop at the real declaration: the anonymous one is
    // not App\Outer's method, and treating it as such loses everything the real body does.
    file_put_contents($root.'/app/Outer.php', <<<'PHP'
        <?php
        namespace App;

        use App\Jobs\ProcessThing;

        class Outer
        {
            public function handle()
            {
                ProcessThing::dispatch();

                return new class
                {
                    public function handle() {}
                };
            }
        }
        PHP);
    file_put_contents($root.'/app/Jobs/ProcessThing.php', <<<'PHP'
        <?php
        namespace App\Jobs;

        class ProcessThing {}
        PHP);

    $edges = (new MethodTracer)->traceMethod('App\Outer', 'handle', ['App' => [$root.'/app']], $root);

    expect(array_map(fn ($edge) => $edge->calleeFqcn, $edges))->toContain('App\Jobs\ProcessThing');

    exec('rm -rf '.escapeshellarg($root));
});
