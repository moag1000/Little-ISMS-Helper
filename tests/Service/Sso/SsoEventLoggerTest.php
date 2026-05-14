<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Entity\IdentityProvider;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\Sso\SsoEventLogger;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class SsoEventLoggerTest extends TestCase
{
    private AuditLogger&MockObject $audit;
    private SsoEventLogger $logger;

    protected function setUp(): void
    {
        $this->audit  = $this->createMock(AuditLogger::class);
        $this->logger = new SsoEventLogger($this->audit);
    }

    private function makeProvider(): IdentityProvider
    {
        $idp = new IdentityProvider();
        $idp->setSlug('test-idp');
        $idp->setName('Test IdP');
        $idp->setClientId('cid');
        return $idp;
    }

    #[Test]
    public function logLoginSuccessCallsAuditCustomWithCorrectAction(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_LOGIN_SUCCESS,
                'IdentityProvider',
                null,
                null,
                self::arrayHasKey('email'),
            );
        $this->logger->logLoginSuccess($this->makeProvider(), 'alice@example.com');
    }

    #[Test]
    public function logLoginFailureCallsAuditCustomWithCorrectAction(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_LOGIN_FAILURE,
                'IdentityProvider',
                null,
                null,
                self::arrayHasKey('reason'),
            );
        $this->logger->logLoginFailure($this->makeProvider(), 'alice@example.com', 'invalid_token');
    }

    #[Test]
    public function logJitProvisionedCallsAuditCustom(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_JIT_PROVISIONED,
                'IdentityProvider',
                null,
                null,
                self::arrayHasKey('email'),
            );
        $user = new User();
        $this->logger->logJitProvisioned($this->makeProvider(), $user, 'alice@example.com');
    }

    #[Test]
    public function logRoleChangedCallsAuditCustom(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_ROLE_CHANGED,
                'IdentityProvider',
                null,
                self::arrayHasKey('old_role'),
                self::arrayHasKey('new_role'),
            );
        $user = new User();
        $this->logger->logRoleChanged($this->makeProvider(), $user, 'ROLE_USER', 'ROLE_MANAGER');
    }

    #[Test]
    public function logConfigChangedCallsAuditCustom(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_CONFIG_CHANGED,
                'IdentityProvider',
                null,
                self::arrayHasKey('field'),
                self::arrayHasKey('new_value'),
            );
        $this->logger->logConfigChanged($this->makeProvider(), 'clientId', 'old', 'new');
    }

    #[Test]
    public function logEnforcementChangedCallsAuditCustom(): void
    {
        $this->audit->expects(self::once())
            ->method('logCustom')
            ->with(
                AuditLogger::ACTION_SSO_ENFORCEMENT_CHANGED,
                'IdentityProvider',
                null,
                self::arrayHasKey('sso_enforced_old'),
                self::arrayHasKey('sso_enforced_new'),
            );
        $this->logger->logEnforcementChanged($this->makeProvider(), false, true);
    }
}
