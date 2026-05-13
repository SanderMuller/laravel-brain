<?php

declare(strict_types=1);

namespace LaraMint\LaravelBrain\OpenApi;

/**
 * Best-effort conversion of Laravel validation rules to a JSON Schema fragment.
 *
 * Accepts rules in either of the two forms the ValidationRulesExtractor yields:
 *  - pipe string:  "required|email|max:255"
 *  - array text:   "'required', 'string', 'max:255'"
 *
 * Unknown rules are preserved under x-laravel-rules so they're not lost.
 */
final class LaravelRuleConverter
{
    /**
     * @return array{schema: array<string, mixed>, required: bool}
     */
    public function convert(string $rulesExpression): array
    {
        $tokens = $this->tokenize($rulesExpression);

        $schema = [];
        $required = false;
        $unknown = [];

        foreach ($tokens as $token) {
            [$name, $arg] = $this->splitRule($token);

            switch ($name) {
                case 'required':
                    $required = true;
                    break;
                case 'nullable':
                    $schema['nullable'] = true;
                    break;
                case 'string':
                    $schema['type'] = 'string';
                    break;
                case 'integer':
                case 'int':
                    $schema['type'] = 'integer';
                    break;
                case 'numeric':
                case 'decimal':
                    $schema['type'] = 'number';
                    break;
                case 'boolean':
                case 'bool':
                    $schema['type'] = 'boolean';
                    break;
                case 'array':
                    $schema['type'] = 'array';
                    break;
                case 'email':
                    $schema['type'] = 'string';
                    $schema['format'] = 'email';
                    break;
                case 'url':
                    $schema['type'] = 'string';
                    $schema['format'] = 'uri';
                    break;
                case 'uuid':
                    $schema['type'] = 'string';
                    $schema['format'] = 'uuid';
                    break;
                case 'date':
                    $schema['type'] = 'string';
                    $schema['format'] = 'date';
                    break;
                case 'date_format':
                case 'date-format':
                    $schema['type'] = 'string';
                    $schema['format'] = $arg ?? 'date-time';
                    break;
                case 'min':
                    if ($arg !== null && is_numeric($arg)) {
                        $type = $schema['type'] ?? null;
                        if ($type === 'string' || $type === 'array') {
                            $schema[$type === 'array' ? 'minItems' : 'minLength'] = (int) $arg;
                        } else {
                            $schema['minimum'] = $arg + 0;
                        }
                    }
                    break;
                case 'max':
                    if ($arg !== null && is_numeric($arg)) {
                        $type = $schema['type'] ?? null;
                        if ($type === 'string' || $type === 'array') {
                            $schema[$type === 'array' ? 'maxItems' : 'maxLength'] = (int) $arg;
                        } else {
                            $schema['maximum'] = $arg + 0;
                        }
                    }
                    break;
                case 'in':
                    if ($arg !== null) {
                        $schema['enum'] = array_map('trim', explode(',', $arg));
                    }
                    break;
                case 'regex':
                    if ($arg !== null) {
                        $schema['type'] = $schema['type'] ?? 'string';
                        $schema['pattern'] = $arg;
                    }
                    break;
                default:
                    if ($token !== '') {
                        $unknown[] = $token;
                    }
            }
        }

        if ($unknown !== []) {
            $schema['x-laravel-rules'] = $unknown;
        }

        if (! isset($schema['type']) && ! isset($schema['nullable'])) {
            $schema['type'] = 'string';
        }

        return ['schema' => $schema, 'required' => $required];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $expr): array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return [];
        }

        // Strip surrounding quotes if the whole expression is a single quoted string.
        $unquoted = $this->unquote($expr);

        // Case 1: pipe form, e.g. "required|email|max:255"
        if (strpos($unquoted, '|') !== false && strpos($unquoted, "'") === false) {
            return array_values(array_filter(array_map('trim', explode('|', $unquoted)), static fn (string $s): bool => $s !== ''));
        }

        // Case 2: comma-separated quoted tokens, e.g. "'required', 'string', 'max:255'"
        $parts = $this->splitTopLevelCommas($expr);
        $out = [];
        foreach ($parts as $p) {
            $token = trim($this->unquote(trim($p)));
            if ($token !== '') {
                // A single token may itself be a pipe string ("required|email")
                if (strpos($token, '|') !== false && strpos($token, "'") === false) {
                    foreach (explode('|', $token) as $sub) {
                        $sub = trim($sub);
                        if ($sub !== '') {
                            $out[] = $sub;
                        }
                    }
                } else {
                    $out[] = $token;
                }
            }
        }

        return $out;
    }

    private function unquote(string $s): string
    {
        $len = strlen($s);
        if ($len >= 2) {
            $first = $s[0];
            $last = $s[$len - 1];
            if (($first === "'" && $last === "'") || ($first === '"' && $last === '"')) {
                return substr($s, 1, $len - 2);
            }
        }

        return $s;
    }

    /**
     * @return list<string>
     */
    private function splitTopLevelCommas(string $expr): array
    {
        $out = [];
        $buf = '';
        $depth = 0;
        $inSingle = false;
        $inDouble = false;

        for ($i = 0, $n = strlen($expr); $i < $n; $i++) {
            $ch = $expr[$i];

            if (! $inDouble && $ch === "'" && ($i === 0 || $expr[$i - 1] !== '\\')) {
                $inSingle = ! $inSingle;
            } elseif (! $inSingle && $ch === '"' && ($i === 0 || $expr[$i - 1] !== '\\')) {
                $inDouble = ! $inDouble;
            } elseif (! $inSingle && ! $inDouble) {
                if ($ch === '(' || $ch === '[') {
                    $depth++;
                } elseif ($ch === ')' || $ch === ']') {
                    $depth--;
                } elseif ($ch === ',' && $depth === 0) {
                    $out[] = $buf;
                    $buf = '';

                    continue;
                }
            }

            $buf .= $ch;
        }

        if ($buf !== '') {
            $out[] = $buf;
        }

        return $out;
    }

    /**
     * @return array{0: string, 1: ?string}
     */
    private function splitRule(string $token): array
    {
        $pos = strpos($token, ':');
        if ($pos === false) {
            return [strtolower($token), null];
        }

        return [strtolower(substr($token, 0, $pos)), substr($token, $pos + 1)];
    }
}
