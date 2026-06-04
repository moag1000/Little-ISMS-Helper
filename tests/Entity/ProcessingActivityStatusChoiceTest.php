<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ProcessingActivity;
use App\Lifecycle\LifecycleRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Validation;

/**
 * Regression: #[Assert\Choice(choices: LifecycleRegistry::STANDARD_5_STAGE)]
 * compared the status against the map's VALUES (per-stage arrays), so every
 * status — including the default 'draft' — was rejected and ALL processing-
 * activity edits failed with "data.status: not a valid choice".
 */
final class ProcessingActivityStatusChoiceTest extends TestCase
{
    #[Test]
    public function standardStageKeysAreTheStatusStrings(): void
    {
        self::assertSame(
            ['draft', 'in_review', 'approved', 'published', 'archived'],
            LifecycleRegistry::standardStageKeys(),
        );
    }

    #[Test]
    public function everyCanonicalStatusPassesTheChoiceConstraint(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        foreach (['draft', 'in_review', 'approved', 'published', 'archived'] as $status) {
            $pa = new ProcessingActivity();
            $pa->setStatus($status);

            $statusViolations = array_filter(
                iterator_to_array($validator->validate($pa)),
                static fn ($v): bool => $v->getPropertyPath() === 'status',
            );

            self::assertCount(0, $statusViolations, sprintf('status "%s" must be a valid choice', $status));
        }
    }

    #[Test]
    public function bogusStatusStillFailsTheChoiceConstraint(): void
    {
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        $pa = new ProcessingActivity();
        $pa->setStatus('not_a_real_status');

        $statusViolations = array_filter(
            iterator_to_array($validator->validate($pa)),
            static fn ($v): bool => $v->getPropertyPath() === 'status',
        );

        self::assertCount(1, $statusViolations);
    }
}
