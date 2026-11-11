<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 92, PHP part (T3: AST rule, replaces R3/R4 of check_raw_output.py; the Twig rules R1/R2 stay there).
 *
 *   R3  A PhpWord document comes only from `DocxDocumentFactory::create()` (output escaping on): no `new PhpWord`
 *       elsewhere, no `TemplateProcessor`, never `setOutputEscapingEnabled(false)`.
 *   R4  `new Twig\Markup(...)` and Twig callables declared `is_safe` / `is_safe_callback` carry a
 *       `// @markup-safe: <reason>` comment on the line or directly above it that says how record text is
 *       escaped first. Class names are resolved (aliases, group use, line breaks), and `Markup::class` as a value
 *       (`$c = Markup::class; new $c(...)`) is refused outright.
 *   T4: the marker counts only on the line itself or in the comment block directly above it (a marked
 *       `new Markup` no longer covers a second one two lines below), `'Twig\Markup'` as a string is refused like
 *       `Markup::class`, and escaper safe classes (`setSafeClasses()`, `addSafeClass()`) need the marker.
 *
 * @implements Rule<InClassNode>
 */
final class MarkupOutputRule implements Rule
{
    private const MARKUP = 'Twig\Markup';
    private const PHPWORD = 'PhpOffice\PhpWord\PhpWord';
    private const TEMPLATE_PROCESSOR = 'PhpOffice\PhpWord\TemplateProcessor';
    private const DOCX_FACTORY = 'App\Service\Export\DocxDocumentFactory';
    /** The filters that turn reviewed HTML / encoded JSON into Markup (`|fa_html`, `|fa_json`). */
    private const MARKUP_FILTERS = 'App\Twig\OutputEscapingExtension';

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();
        if (!str_starts_with($class, 'App\\')) {
            return [];
        }
        $file = $scope->getFile();
        $rel = GateAst::relative($file);
        $errors = [];
        $error = static function (string $id, Node $at, string $message) use (&$errors, $rel): void {
            $errors[] = RuleErrorBuilder::message(sprintf('Gate 92 [%s]: %s', $rel, $message))
                ->identifier('gate92.' . $id)->line($at->getStartLine())->build();
        };

        foreach (GateAst::find($node->getOriginalNode(), static fn (Node $n): bool => true) as $n) {
            $name = match (true) {
                $n instanceof Expr\New_ && $n->class instanceof Node\Name => ltrim($n->class->toString(), '\\'),
                $n instanceof Expr\StaticCall && $n->class instanceof Node\Name => ltrim($n->class->toString(), '\\'),
                $n instanceof Expr\ClassConstFetch && $n->class instanceof Node\Name => ltrim($n->class->toString(), '\\'),
                default => null,
            };
            if ($n instanceof Expr\New_ && $name === self::PHPWORD && $class !== self::DOCX_FACTORY) {
                $error('phpWord', $n, 'PhpWord document created outside DocxDocumentFactory::create() — output '
                    . 'escaping may be off.');
            }
            if ($name === self::TEMPLATE_PROCESSOR) {
                $error('templateProcessor', $n, 'PhpWord TemplateProcessor — setValue() writes XML unescaped unless '
                    . 'output escaping is on; use DocxDocumentFactory.');
            }
            if (GateAst::callName($n) === 'setoutputescapingenabled'
                && !GateAst::isTrue(GateAst::arg($n, 0))) {
                $error('escapingOff', $n, 'PhpWord output escaping switched off (or set from a variable).');
            }
            if ($class === self::MARKUP_FILTERS) {
                continue;
            }
            if ($n instanceof Expr\New_ && $name === self::MARKUP && !GateAst::markerAbove($file, '@markup-safe', $n->getStartLine())) {
                $error('markup', $n, '`new Markup()` — record text would print as HTML; escape it first and say how '
                    . 'in a `// @markup-safe: <reason>` comment on this or the previous line.');
            }
            if ($n instanceof Expr\ClassConstFetch && $name === self::MARKUP && $n->name instanceof Node\Identifier
                && $n->name->toLowerString() === 'class' && !GateAst::markerAbove($file, '@markup-safe', $n->getStartLine())) {
                $error('markupClass', $n, '`Markup::class` as a value builds Markup out of sight of this gate — '
                    . 'use `new Markup()` with a `// @markup-safe: <reason>` comment.');
            }
            if ($n instanceof Node\Scalar\String_ && strcasecmp(ltrim($n->value, '\\'), self::MARKUP) === 0
                && !GateAst::markerAbove($file, '@markup-safe', $n->getStartLine())) {
                // T4 K2: `$c = 'Twig\Markup'; new $c($x)` — the class by its name as a string.
                $error('markupClass', $n, 'Twig\\Markup named as a string builds Markup out of sight of this gate.');
            }
            if (GateAst::isMethodCall($n, 'setSafeClasses', 'addSafeClass')
                && !GateAst::markerAbove($file, '@markup-safe', $n->getStartLine())) {
                // T4 K1: a class declared safe for the escaper prints its __toString() unescaped everywhere.
                $error('safeClass', $n, 'escaper safe class (setSafeClasses()/addSafeClass()) — its string form is '
                    . 'printed unescaped; add `// @markup-safe: <reason>`.');
            }
            if ($this->isSafeOption($n) && !GateAst::markerAbove($file, '@markup-safe', $n->getStartLine())) {
                $error('isSafe', $n, 'Twig callable marked is_safe — its output is printed unescaped; add '
                    . '`// @markup-safe: <reason>` once it escapes record text itself.');
            }
        }

        return $errors;
    }

    /** `'is_safe' => [...]`, `'is_safe_callback' => ...` or the named arguments `isSafe:` / `isSafeCallback:`. */
    private function isSafeOption(Node $n): bool
    {
        if ($n instanceof Node\ArrayItem && $n->key instanceof Node\Scalar\String_) {
            return in_array($n->key->value, ['is_safe', 'is_safe_callback'], true)
                && !($n->value instanceof Expr\Array_ && $n->value->items === []);
        }

        return $n instanceof Node\Arg && $n->name !== null
            && in_array($n->name->toLowerString(), ['issafe', 'issafecallback'], true);
    }
}
