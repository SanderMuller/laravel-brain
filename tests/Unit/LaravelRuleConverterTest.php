<?php

use LaraMint\LaravelBrain\OpenApi\LaravelRuleConverter;

it('converts pipe-form rules', function () {
    $result = (new LaravelRuleConverter)->convert('required|email|max:255');

    expect($result['required'])->toBeTrue();
    expect($result['schema'])->toMatchArray([
        'type' => 'string',
        'format' => 'email',
        'maxLength' => 255,
    ]);
});

it('converts array-form rules from the rules extractor', function () {
    // Mirrors the output shape of ValidationRulesExtractor (pretty-printed AST values)
    $result = (new LaravelRuleConverter)->convert("'required', 'string', 'max:255'");

    expect($result['required'])->toBeTrue();
    expect($result['schema'])->toMatchArray([
        'type' => 'string',
        'maxLength' => 255,
    ]);
});

it('handles integer min/max as numeric bounds', function () {
    $result = (new LaravelRuleConverter)->convert('integer|min:1|max:10');

    expect($result['schema'])->toMatchArray([
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 10,
    ]);
});

it('captures in: as enum', function () {
    $result = (new LaravelRuleConverter)->convert('required|in:draft,published,archived');

    expect($result['required'])->toBeTrue();
    expect($result['schema']['enum'])->toBe(['draft', 'published', 'archived']);
});

it('preserves unknown rules under x-laravel-rules', function () {
    $result = (new LaravelRuleConverter)->convert('required|exists:users,id');

    expect($result['required'])->toBeTrue();
    expect($result['schema']['x-laravel-rules'])->toBe(['exists:users,id']);
});

it('marks nullable rules', function () {
    $result = (new LaravelRuleConverter)->convert('nullable|string');

    expect($result['required'])->toBeFalse();
    expect($result['schema'])->toMatchArray([
        'type' => 'string',
        'nullable' => true,
    ]);
});
