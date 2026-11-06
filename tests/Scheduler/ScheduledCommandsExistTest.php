<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Schedule;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every command the scheduler runs must exist and start without arguments —
 * a typo or a new required argument would otherwise fail silently in the worker.
 */
final class ScheduledCommandsExistTest extends KernelTestCase
{
    #[Test]
    public function everyScheduledCommandExistsAndNeedsNoArguments(): void
    {
        $application = new Application(self::bootKernel());

        foreach (Schedule::COMMAND_TASKS as [, $line]) {
            $command = explode(' ', $line)[0];
            self::assertTrue($application->has($command), sprintf('scheduled command "%s" does not exist', $command));
            $required = array_filter(
                $application->find($command)->getDefinition()->getArguments(),
                static fn ($argument): bool => $argument->isRequired(),
            );
            self::assertSame([], array_keys($required), sprintf('"%s" needs arguments', $command));
            foreach (array_slice(explode(' ', $line), 1) as $option) {
                self::assertTrue(
                    $application->find($command)->getDefinition()->hasOption(ltrim($option, '-')),
                    sprintf('"%s" has no option %s', $command, $option),
                );
            }
        }
    }
}
