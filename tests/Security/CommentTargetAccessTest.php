<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\CommentController;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S11: a comment is posted only by who may open the record's detail page.
 * CommentController::TARGET_ACCESS has to name the module the show controller
 * requires and the voter the record type has; this test reads both from the code.
 */
final class CommentTargetAccessTest extends TestCase
{
    #[Test]
    public function everyCommentableTypeHasAnAccessRule(): void
    {
        self::assertSame(
            array_keys(CommentController::entityTypes()),
            array_keys(CommentController::TARGET_ACCESS),
        );
    }

    #[Test]
    public function theModuleIsTheOneTheShowControllerRequires(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (CommentController::entityTypes() as $type => $class) {
            $short = (new \ReflectionClass($class))->getShortName();
            $controller = $this->showControllerOf($root, $short);
            self::assertNotNull($controller, sprintf('No show(%s $…) action found for comment type %s.', $short, $type));

            $source = (string) file_get_contents($controller);
            $classPos = (int) strpos($source, "\nclass ") ?: (int) strpos($source, "\nfinal class ");
            preg_match("/#\[RequiresModule\('([a-z0-9_]+)'\)\]/", substr($source, 0, $classPos), $m);
            self::assertSame(
                $m[1] ?? null,
                CommentController::TARGET_ACCESS[$type]['module'],
                sprintf('Comment rule for %s must name the module of %s.', $type, basename($controller)),
            );
        }
    }

    #[Test]
    public function aTypeWithAViewVoterIsCheckedAgainstIt(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (CommentController::entityTypes() as $type => $class) {
            $short = (new \ReflectionClass($class))->getShortName();
            $voter = $root . '/src/Security/Voter/' . $short . 'Voter.php';
            $hasView = is_file($voter) && str_contains((string) file_get_contents($voter), "VIEW = 'view'");
            self::assertSame(
                $hasView ? 'view' : null,
                CommentController::TARGET_ACCESS[$type]['attribute'],
                sprintf('Comment rule for %s must ask %sVoter.', $type, $short),
            );
        }
    }

    private function showControllerOf(string $root, string $short): ?string
    {
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/src/Controller', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            if (preg_match('/public function show\([^)]*\b' . preg_quote($short, '/') . ' \$/s', $source) === 1) {
                return $file->getPathname();
            }
        }

        return null;
    }
}
