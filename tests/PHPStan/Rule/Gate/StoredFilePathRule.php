<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Gate 94 (T4: AST rule, replaces check_stored_file_paths.py). A file path stored on a record is data (S19: a
 * restored '../../.env.local' was read, '../var/setup_complete.lock' deleted). Only StoredFilePath and
 * ProtectedUploads turn it into a file, inside their folder.
 *
 * The stored-path columns are exactly the keys of `StoredFilePath::FIELDS` (read through static reflection, so a
 * new column is covered the day it is registered); their getters (`getLogoPath()`, also `->{'getLogoPath'}()`),
 * public properties (`->logoPath`) and every local variable assigned from them are stored paths. A stored path
 * fails when it reaches a path sink outside StoredFilePath/ProtectedUploads: concatenation and interpolation,
 * sprintf/vsprintf/implode/join, `Path::join()`/`canonicalize()`/`makeAbsolute()`, a file function
 * (file_get_contents, unlink, fopen, readfile, copy, rename, file_exists, is_file, filesize, …), a Filesystem call or
 * `new File`/`BinaryFileResponse`/`SplFileInfo`. Passing it to a method (`$storedFilePath->resolve($p)`) is the
 * intended use; `basename()` makes it one file name. `'/public/' . $var` stays refused except the fixed upload
 * folder property. A reviewed exception carries `// @stored-path-ok: <reason>` on the line or directly above it.
 *
 * @implements Rule<InClassNode>
 */
final class StoredFilePathRule implements Rule
{
    private const REGISTRY = 'App\Service\Upload\StoredFilePath';
    private const EXEMPT = [self::REGISTRY, 'App\Service\Upload\ProtectedUploads'];
    /** Used when the registry cannot be read (the rule's own tests without the app). */
    private const FALLBACK_FIELDS = ['filePath', 'logoPath', 'profilePicture', 'pdfPath', 'storedFilename'];
    private const FILE_FUNCTIONS = ['file_get_contents', 'file_put_contents', 'unlink', 'fopen', 'file', 'readfile',
        'copy', 'rename', 'file_exists', 'is_file', 'is_readable', 'is_writable', 'filesize', 'filemtime', 'realpath',
        'mime_content_type', 'md5_file', 'sha1_file', 'hash_file', 'touch', 'chmod', 'mkdir', 'rmdir', 'is_dir',
        'getimagesize', 'exif_read_data', 'parse_ini_file', 'simplexml_load_file', 'opendir', 'scandir', 'glob',
        'sprintf', 'vsprintf', 'implode', 'join'];
    private const PATH_STATICS = ['join', 'canonicalize', 'makeabsolute', 'makerelative', 'normalize'];
    private const FILESYSTEM_CALLS = ['remove', 'exists', 'copy', 'rename', 'dumpfile', 'appendtofile', 'readfile',
        'mirror', 'touch', 'chmod', 'symlink'];
    private const FILE_CLASSES = ['Symfony\Component\HttpFoundation\File\File', 'Symfony\Component\HttpFoundation\BinaryFileResponse',
        'SplFileInfo', 'SplFileObject', 'Symfony\Component\HttpFoundation\File\UploadedFile'];

    /** @var list<string>|null lower-case column names */
    private ?array $fields = null;

    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection()->getName();
        if (!str_starts_with($class, 'App\\') || str_starts_with($class, 'App\Tests\\') || in_array($class, self::EXEMPT, true)) {
            return [];
        }
        $file = $scope->getFile();
        $errors = [];
        foreach ($node->getOriginalNode()->getMethods() as $method) {
            $aliases = $this->aliases($method, $scope);
            $reported = [];
            foreach (GateAst::find($method, static fn (Node $n): bool => true) as $n) {
                $sink = $this->sinkOperands($n, $scope);
                if ($sink === null) {
                    continue;
                }
                foreach ($sink as $operand) {
                    if ($this->carries($operand, $aliases, $scope) && !isset($reported[$n->getStartLine()])
                        && !GateAst::markerAbove($file, '@stored-path-ok', $n->getStartLine())) {
                        $reported[$n->getStartLine()] = true;
                        $errors[] = $this->error($file, $n, 'a stored file path reaches ' . $this->sinkName($n)
                            . ' — resolve it with StoredFilePath / ProtectedUploads.');
                        break;
                    }
                }
                if ($n instanceof Expr\BinaryOp\Concat && $this->publicJoin($n)
                    && !GateAst::markerAbove($file, '@stored-path-ok', $n->getStartLine()) && !isset($reported[$n->getStartLine()])) {
                    $reported[$n->getStartLine()] = true;
                    $errors[] = $this->error($file, $n, "a variable joined onto '/public/' — only the fixed upload folder.");
                }
            }
        }

        return $errors;
    }

    /** @return list<string> */
    private function fields(): array
    {
        if ($this->fields !== null) {
            return $this->fields;
        }
        $fields = [];
        if ($this->reflectionProvider->hasClass(self::REGISTRY)) {
            $registry = $this->reflectionProvider->getClass(self::REGISTRY);
            if ($registry->hasConstant('FIELDS')) {
                foreach ($registry->getConstant('FIELDS')->getValueType()->getConstantArrays() as $array) {
                    foreach ($array->getKeyTypes() as $key) {
                        foreach ($key->getConstantStrings() as $string) {
                            $parts = explode('::', $string->getValue());
                            $fields[] = strtolower(end($parts));
                        }
                    }
                }
            }
        }

        return $this->fields = $fields === [] ? array_map('strtolower', self::FALLBACK_FIELDS) : array_values(array_unique($fields));
    }

    private function isStoredPath(Node $n, Scope $scope): bool
    {
        if ($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) {
            $name = $n->name instanceof Node\Identifier ? $n->name->toString() : (string) GateAst::template($n->name, $scope);

            return str_starts_with(strtolower($name), 'get') && in_array(strtolower(substr($name, 3)), $this->fields(), true)
                && $n->getArgs() === [];
        }
        if ($n instanceof Expr\PropertyFetch || $n instanceof Expr\NullsafePropertyFetch) {
            return $n->name instanceof Node\Identifier && !($n->var instanceof Expr\Variable && $n->var->name === 'this')
                && in_array($n->name->toLowerString(), $this->fields(), true);
        }

        return false;
    }

    /**
     * Local variables of the method holding a stored path (`$p = $e->getPdfPath();`, `$q = $p;`), to a fixed point.
     *
     * @return list<string>
     */
    private function aliases(Node\Stmt\ClassMethod $method, Scope $scope): array
    {
        $aliases = [];
        do {
            $before = count($aliases);
            foreach (GateAst::find($method, static fn (Node $n): bool => $n instanceof Expr\Assign
                && $n->var instanceof Expr\Variable && is_string($n->var->name)) as $assign) {
                \assert($assign instanceof Expr\Assign && $assign->var instanceof Expr\Variable);
                if (!in_array($assign->var->name, $aliases, true) && $this->carries($assign->expr, $aliases, $scope)) {
                    $aliases[] = (string) $assign->var->name;
                }
            }
        } while (count($aliases) > $before);

        return $aliases;
    }

    /**
     * The value (not a call that receives it) is a stored path or built from one.
     *
     * @param list<string> $aliases
     */
    private function carries(?Node $n, array $aliases, Scope $scope): bool
    {
        return match (true) {
            $n === null => false,
            $this->isStoredPath($n, $scope) => true,
            $n instanceof Expr\Variable => is_string($n->name) && in_array($n->name, $aliases, true),
            $n instanceof Expr\BinaryOp\Concat => $this->carries($n->left, $aliases, $scope) || $this->carries($n->right, $aliases, $scope),
            $n instanceof Node\Scalar\InterpolatedString => array_filter($n->parts, fn (Node $p): bool => $this->carries($p, $aliases, $scope)) !== [],
            $n instanceof Expr\Ternary => $this->carries($n->if ?? $n->cond, $aliases, $scope) || $this->carries($n->else, $aliases, $scope),
            $n instanceof Expr\BinaryOp\Coalesce => $this->carries($n->left, $aliases, $scope) || $this->carries($n->right, $aliases, $scope),
            $n instanceof Expr\Cast\String_ => $this->carries($n->expr, $aliases, $scope),
            $n instanceof Expr\Array_ => array_filter($n->items, fn (?Node $i): bool => $i instanceof Node\ArrayItem && $this->carries($i->value, $aliases, $scope)) !== [],
            $n instanceof Expr\FuncCall && in_array(GateAst::callName($n), ['trim', 'ltrim', 'rtrim', 'str_replace', 'strtolower', 'urldecode'], true)
                => array_filter($n->getArgs(), fn (Node\Arg $a): bool => $this->carries($a->value, $aliases, $scope)) !== [],
            default => false,
        };
    }

    /** @return list<Node>|null the operands of a path sink, null when $n is none */
    private function sinkOperands(Node $n, Scope $scope): ?array
    {
        if ($n instanceof Expr\BinaryOp\Concat) {
            return [$n->left, $n->right];
        }
        if ($n instanceof Node\Scalar\InterpolatedString) {
            return array_values(array_filter($n->parts, static fn (Node $p): bool => !$p instanceof Node\InterpolatedStringPart));
        }
        if ($n instanceof Expr\AssignOp\Concat) {
            return [$n->expr];
        }
        $args = static fn (Expr\CallLike $c): array => $c->isFirstClassCallable() ? [] : array_map(static fn (Node\Arg $a): Expr => $a->value, $c->getArgs());
        if ($n instanceof Expr\FuncCall && in_array(GateAst::callName($n), self::FILE_FUNCTIONS, true)) {
            return $args($n);
        }
        if ($n instanceof Expr\StaticCall && in_array(GateAst::callName($n), self::PATH_STATICS, true)
            && $n->class instanceof Node\Name && str_ends_with($n->class->toString(), 'Path')) {
            return $args($n);
        }
        if (($n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall)
            && in_array(GateAst::callName($n), self::FILESYSTEM_CALLS, true)
            && in_array('Symfony\Component\Filesystem\Filesystem', $scope->getType($n->var)->getObjectClassNames(), true)) {
            return $args($n);
        }
        if ($n instanceof Expr\New_ && $n->class instanceof Node\Name
            && in_array(ltrim($n->class->toString(), '\\'), self::FILE_CLASSES, true)) {
            return $args($n);
        }

        return null;
    }

    private function sinkName(Node $n): string
    {
        return match (true) {
            $n instanceof Expr\BinaryOp\Concat, $n instanceof Expr\AssignOp\Concat => 'a concatenation',
            $n instanceof Node\Scalar\InterpolatedString => 'an interpolated string',
            $n instanceof Expr\New_ => 'a file object',
            default => (GateAst::callName($n) ?? 'a call') . '()',
        };
    }

    private function publicJoin(Expr\BinaryOp\Concat $n): bool
    {
        $left = $n->left instanceof Expr\BinaryOp\Concat ? $n->left->right : $n->left;
        if (!$left instanceof Node\Scalar\String_ || !str_ends_with($left->value, '/public/')) {
            return false;
        }
        $right = $n->right;

        return ($right instanceof Expr\Variable || $right instanceof Expr\PropertyFetch)
            && !($right instanceof Expr\PropertyFetch && $right->name instanceof Node\Identifier
                && $right->name->toString() === 'uploadsDirectory');
    }

    private function error(string $file, Node $at, string $message): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf('Gate 94 [%s]: %s', GateAst::relative($file), $message))
            ->identifier('gate94.storedPath')->line($at->getStartLine())->build();
    }
}
