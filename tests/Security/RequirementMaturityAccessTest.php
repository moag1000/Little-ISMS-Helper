<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Tests\Support\PhpSourceScanner;
use PhpParser\Node;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S18: an organisation's Reifegrad of a shared catalogue requirement lives on its fulfilment row.
 * Code reads and writes it only through App\Service\Compliance\RequirementMaturity — a direct
 * getMaturity*() / setMaturity*() on a requirement reads or writes the value every organisation
 * shares.
 *
 * Exempt: the entities, and the TISAX/VDA-ISA code that works on the organisation's own
 * uploaded workbook rows (uploadTenant) or loads the catalogue skeleton default.
 */
final class RequirementMaturityAccessTest extends TestCase
{
    /** @var array<string, string> path prefix => reason */
    private const EXEMPT = [
        'src/Entity/ComplianceRequirement.php' => 'the entity',
        'src/Entity/ComplianceRequirementFulfillment.php' => 'the entity',
        'src/Service/Compliance/RequirementMaturity.php' => 'the one access path',
        'src/Service/Tisax/' => 'works on the organisation\'s own uploaded workbook rows',
        'src/Service/Import/Mapper/TisaxRequirementMapper.php' => 'TISAX workbook rows',
        'src/Command/TisaxConsolidateCommand.php' => 'TISAX workbook rows',
        // Found by the hardened scan (W9): DQL on the shared column. It only selects which TISAX rows carry an
        // assessment for the audit-freeze snapshot (scoped to uploadTenant or shared rows); it neither returns
        // nor writes a maturity value as an organisation's Reifegrad. Review whether this should read the
        // fulfilment rows instead.
        'src/Repository/ComplianceRequirementRepository.php' => 'TISAX audit-freeze selection (DQL on cr.maturityCurrent), see comment',
    ];

    /** Calls, property reads, string callables and DQL/QueryBuilder strings that touch the shared value. */
    private const string PHP_MEMBER = '/^(?:[sg]et)?maturity(?:Current|Target|ReviewedAt)$/i';
    private const string DQL_FIELD = '/\b\w+\.maturity(?:Current|Target|ReviewedAt)\b/i';
    private const string CALLABLE = '/^[sg]etMaturity(?:Current|Target|ReviewedAt)$/i';
    private const string TWIG = '/(?:\.|attribute\(\s*[\w.]+\s*,\s*[\'"])\s*(?:get|set)?maturity(?:Current|Target|ReviewedAt)\b/i';

    #[Test]
    public function maturityIsReadAndWrittenOnlyThroughRequirementMaturity(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::EXEMPT as $exempt => $reason) {
                    if (str_starts_with($relative, $exempt)) {
                        continue 2;
                    }
                }
                $source = (string) file_get_contents($file->getPathname());
                $hit = $file->getExtension() === 'twig'
                    ? !str_starts_with($relative, 'templates/tisax/') && self::twigTouchesMaturity($source)
                    : self::phpTouchesMaturity(PhpSourceScanner::parseCode($source, $relative));
                if ($hit) {
                    $offenders[] = $relative;
                }
            }
        }

        self::assertSame([], $offenders, 'Read and write a Reifegrad via App\Service\Compliance\RequirementMaturity (S18).');
    }

    #[Test]
    public function theScannerSeesEveryWayToTouchTheSharedValue(): void
    {
        $php = [
            'getter' => '$r->getMaturityCurrent();',
            'nullsafe getter' => '$r?->getMaturityTarget();',
            'setter' => '$r->setMaturityReviewedAt($d);',
            'property read' => '$x = $r->maturityCurrent;',
            'DQL select' => '$qb->select("r.maturityCurrent");',
            'DQL set' => '$qb->update(X::class, "r")->set(\'r.maturityCurrent\', ":v");',
            'DQL in a concatenated string' => '$qb->where("(r.maturityTarget" . " IS NOT NULL)");',
            'string callable' => '$f = [$r, "getMaturityCurrent"];',
            'dynamic call by name' => '$r->{"setMaturityTarget"}(3);',
            'static call' => 'Foo::getMaturityTarget($r);',
        ];
        foreach ($php as $label => $statement) {
            self::assertTrue(
                self::phpTouchesMaturity(PhpSourceScanner::parseCode('<?php ' . $statement)),
                $label . ' must be flagged',
            );
        }
        $clean = '<?php // $r->getMaturityCurrent() in a comment' . "\n" . '$t->getWizardMaturityTarget(); $x = "maturityCurrent";';
        self::assertFalse(self::phpTouchesMaturity(PhpSourceScanner::parseCode($clean)), 'comments and look-alikes pass');

        foreach ([
            '{{ requirement.maturityCurrent }}',
            '{{ requirement.getMaturityCurrent() }}',
            "{{ attribute(r, 'maturityCurrent') }}",
            '{{ attribute(r, "maturityTarget") }}',
            '{{ r.maturityReviewedAt|fa_date }}',
        ] as $twig) {
            self::assertTrue(self::twigTouchesMaturity($twig), $twig . ' must be flagged');
        }
        self::assertFalse(self::twigTouchesMaturity('{# r.maturityCurrent #}{{ tenant.wizardMaturityTarget }}'));
    }

    /** @param list<Node> $ast */
    private static function phpTouchesMaturity(array $ast): bool
    {
        foreach (PhpSourceScanner::find($ast, Node\Expr\MethodCall::class) as $call) {
            if (self::nameMatches($call->name, self::PHP_MEMBER)) {
                return true;
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\NullsafeMethodCall::class) as $call) {
            if (self::nameMatches($call->name, self::PHP_MEMBER)) {
                return true;
            }
        }
        foreach (PhpSourceScanner::find($ast, Node\Expr\StaticCall::class) as $call) {
            if (self::nameMatches($call->name, self::PHP_MEMBER)) {
                return true;
            }
        }
        foreach ([...PhpSourceScanner::find($ast, Node\Expr\PropertyFetch::class), ...PhpSourceScanner::find($ast, Node\Expr\NullsafePropertyFetch::class)] as $fetch) {
            if (self::nameMatches($fetch->name, self::PHP_MEMBER)) {
                return true;
            }
        }
        foreach (PhpSourceScanner::stringLiterals($ast) as $string) {
            if (preg_match(self::DQL_FIELD, $string) === 1 || preg_match(self::CALLABLE, $string) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function nameMatches(Node|string $name, string $pattern): bool
    {
        if ($name instanceof Node\Identifier) {
            return preg_match($pattern, $name->toString()) === 1;
        }
        if ($name instanceof Node) {
            $literal = $name instanceof Node\Expr ? PhpSourceScanner::constantString($name) : null;

            return $literal !== null && preg_match($pattern, $literal) === 1;
        }

        return preg_match($pattern, $name) === 1;
    }

    private static function twigTouchesMaturity(string $twig): bool
    {
        return preg_match(self::TWIG, PhpSourceScanner::twigWithoutComments($twig)) === 1;
    }
}
