<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;

/**
 * Shared AST helpers of the gate rules (T3, 2026-11).
 *
 * The regex gates matched source text, so a comment counted as code, an alias, a line break, a constant or a
 * group use slipped past them, and every new spelling had to be caught one at a time. These rules read the
 * parsed tree PHPStan already has: names are resolved (aliases, group use, leading backslash), comments are not
 * code, constants are evaluated. Reviewed exceptions stay comment markers (`@csrf-exempt: <reason>`), read from
 * the file's comment tokens because php-parser does not attach a comment inside a method chain to any node.
 */
final class GateAst
{
    /** @var array<string, list<array{int, int, string}>> file => [startLine, endLine, text] */
    private static array $comments = [];

    public static function finder(): NodeFinder
    {
        return new NodeFinder();
    }

    /**
     * Nodes below $nodes that match $filter, without descending into closures, arrow functions or nested classes
     * when $ownScopeOnly is set (code there runs elsewhere).
     *
     * @param Node|list<Node>|array<Node> $nodes
     * @param callable(Node): bool $filter
     * @return list<Node>
     */
    public static function find(Node|array $nodes, callable $filter, bool $ownScopeOnly = false): array
    {
        $visitor = new class ($filter, $ownScopeOnly) extends NodeVisitorAbstract {
            /** @var list<Node> */
            public array $found = [];

            /** @param callable(Node): bool $filter */
            public function __construct(private $filter, private readonly bool $ownScopeOnly)
            {
            }

            public function enterNode(Node $node): ?int
            {
                if (($this->filter)($node)) {
                    $this->found[] = $node;
                }
                if ($this->ownScopeOnly && ($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction
                    || $node instanceof Node\Stmt\Class_)) {
                    return NodeTraverser::DONT_TRAVERSE_CHILDREN;
                }

                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse(is_array($nodes) ? array_values($nodes) : [$nodes]);

        return $visitor->found;
    }

    /**
     * Parent of every node below $root (php-parser keeps no parent links; PHPStan's tree must not be changed).
     *
     * @param Node|list<Node> $root
     * @return \SplObjectStorage<Node, Node>
     */
    public static function parents(Node|array $root): \SplObjectStorage
    {
        $map = new \SplObjectStorage();
        $visitor = new class ($map) extends NodeVisitorAbstract {
            /** @var list<Node> */
            private array $stack = [];

            /** @param \SplObjectStorage<Node, Node> $map */
            public function __construct(private readonly \SplObjectStorage $map)
            {
            }

            public function enterNode(Node $node): null
            {
                if ($this->stack !== []) {
                    $this->map[$node] = $this->stack[count($this->stack) - 1];
                }
                $this->stack[] = $node;

                return null;
            }

            public function leaveNode(Node $node): null
            {
                array_pop($this->stack);

                return null;
            }
        };
        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse(is_array($root) ? array_values($root) : [$root]);

        return $map;
    }

    /** Lower-case name of a call (`foo` of `->foo()`, `::foo()`, `foo()`), null for a dynamic name. */
    public static function callName(Node $node): ?string
    {
        if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall
            || $node instanceof Expr\StaticCall) {
            return $node->name instanceof Node\Identifier ? $node->name->toLowerString() : null;
        }
        if ($node instanceof Expr\FuncCall) {
            return $node->name instanceof Node\Name ? strtolower($node->name->getLast()) : null;
        }

        return null;
    }

    public static function isMethodCall(Node $node, string ...$names): bool
    {
        return ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall)
            && in_array(self::callName($node), array_map('strtolower', $names), true);
    }

    public static function isNull(?Node $node): bool
    {
        return $node instanceof Expr\ConstFetch && $node->name->toLowerString() === 'null';
    }

    public static function isFalse(?Node $node): bool
    {
        return $node instanceof Expr\ConstFetch && $node->name->toLowerString() === 'false';
    }

    public static function isTrue(?Node $node): bool
    {
        return $node instanceof Expr\ConstFetch && $node->name->toLowerString() === 'true';
    }

    /** First argument (or the named one) of a call / attribute, null when absent. */
    public static function arg(Node $call, int $position, ?string $name = null): ?Expr
    {
        $args = $call instanceof Node\Attribute ? $call->args
            : ($call instanceof Expr\CallLike && !$call->isFirstClassCallable() ? $call->getArgs() : []);
        foreach ($args as $i => $arg) {
            if ($name !== null && $arg->name !== null && $arg->name->toString() === $name) {
                return $arg->value;
            }
        }
        foreach ($args as $i => $arg) {
            if ($arg->name === null && $i === $position) {
                return $arg->value;
            }
        }

        return null;
    }

    /**
     * The constant strings an expression can be, evaluated by PHPStan (literals, class constants, `self::X`,
     * concatenation of constants, sprintf of constants). Empty when not constant.
     *
     * @return list<string>
     */
    public static function strings(?Expr $expr, Scope $scope): array
    {
        if ($expr === null) {
            return [];
        }
        $values = [];
        $type = $scope->getType($expr);
        foreach ($type->getConstantStrings() as $string) {
            $values[] = $string->getValue();
        }
        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getValueTypes() as $value) {
                foreach ($value->getConstantStrings() as $string) {
                    $values[] = $string->getValue();
                }
            }
        }

        return $values;
    }

    /**
     * Text of an expression that builds a string from parts: constant parts as they are, every other part
     * replaced by `%s` (so `'x.' . $f . ' IS NULL'` reads `x.%s IS NULL`). Null when no part is a string.
     */
    public static function template(Expr $expr, Scope $scope): ?string
    {
        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = self::template($expr->left, $scope);
            $right = self::template($expr->right, $scope);

            return $left === null && $right === null ? null : ($left ?? '%s') . ($right ?? '%s');
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            $text = '';
            foreach ($expr->parts as $part) {
                $text .= $part instanceof Node\InterpolatedStringPart ? $part->value : '%s';
            }

            return $text;
        }
        if ($expr instanceof Expr\FuncCall && self::callName($expr) === 'sprintf') {
            $format = self::arg($expr, 0);
            if ($format === null) {
                return null;
            }
            $text = self::template($format, $scope);
            // All arguments constant: fold the call (`sprintf('%s_%s', '_security', 'main')`).
            $values = [];
            foreach (array_slice($expr->getArgs(), 1) as $arg) {
                $value = $arg->unpack ? null : self::template($arg->value, $scope);
                if ($value === null || str_contains($value, '%s')) {
                    return $text;
                }
                $values[] = $value;
            }
            if ($text !== null && $values !== []) {
                try {
                    return vsprintf($text, $values);
                } catch (\ValueError) {
                    return $text;
                }
            }

            return $text;
        }
        $strings = self::strings($expr, $scope);

        return count($strings) === 1 ? $strings[0] : null;
    }

    /**
     * Class names an expression is typed as, from what is known without flow analysis: `$this->prop` (declared
     * property type), a parameter of $method, `new X`, `X::create()`-less locals assigned once from one of these.
     *
     * @return list<string>
     */
    public static function classesOf(Expr $expr, ClassReflection $class, ?Node $method, Scope $scope): array
    {
        if ($expr instanceof Expr\New_ && $expr->class instanceof Node\Name) {
            return [$expr->class->toString()];
        }
        if ($expr instanceof Expr\PropertyFetch && $expr->var instanceof Expr\Variable && $expr->var->name === 'this'
            && $expr->name instanceof Node\Identifier && $class->hasProperty($expr->name->toString())) {
            return $class->getProperty($expr->name->toString(), $scope)->getReadableType()->getReferencedClasses();
        }
        if ($expr instanceof Expr\Variable && is_string($expr->name) && $method instanceof Node\FunctionLike) {
            foreach ($method->getParams() as $param) {
                if ($param->var instanceof Expr\Variable && $param->var->name === $expr->name) {
                    return self::typeNames($param->type);
                }
            }
            $names = [];
            foreach (self::find($method, static fn (Node $n): bool => $n instanceof Expr\Assign
                && $n->var instanceof Expr\Variable && $n->var->name === $expr->name) as $assign) {
                if ($assign instanceof Expr\Assign && !$assign->expr instanceof Expr\Variable) {
                    $names = [...$names, ...self::classesOf($assign->expr, $class, $method, $scope)];
                }
            }

            return $names;
        }
        if (self::isMethodCall($expr, 'getManager', 'getEntityManager', 'getManagerForClass')) {
            // `$em = $this->doctrine->getManager();` (T4 C9: a local alias of the registry's manager)
            return ['Doctrine\Persistence\ObjectManager'];
        }
        if (self::isMethodCall($expr, 'getConnection')) {
            return ['Doctrine\DBAL\Connection'];
        }
        if ($expr instanceof Expr\MethodCall || $expr instanceof Expr\StaticCall || $expr instanceof Expr\FuncCall) {
            // A call typed by PHPStan (`$this->registry->getRepository(X::class)`, factories): its return type.
            return $scope->getType($expr)->getReferencedClasses();
        }

        return [];
    }

    /** True when the expression is typed as (a subclass of) one of $types. */
    public static function isA(Expr $expr, ClassReflection $class, ?Node $method, Scope $scope, string ...$types): bool
    {
        foreach (self::classesOf($expr, $class, $method, $scope) as $name) {
            foreach ($types as $type) {
                if ((new ObjectType($type))->isSuperTypeOf(new ObjectType($name))->yes()) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function typeNames(?Node $type): array
    {
        if ($type instanceof Node\Name) {
            return [$type->toString()];
        }
        if ($type instanceof Node\NullableType) {
            return self::typeNames($type->type);
        }
        if ($type instanceof Node\UnionType || $type instanceof Node\IntersectionType) {
            return array_merge(...array_map(self::typeNames(...), $type->types));
        }

        return [];
    }

    /**
     * Comments of the file as [startLine, endLine, text], read from the tokens (a comment inside a method chain
     * belongs to no AST node).
     *
     * @return list<array{int, int, string}>
     */
    public static function comments(string $file): array
    {
        if (!isset(self::$comments[$file])) {
            $list = [];
            $code = is_file($file) ? (string) file_get_contents($file) : '';
            foreach (\PhpToken::tokenize($code) as $token) {
                if ($token->is([T_COMMENT, T_DOC_COMMENT])) {
                    $list[] = [$token->line, $token->line + substr_count($token->text, "\n"), $token->text];
                }
            }
            self::$comments[$file] = $list;
        }

        return self::$comments[$file];
    }

    /**
     * A reviewed marker (`@csrf-exempt: <reason>`) in a comment of lines $from..$to. The marker needs text after
     * the colon, so a bare marker does not count; a marker inside a string literal is no comment and does not count.
     */
    public static function hasMarker(string $file, string $marker, int $from, int $to): bool
    {
        $pattern = '/' . preg_quote($marker, '/') . ':[ \t]*[^\s*\/]/';
        foreach (self::comments($file) as [$start, $end, $text]) {
            if ($end >= $from && $start <= $to && preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * The marker in the lead of a class member: its doc comment, comments above it and between its attributes,
     * down to the line of its name.
     */
    public static function markerOnMember(string $file, string $marker, Node $member): bool
    {
        $from = $member->getStartLine();
        foreach ($member->getComments() as $comment) {
            $from = min($from, $comment->getStartLine());
        }
        $to = $member instanceof ClassMethod || $member instanceof Node\Stmt\Class_
            ? ($member->name?->getStartLine() ?? $member->getStartLine()) : $member->getStartLine();

        return self::hasMarker($file, $marker, $from, $to);
    }

    /**
     * The marker on a statement: in its own comments, on its first line, or in the comment block directly above
     * the line (up to three lines, as the textual gate allowed).
     */
    public static function markerNear(string $file, string $marker, Node $node): bool
    {
        $line = $node->getStartLine();
        foreach ($node->getComments() as $comment) {
            $line = min($line, $comment->getStartLine());
        }

        return self::hasMarker($file, $marker, $line - 3, $node->getStartLine());
    }

    /**
     * The marker on line $line itself or in the comment block directly above it (contiguous comment lines; a line
     * of code ends the block, so a marker of the previous statement does not carry over).
     */
    public static function markerAbove(string $file, string $marker, int $line): bool
    {
        $pattern = '/' . preg_quote($marker, '/') . ':[ \t]*[^\s*\/]/';
        $byEnd = [];
        foreach (self::comments($file) as [$start, $end, $text]) {
            if ($start <= $line && $end >= $line && preg_match($pattern, $text) === 1) {
                return true;
            }
            $byEnd[$end][] = [$start, $text];
        }
        $next = $line - 1;
        while (isset($byEnd[$next])) {
            $start = $next;
            foreach ($byEnd[$next] as [$commentStart, $text]) {
                if (preg_match($pattern, $text) === 1) {
                    return true;
                }
                $start = min($start, $commentStart);
            }
            $next = $start - 1;
        }

        return false;
    }

    /** Relative path of a file inside the repository (`src/...`), for messages and allow lists. */
    public static function relative(string $file): string
    {
        $file = str_replace('\\', '/', $file);
        $pos = strrpos($file, '/src/');
        if ($pos === false) {
            $pos = strrpos($file, '/tests/');
        }

        return $pos === false ? $file : substr($file, $pos + 1);
    }

    /** Statements below a node that end the action: return, throw, exit, or a call that throws an access error. */
    public static function endsAction(Node|array $nodes): bool
    {
        return self::find($nodes, static fn (Node $n): bool => $n instanceof Node\Stmt\Return_
            || $n instanceof Expr\Throw_ || $n instanceof Expr\Exit_
            || self::isMethodCall($n, 'denyAccessUnlessGranted'), true) !== [];
    }

    /** Route attributes (`#[Route]`) of a class member. @return list<Node\Attribute> */
    public static function attributes(Node $member, string ...$classes): array
    {
        $found = [];
        if (!property_exists($member, 'attrGroups')) {
            return [];
        }
        foreach ($member->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                if (in_array(ltrim($attr->name->toString(), '\\'), $classes, true)) {
                    $found[] = $attr;
                }
            }
        }

        return $found;
    }
}
