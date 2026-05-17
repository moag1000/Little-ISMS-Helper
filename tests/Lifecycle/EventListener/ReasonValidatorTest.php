<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\EventListener;

use App\Entity\Document;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EventListener\ReasonValidator;
use App\Lifecycle\Exception\ReasonRequiredException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\TransitionEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class ReasonValidatorTest extends TestCase
{
    public function testThrowsWhenReasonRequiredAndMissing(): void
    {
        $this->expectException(ReasonRequiredException::class);
        $validator = $this->makeValidator(required: true);
        $validator->onTransition($this->makeEvent(context: []));
    }

    public function testThrowsWhenReasonEmpty(): void
    {
        $this->expectException(ReasonRequiredException::class);
        $validator = $this->makeValidator(required: true);
        $validator->onTransition($this->makeEvent(context: ['reason' => '   ']));
    }

    public function testPassesWhenReasonProvided(): void
    {
        $validator = $this->makeValidator(required: true);
        $validator->onTransition($this->makeEvent(context: ['reason' => 'good']));
        $this->expectNotToPerformAssertions();
    }

    public function testPassesWhenNotRequired(): void
    {
        $validator = $this->makeValidator(required: false);
        $validator->onTransition($this->makeEvent(context: []));
        $this->expectNotToPerformAssertions();
    }

    private function makeValidator(bool $required): ReasonValidator
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('get')->willReturn($required);
        return new ReasonValidator($resolver);
    }

    private function makeEvent(array $context): TransitionEvent
    {
        return new TransitionEvent(
            new Document(),
            new Marking(['in_review' => 1]),
            new Transition('request_changes', ['in_review'], ['draft']),
            $this->createStub(WorkflowInterface::class),
            $context,
        );
    }
}
