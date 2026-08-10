<?php

use LaraMint\LaravelBrain\Analysis\ValidationRulesExtractor;

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

it('answers again once the file has changed', function () {
    // The verdict is remembered per file, so the thing worth pinning is that it stops being
    // remembered when the file it describes is no longer the same file.
    $file = sys_get_temp_dir().'/brain-rules-'.uniqid().'.php';
    $extractor = new ValidationRulesExtractor;

    // The two versions are the same length on purpose: the key is path + mtime + size, so a
    // size that changes with the content would hide a broken mtime component.
    file_put_contents($file, "<?php\n\nclass Plain\n{\n    public function ruled() { return []; }\n}\n");
    touch($file, time() - 60);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    file_put_contents($file, "<?php\n\nclass Plain\n{\n    public function rules() { return []; }\n}\n");
    touch($file, time() - 30);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeTrue();

    unlink($file);
});

it('does not remember a file that is still being written', function () {
    // A file whose mtime is not yet in the past can change again without changing its key, so
    // caching its verdict would let the old answer stand. The mtime is set ahead explicitly
    // rather than relying on the write landing in the same second as the read, which would make
    // this pass or fail on where the clock happens to be.
    $file = sys_get_temp_dir().'/brain-rules-unsettled-'.uniqid().'.php';
    $extractor = new ValidationRulesExtractor;

    // Same length again, and the same mtime — so path, mtime and size all match across the
    // edit and only the refusal to remember an unsettled file can give the right answer.
    file_put_contents($file, "<?php\n\nclass Draft\n{\n    public function ruled() { return []; }\n}\n");
    touch($file, time() + 5);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeFalse();

    file_put_contents($file, "<?php\n\nclass Draft\n{\n    public function rules() { return []; }\n}\n");
    touch($file, time() + 5);
    expect($extractor->hasNonAbstractRulesMethod($file))->toBeTrue();

    unlink($file);
});
