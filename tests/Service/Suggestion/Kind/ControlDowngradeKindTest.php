<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\AuditFinding;
use App\Entity\Control;
use App\Service\Suggestion\Kind\ControlDowngradeKind;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ControlDowngradeKindTest extends TestCase
{
    #[Test]
    public function aMajorNonconformityTakesAnImplementedControlBackToInProgress(): void
    {
        $finding = (new AuditFinding())->setType(AuditFinding::TYPE_MAJOR_NC);

        self::assertSame(['status' => 'in_progress', 'percentage' => 50], ControlDowngradeKind::targetFor($finding, self::control('verified')));
        self::assertSame(['status' => 'in_progress', 'percentage' => 50], ControlDowngradeKind::targetFor($finding, self::control('implemented')));
        self::assertNull(ControlDowngradeKind::targetFor($finding, self::control('in_progress')), 'already not done');
    }

    #[Test]
    public function aMinorNonconformityOnlyWithdrawsVerified(): void
    {
        $finding = (new AuditFinding())->setType(AuditFinding::TYPE_MINOR_NC);

        self::assertSame(['status' => 'implemented', 'percentage' => null], ControlDowngradeKind::targetFor($finding, self::control('verified')));
        self::assertNull(ControlDowngradeKind::targetFor($finding, self::control('implemented')));
        self::assertNull(ControlDowngradeKind::targetFor((new AuditFinding())->setType(AuditFinding::TYPE_OBSERVATION), self::control('verified')));
    }

    private static function control(string $status): Control
    {
        return (new Control())->setControlId('5.1')->setName('Policies')->setImplementationStatus($status);
    }
}
