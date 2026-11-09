<?php

declare(strict_types=1);

namespace App\Lifecycle\EventListener;

use App\Entity\ThreatLedPenetrationTest;
use DateTimeImmutable;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Workflow\Event\CompletedEvent;

/**
 * The actual test dates of a TLPT (DORA Art. 26, RTS on TLPT Art. 9/10) are
 * written by the step that produces them, never typed into the form:
 *
 *  - start_red_team: the active testing phase starts today,
 *  - start_reporting: the active testing phase ended today,
 *  - close: the test ends today unless the reporting step already set it.
 *
 * A date already on the record (imported, set over the API) is kept. The next
 * TLPT is due three years after the actual end
 * ({@see \App\Service\Deadline\Source\ThreatLedPenetrationTestSource}).
 * LifecycleService flushes after the transition.
 */
#[AsEventListener(event: 'workflow.threat_led_penetration_test_lifecycle.completed', method: 'onCompleted')]
final class TlptExecutionDatesListener
{
    public function onCompleted(CompletedEvent $event): void
    {
        $test = $event->getSubject();
        if (!$test instanceof ThreatLedPenetrationTest) {
            return;
        }
        $today = new DateTimeImmutable('today');
        match ($event->getTransition()?->getName()) {
            'start_red_team' => $test->getActualStartDate() ?? $test->setActualStartDate($today),
            'start_reporting', 'close' => $test->getActualEndDate() ?? $test->setActualEndDate($today),
            default => null,
        };
        $test->setUpdatedAt(new DateTimeImmutable());
    }
}
