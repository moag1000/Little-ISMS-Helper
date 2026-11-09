<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;

/**
 * Source-scanning tests read PHP through the parser, not through regexes: comments, string contents,
 * `use … as` aliases, group use, line breaks and `new Foo;` without parentheses are then handled by
 * the parser instead of each test's own pattern. Names come back fully qualified (NameResolver).
 *
 * Text matches on Twig, YAML or config files go through {@see self::withoutComments()} first.
 */
final class PhpSourceScanner
{
    /** @return list<string> absolute paths of every `*.php` below $dir, sorted */
    public static function phpFiles(string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        return $files;
    }

    /** @return list<Node> statements with fully qualified names; unparsable files fail loudly */
    public static function parseFile(string $path): array
    {
        return self::parseCode((string) file_get_contents($path), $path);
    }

    /** @return list<Node> */
    public static function parseCode(string $code, string $label = 'snippet'): array
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        try {
            $ast = $parser->parse($code) ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf('Cannot parse %s: %s', $label, $e->getMessage()), 0, $e);
        }
        $traverser = new NodeTraverser(new NameResolver());

        return array_values($traverser->traverse($ast));
    }

    /**
     * @template T of Node
     *
     * @param array<Node>     $ast
     * @param class-string<T> $class
     *
     * @return list<T>
     */
    public static function find(array $ast, string $class): array
    {
        /** @var list<T> $found */
        $found = (new NodeFinder())->findInstanceOf($ast, $class);

        return $found;
    }

    /** PHP, or any text with C-style/`#` comments: removes comments and doc blocks, keeps line numbers. */
    public static function withoutComments(string $php): string
    {
        $out = '';
        foreach (token_get_all($php) as $token) {
            if (\is_array($token)) {
                $out .= \in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
                    ? str_repeat("\n", substr_count($token[1], "\n"))
                    : $token[1];
            } else {
                $out .= $token;
            }
        }

        return $out;
    }

    /** Twig: `{# … #}` comments removed, line numbers kept. */
    public static function twigWithoutComments(string $twig): string
    {
        return (string) preg_replace_callback('/\{#.*?#\}/s', static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")), $twig);
    }

    /** YAML: whole-line and trailing `# …` comments removed (quotes are not tracked, good enough for config scans). */
    public static function yamlWithoutComments(string $yaml): string
    {
        return (string) preg_replace('/(^|\s)#.*$/m', '$1', $yaml);
    }

    /**
     * A short, string-concatenation-folded literal of an expression, or null when it is not constant:
     * `'a' . 'b'`, `"a"`, `'a' . self::X` (null). Lets tests see through `'/public/' . 'uploads/documents'`.
     */
    public static function constantString(Node $expr): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            $left = self::constantString($expr->left);
            $right = self::constantString($expr->right);

            return $left !== null && $right !== null ? $left . $right : null;
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $out = '';
            foreach ($expr->parts as $part) {
                if (!$part instanceof Node\InterpolatedStringPart) {
                    return null;
                }
                $out .= $part->value;
            }

            return $out;
        }

        return null;
    }

    /**
     * Every string the code spells out as a literal. Concatenation chains are flattened and adjacent constant
     * parts joined, so `$root . '/public/' . 'uploads/documents'` yields `/public/uploads/documents` even
     * though the chain as a whole is not constant. Interpolated strings contribute their literal parts.
     *
     * @param array<Node> $ast
     *
     * @return list<string>
     */
    public static function stringLiterals(array $ast): array
    {
        $strings = [];
        $nested = [];
        $concats = self::find($ast, Node\Expr\BinaryOp\Concat::class);
        foreach ($concats as $concat) {
            foreach ([$concat->left, $concat->right] as $side) {
                if ($side instanceof Node\Expr\BinaryOp\Concat) {
                    $nested[spl_object_id($side)] = true;
                }
            }
        }
        foreach ($concats as $concat) {
            if (isset($nested[spl_object_id($concat)])) {
                continue;
            }
            $run = '';
            foreach (self::flattenConcat($concat) as $operand) {
                $value = self::constantString($operand);
                if ($value !== null) {
                    $run .= $value;
                    continue;
                }
                if ($run !== '') {
                    $strings[] = $run;
                }
                $run = '';
            }
            if ($run !== '') {
                $strings[] = $run;
            }
        }
        foreach (self::find($ast, Node\Scalar\String_::class) as $string) {
            $strings[] = $string->value;
        }
        foreach (self::find($ast, Node\Scalar\InterpolatedString::class) as $interpolated) {
            $literal = '';
            foreach ($interpolated->parts as $part) {
                if ($part instanceof Node\InterpolatedStringPart) {
                    $literal .= $part->value;
                }
            }
            $strings[] = $literal;
        }

        return $strings;
    }

    /** @return list<Node\Expr> operands of a left-nested `a . b . c` chain, in order */
    private static function flattenConcat(Node\Expr $expr): array
    {
        if (!$expr instanceof Node\Expr\BinaryOp\Concat) {
            return [$expr];
        }

        return [...self::flattenConcat($expr->left), ...self::flattenConcat($expr->right)];
    }
}
