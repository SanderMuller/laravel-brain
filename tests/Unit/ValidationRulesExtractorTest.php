<?php

use LaraMint\LaravelBrain\Analysis\ValidationRulesExtractor;
use LaraMint\LaravelBrain\Parser\PhpFileParser;

it('detects a concrete rules() method', function () {
    $extractor = new ValidationRulesExtractor;
    expect($extractor->hasNonAbstractRulesMethod(fixture('/laravel-project/app/Http/Requests/ProfileStoreRequest.php')))->toBeTrue();
});

it('extracts validation rows from rules() return arrays', function () {
    $extractor = new ValidationRulesExtractor;
    $rows = $extractor->extractFromFile(fixture('/laravel-project/app/Http/Requests/ProfileStoreRequest.php'));

    expect($rows)->toBeNonEmptyArray();

    $fields = array_column($rows, 'field');
    expect($fields)->toBe(["'name'", "'email'"]);

    $rulesText = array_column($rows, 'rules');
    expect($rulesText)->toBe([
        "'required', 'string', 'max:255'",
        "'required|email'",
    ]);
});

/**
 * Write $code to a temp file and pin its mtime, clearing PHP's per-process stat cache so a test
 * reads what it just wrote rather than what it last stat'd. The same discipline, and the same
 * reason, as writeSource() in PhpFileParserSharedCacheTest.
 */
function writeRulesSource(string $path, string $code, int $mtime): void
{
    file_put_contents($path, $code);
    touch($path, $mtime);
    clearstatcache(true, $path);
}

/** A class with a rules() method, and one without, written to the same byte length. */
function rulesSource(bool $withRules): string
{
    $method = $withRules ? 'rules' : 'ruled';

    return "<?php\n\nclass Subject\n{\n    public function {$method}() { return []; }\n}\n";
}

it('answers again once the file has changed', function () {
    // The verdict is remembered per file, so the thing worth pinning is that it stops being
    // remembered when the file it describes is no longer the same file. Both versions are the
    // same length on purpose: the key is path + mtime + size, so a size that moved with the
    // content would hide a broken mtime component.
    $file = sys_get_temp_dir().'/brain-rules-'.uniqid().'.php';
    $extractor = new ValidationRulesExtractor;

    writeRulesSource($file, rulesSource(false), time() - 60);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    writeRulesSource($file, rulesSource(true), time() - 30);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeTrue();

    unlink($file);
});

it('does not remember a file that is still being written', function () {
    // A file whose mtime is not yet in the past can change again without changing its key, so
    // caching its verdict would let the old answer stand. The mtime is pinned ahead rather than
    // left to the write landing in the same second as the read, which would make this pass or
    // fail on where the clock happens to be. Path, mtime and size are all unchanged across the
    // edit, so only the refusal to remember an unsettled file can give the right answer.
    $file = sys_get_temp_dir().'/brain-rules-unsettled-'.uniqid().'.php';
    $extractor = new ValidationRulesExtractor;
    $unsettled = time() + 2;

    writeRulesSource($file, rulesSource(false), $unsettled);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    writeRulesSource($file, rulesSource(true), $unsettled);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeTrue();

    unlink($file);
});

it('remembers that a file has no rules(), not just that it has one', function () {
    // The memo stores booleans, and the usual answer is false — so a membership test that treats
    // a stored false as absent would still look correct (it would just re-derive everything) and
    // quietly give back the whole saving. This pins that a false is remembered.
    //
    // The second version keeps the same path, mtime and size, which is the contract of this key
    // and of the shared parse cache it mirrors: same key, same answer. The parse cache is cleared
    // in between so that only the memo can produce the earlier answer.
    $file = sys_get_temp_dir().'/brain-rules-memo-'.uniqid().'.php';
    $extractor = new ValidationRulesExtractor;
    $mtime = time() - 60;

    writeRulesSource($file, rulesSource(false), $mtime);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    writeRulesSource($file, rulesSource(true), $mtime);
    PhpFileParser::clearSharedCache();

    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    unlink($file);
});

it('answers identically for source handed over and for the file it came from', function () {
    // Driving both variants from one fixture is what stops them drifting: a change that reaches
    // only one of them fails here. The rows themselves are pinned by the file-variant test above,
    // so this asserts equality rather than restating them.
    $file = fixture('/laravel-project/app/Http/Requests/ProfileStoreRequest.php');
    $code = (string) file_get_contents($file);
    $extractor = new ValidationRulesExtractor;

    expect($extractor->hasNonAbstractRulesMethodInCode($code))
        ->toBe($extractor->hasNonAbstractRulesMethod($file))
        ->toBeTrue()
        ->and($extractor->extractFromCode($code))->toBe($extractor->extractFromFile($file))
        ->toBeNonEmptyArray();
});

it('reads source that never was a file', function () {
    // The point of the entry point: a caller holding source with no path to hand over.
    $extractor = new ValidationRulesExtractor;

    $rows = $extractor->extractFromCode(<<<'PHP'
        <?php

        namespace App\Http\Requests;

        class InMemoryRequest extends FormRequest
        {
            public function rules()
            {
                return ['title' => 'required|string'];
            }
        }
        PHP);

    expect($rows)->toBe([['field' => "'title'", 'rules' => "'required|string'"]]);
});

it('says no rather than throwing when the source does not parse', function () {
    $extractor = new ValidationRulesExtractor;

    expect($extractor->hasNonAbstractRulesMethodInCode('<?php class Broken {'))->toBeFalse()
        ->and($extractor->extractFromCode('<?php class Broken {'))->toBe([])
        ->and($extractor->hasNonAbstractRulesMethodInCode(''))->toBeFalse()
        ->and($extractor->extractFromCode(''))->toBe([]);
});

it('does not mistake an abstract rules() for one it can read', function () {
    // The file variant has always drawn this line; the code variant has to draw it in the same
    // place, and it is the one branch of the shared path a happy-path test never reaches.
    $extractor = new ValidationRulesExtractor;

    $code = <<<'PHP'
        <?php

        abstract class BaseRequest
        {
            abstract public function rules();
        }
        PHP;

    expect($extractor->hasNonAbstractRulesMethodInCode($code))->toBeFalse()
        ->and($extractor->extractFromCode($code))->toBe([]);
});

it('separates a rules() it cannot read from no rules() at all', function () {
    // Empty rows are not absence. A rules() with nothing readable in it answers true to the one
    // question and [] to the other, and a caller that collapses the two loses the distinction
    // between "no validation here" and "validation this cannot enumerate".
    $extractor = new ValidationRulesExtractor;

    $unreadable = '<?php class Unreadable { public function rules() { $rules = []; } }';
    $absent = '<?php class Absent { public function other() { return []; } }';

    expect($extractor->hasNonAbstractRulesMethodInCode($unreadable))->toBeTrue()
        ->and($extractor->extractFromCode($unreadable))->toBe([])
        ->and($extractor->hasNonAbstractRulesMethodInCode($absent))->toBeFalse()
        ->and($extractor->extractFromCode($absent))->toBe([]);
});
