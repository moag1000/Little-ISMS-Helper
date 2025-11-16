<?php

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\SecurityEventLogger;
use App\Service\SessionManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SecurityEventLoggerTest extends TestCase
{
    private MockObject $logger;
    private MockObject $requestStack;
    private MockObject $entityManager;
    private MockObject $auditLogger;
    private MockObject $sessionManager;
    private SecurityEventLogger $securityLogger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->requestStack = $this->createMock(RequestStack::class);
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->auditLogger = $this->createMock(AuditLogger::class);
        $this->sessionManager = $this->createMock(SessionManager::class);

        $this->securityLogger = new SecurityEventLogger(
            $this->logger,
            $this->requestStack,
            $this->entityManager,
            $this->auditLogger,
            $this->sessionManager
        );
    }

    public function testLogLoginSuccessLogsToAllTargets(): void
    {
        $user = $this->createUser(1, 'admin@example.com');
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn('session123');

        $this->setupRequest('127.0.0.1', 'Mozilla', $session);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('LOGIN_SUCCESS'),
                $this->callback(function ($context) {
                    return $context['event_type'] === 'LOGIN_SUCCESS'
                        && $context['details']['username'] === 'admin@example.com'
                        && $context['details']['user_id'] === 1;
                })
            );

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'login_success',
                'Authentication',
                1,
                null,
                $this->callback(fn($v) => $v['username'] === 'admin@example.com'),
                'User logged in successfully'
            );

        $this->sessionManager->expects($this->once())
            ->method('createSession')
            ->with($user, 'session123');

        $this->securityLogger->logLoginSuccess($user);
    }

    public function testLogLoginSuccessWithoutSession(): void
    {
        $user = $this->createUser(1, 'admin@example.com');

        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn('127.0.0.1');
        $request->method('getSession')->willReturn(null);
        $request->method('getRequestUri')->willReturn('/login');

        $headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $headers->method('get')->willReturn('Mozilla');
        $request->headers = $headers;

        $this->requestStack->method('getCurrentRequest')->willReturn($request);

        $this->logger->expects($this->once())->method('info');
        $this->auditLogger->expects($this->once())->method('logCustom');
        $this->sessionManager->expects($this->never())->method('createSession');

        $this->securityLogger->logLoginSuccess($user);
    }

    public function testLogLoginFailureLogsWarning(): void
    {
        $this->setupRequest('192.168.1.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('LOGIN_FAILURE'),
                $this->callback(function ($context) {
                    return $context['event_type'] === 'LOGIN_FAILURE'
                        && $context['details']['username'] === 'hacker@example.com'
                        && $context['details']['reason'] === 'Invalid credentials';
                })
            );

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with(
                'login_failure',
                'Authentication',
                null,
                null,
                $this->callback(fn($v) => $v['username'] === 'hacker@example.com'),
                'Failed login attempt',
                'hacker@example.com'
            );

        $this->securityLogger->logLoginFailure('hacker@example.com');
    }

    public function testLogLoginFailureWithCustomReason(): void
    {
        $this->setupRequest('10.0.0.1', 'CLI');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('LOGIN_FAILURE'),
                $this->callback(fn($ctx) => $ctx['details']['reason'] === 'Account locked')
            );

        $this->securityLogger->logLoginFailure('locked@example.com', 'Account locked');
    }

    public function testLogLogoutLogsAndEndsSession(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $session = $this->createMock(SessionInterface::class);
        $session->method('getId')->willReturn('session456');

        $this->setupRequest('127.0.0.1', 'Mozilla', $session);

        $this->logger->expects($this->once())
            ->method('info')
            ->with($this->stringContains('LOGOUT'));

        $this->auditLogger->expects($this->once())
            ->method('logCustom')
            ->with('logout', 'Authentication', 1);

        $this->sessionManager->expects($this->once())
            ->method('endSession')
            ->with('session456', 'logout');

        $this->securityLogger->logLogout($user);
    }

    public function testLogAccessDeniedLogsWarning(): void
    {
        $user = $this->createUser(2, 'nonadmin@example.com');
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('ACCESS_DENIED'),
                $this->callback(function ($context) {
                    return $context['details']['resource'] === '/admin/users'
                        && $context['details']['action'] === 'view'
                        && $context['details']['username'] === 'nonadmin@example.com';
                })
            );

        $this->securityLogger->logAccessDenied('/admin/users', 'view', $user);
    }

    public function testLogAccessDeniedWithoutUser(): void
    {
        $this->setupRequest('10.0.0.1', 'Bot');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('ACCESS_DENIED'),
                $this->callback(fn($ctx) => $ctx['details']['username'] === null)
            );

        $this->securityLogger->logAccessDenied('/api/protected', 'access');
    }

    public function testLogFileUploadSuccessLogsInfo(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('FILE_UPLOAD_SUCCESS'),
                $this->callback(function ($context) {
                    return $context['details']['filename'] === 'document.pdf'
                        && $context['details']['mime_type'] === 'application/pdf'
                        && $context['details']['file_size'] === 1024000
                        && $context['details']['error'] === null;
                })
            );

        $this->securityLogger->logFileUpload('document.pdf', 'application/pdf', 1024000, true);
    }

    public function testLogFileUploadFailureLogsWarning(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('FILE_UPLOAD_FAILURE'),
                $this->callback(fn($ctx) => $ctx['details']['error'] === 'File too large')
            );

        $this->securityLogger->logFileUpload('large.zip', 'application/zip', 999999999, false, 'File too large');
    }

    public function testLogDataChangeLogsWithSanitizedChanges(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $changes = [
            'name' => 'New Name',
            'password_hash' => 'secret123', // Should be sanitized
            'api_token' => 'abc123', // Should be sanitized
            'status' => 'active',
        ];

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('DATA_CHANGE'),
                $this->callback(function ($context) {
                    $changes = $context['details']['changes'];
                    return $context['details']['entity_type'] === 'User'
                        && $context['details']['entity_id'] === 5
                        && $context['details']['action'] === 'UPDATE'
                        && $changes['name'] === 'New Name'
                        && $changes['password_hash'] === '***REDACTED***'
                        && $changes['api_token'] === '***REDACTED***'
                        && $changes['status'] === 'active';
                })
            );

        $this->securityLogger->logDataChange('User', 5, 'UPDATE', $changes);
    }

    public function testLogDataChangeWithoutChanges(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('DATA_CHANGE'),
                $this->callback(fn($ctx) => $ctx['details']['changes'] === null)
            );

        $this->securityLogger->logDataChange('Document', 10, 'DELETE');
    }

    public function testLogRateLimitHitLogsWarning(): void
    {
        $this->setupRequest('10.0.0.1', 'Bot');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('RATE_LIMIT_HIT'),
                $this->callback(fn($ctx) => $ctx['details']['limiter'] === 'api_requests')
            );

        $this->securityLogger->logRateLimitHit('api_requests');
    }

    public function testLogPasswordChangeSuccessLogsInfo(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('PASSWORD_CHANGE'),
                $this->callback(function ($context) {
                    return $context['details']['username'] === 'user@example.com'
                        && $context['details']['success'] === true;
                })
            );

        $this->securityLogger->logPasswordChange($user, true);
    }

    public function testLogPasswordChangeFailureLogsWarning(): void
    {
        $user = $this->createUser(1, 'user@example.com');
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('PASSWORD_CHANGE'),
                $this->callback(fn($ctx) => $ctx['details']['success'] === false)
            );

        $this->securityLogger->logPasswordChange($user, false);
    }

    public function testLogSuspiciousActivityLogsCritical(): void
    {
        $this->setupRequest('192.168.1.100', 'Suspicious-Bot');

        $this->logger->expects($this->once())
            ->method('critical')
            ->with(
                $this->stringContains('SUSPICIOUS_ACTIVITY'),
                $this->callback(function ($context) {
                    return $context['details']['description'] === 'Multiple failed login attempts'
                        && $context['details']['attempts'] === 50
                        && $context['details']['timeframe'] === '5 minutes';
                })
            );

        $this->securityLogger->logSuspiciousActivity(
            'Multiple failed login attempts',
            ['attempts' => 50, 'timeframe' => '5 minutes']
        );
    }

    public function testLogConfigChangeLogsWithSanitizedValues(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('CONFIG_CHANGE'),
                $this->callback(function ($context) {
                    return $context['details']['setting'] === 'max_login_attempts'
                        && $context['details']['old_value'] === 5
                        && $context['details']['new_value'] === 3;
                })
            );

        $this->securityLogger->logConfigChange('max_login_attempts', 5, 3);
    }

    public function testLogConfigChangeTruncatesLongStrings(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $longString = str_repeat('x', 200);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('CONFIG_CHANGE'),
                $this->callback(function ($context) {
                    $newValue = $context['details']['new_value'];
                    return str_contains($newValue, '(truncated)')
                        && strlen($newValue) < 150;
                })
            );

        $this->securityLogger->logConfigChange('description', 'old', $longString);
    }

    public function testLogConfigChangeSanitizesSensitiveArrays(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $sensitiveConfig = [
            'database_host' => 'localhost',
            'database_password' => 'secret123',
            'api_secret' => 'topsecret',
        ];

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('CONFIG_CHANGE'),
                $this->callback(function ($context) {
                    $newValue = $context['details']['new_value'];
                    return $newValue['database_host'] === 'localhost'
                        && $newValue['database_password'] === '***REDACTED***'
                        && $newValue['api_secret'] === '***REDACTED***';
                })
            );

        $this->securityLogger->logConfigChange('database', [], $sensitiveConfig);
    }

    public function testLogsWithCliContext(): void
    {
        $this->requestStack->method('getCurrentRequest')->willReturn(null);

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->stringContains('DATA_CHANGE'),
                $this->callback(function ($context) {
                    return $context['ip_address'] === 'CLI'
                        && $context['user_agent'] === 'N/A'
                        && $context['request_uri'] === 'N/A';
                })
            );

        $this->securityLogger->logDataChange('Migration', 1, 'CREATE');
    }

    public function testTimestampIncludedInAllLogs(): void
    {
        $this->setupRequest('127.0.0.1', 'Browser');

        $this->logger->expects($this->once())
            ->method('info')
            ->with(
                $this->anything(),
                $this->callback(function ($context) {
                    return isset($context['timestamp'])
                        && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $context['timestamp']);
                })
            );

        $this->securityLogger->logDataChange('Entity', 1, 'VIEW');
    }

    private function createUser(int $id, string $email): MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getUserIdentifier')->willReturn($email);
        return $user;
    }

    private function setupRequest(string $ip, string $userAgent, ?SessionInterface $session = null): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getClientIp')->willReturn($ip);
        $request->method('getRequestUri')->willReturn('/test');

        if ($session) {
            $request->method('getSession')->willReturn($session);
        }

        $headers = $this->createMock(\Symfony\Component\HttpFoundation\HeaderBag::class);
        $headers->method('get')->with('User-Agent')->willReturn($userAgent);
        $request->headers = $headers;

        $this->requestStack->method('getCurrentRequest')->willReturn($request);
    }
}
