<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Controller\DataBreachController;
use App\Controller\DataSubjectRequestController;
use App\Controller\DPIAController;
use App\Controller\AdminLicensingController;
use App\Security\Voter\TenantScopedAdminVoter;
use App\Controller\SecurityReportController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * ROLE_AUDITOR — and ROLE_GROUP_CISO / ROLE_KONZERN_AUDITOR, which inherit
 * it — is the read-only role (security.yaml, FieldVisibilityResolver).
 * Bug-Sweep 3 found GDPR breach and DPIA decisions gated on it, the Art. 15
 * data export open to every user, and the shell-backed report generators
 * open to every user.
 */
final class WriteActionRoleGateTest extends TestCase
{
    /** @return iterable<string, array{class-string, string, string}> */
    public static function gates(): iterable
    {
        foreach (['new', 'edit', 'submitForAssessment', 'notifyAuthority', 'notifySubjects', 'subjectNotificationExemption', 'close'] as $m) {
            yield 'breach ' . $m => [DataBreachController::class, $m, 'ROLE_MANAGER'];
        }
        foreach (['approve', 'reject', 'requestRevision', 'dpConsultation', 'completeReview'] as $m) {
            yield 'dpia ' . $m => [DPIAController::class, $m, 'ROLE_MANAGER'];
        }
        yield 'dsr personal data export' => [DataSubjectRequestController::class, 'exportPersonalData', 'ROLE_MANAGER'];
        yield 'security report generator' => [SecurityReportController::class, 'generate', 'ROLE_ADMIN'];
        yield 'license report generator' => [AdminLicensingController::class, 'generate', TenantScopedAdminVoter::ADMIN_GLOBAL_OP];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('gates')]
    public function actionNeedsRole(string $class, string $method, string $role): void
    {
        $roles = array_map(
            static fn(\ReflectionAttribute $a): mixed => $a->newInstance()->attribute,
            (new ReflectionMethod($class, $method))->getAttributes(IsGranted::class),
        );

        self::assertContains($role, $roles, sprintf('%s::%s() must require %s.', $class, $method, $role));
    }
}
