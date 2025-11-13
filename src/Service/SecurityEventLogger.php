<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security: Security Event Logging Service
 *
 * Implements security event logging according to OWASP guidelines for:
 * - Authentication events (login success/failure)
 * - Authorization failures (access denied)
 * - Data changes (CRUD operations on sensitive data)
 * - File uploads
 * - Security-relevant configuration changes
 *
 * All events are logged with:
 * - Timestamp
 * - User information (if authenticated)
 * - IP address
 * - Event type and details
 * - Success/failure status
 */
class SecurityEventLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RequestStack $requestStack,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger
    ) {}

    /**
     * Security: Log successful login
     */
    public function logLoginSuccess(UserInterface $user): void
    {
        $this->logSecurityEvent('LOGIN_SUCCESS', [
            'username' => $user->getUserIdentifier(),
            'user_id' => $user instanceof User ? $user->getId() : null,
        ]);

        // Also log to audit log database for session tracking
        $this->auditLogger->logCustom(
            'login_success',
            'Authentication',
            $user instanceof User ? $user->getId() : null,
            null,
            ['username' => $user->getUserIdentifier()],
            'User logged in successfully'
        );
    }

    /**
     * Security: Log failed login attempt
     */
    public function logLoginFailure(string $username, ?string $reason = null): void
    {
        $this->logSecurityEvent('LOGIN_FAILURE', [
            'username' => $username,
            'reason' => $reason ?? 'Invalid credentials',
        ], 'warning');

        // Also log to audit log database for security monitoring
        $this->auditLogger->logCustom(
            'login_failure',
            'Authentication',
            null,
            null,
            ['username' => $username, 'reason' => $reason ?? 'Invalid credentials'],
            'Failed login attempt',
            $username // Pass the username explicitly since user is not authenticated
        );
    }

    /**
     * Security: Log logout
     */
    public function logLogout(UserInterface $user): void
    {
        $this->logSecurityEvent('LOGOUT', [
            'username' => $user->getUserIdentifier(),
        ]);

        // Also log to audit log database for session tracking
        $this->auditLogger->logCustom(
            'logout',
            'Authentication',
            $user instanceof User ? $user->getId() : null,
            null,
            ['username' => $user->getUserIdentifier()],
            'User logged out'
        );
    }

    /**
     * Security: Log access denied (authorization failure)
     */
    public function logAccessDenied(string $resource, ?string $action = null, ?UserInterface $user = null): void
    {
        $this->logSecurityEvent('ACCESS_DENIED', [
            'resource' => $resource,
            'action' => $action,
            'username' => $user?->getUserIdentifier(),
        ], 'warning');
    }

    /**
     * Security: Log file upload
     */
    public function logFileUpload(string $filename, string $mimeType, int $fileSize, bool $success, ?string $error = null): void
    {
        $this->logSecurityEvent($success ? 'FILE_UPLOAD_SUCCESS' : 'FILE_UPLOAD_FAILURE', [
            'filename' => $filename,
            'mime_type' => $mimeType,
            'file_size' => $fileSize,
            'error' => $error,
        ], $success ? 'info' : 'warning');
    }

    /**
     * Security: Log data modification (CRUD operations)
     */
    public function logDataChange(string $entityType, int $entityId, string $action, ?array $changes = null): void
    {
        $this->logSecurityEvent('DATA_CHANGE', [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action, // CREATE, UPDATE, DELETE
            'changes' => $changes ? $this->sanitizeChanges($changes) : null,
        ]);
    }

    /**
     * Security: Log rate limit hit
     */
    public function logRateLimitHit(string $limiterName): void
    {
        $this->logSecurityEvent('RATE_LIMIT_HIT', [
            'limiter' => $limiterName,
        ], 'warning');
    }

    /**
     * Security: Log password change
     */
    public function logPasswordChange(UserInterface $user, bool $success): void
    {
        $this->logSecurityEvent('PASSWORD_CHANGE', [
            'username' => $user->getUserIdentifier(),
            'success' => $success,
        ], $success ? 'info' : 'warning');
    }

    /**
     * Security: Log suspicious activity
     */
    public function logSuspiciousActivity(string $description, array $details = []): void
    {
        $this->logSecurityEvent('SUSPICIOUS_ACTIVITY', array_merge([
            'description' => $description,
        ], $details), 'critical');
    }

    /**
     * Security: Log security configuration change
     */
    public function logConfigChange(string $setting, mixed $oldValue, mixed $newValue): void
    {
        $this->logSecurityEvent('CONFIG_CHANGE', [
            'setting' => $setting,
            'old_value' => $this->sanitizeValue($oldValue),
            'new_value' => $this->sanitizeValue($newValue),
        ]);
    }

    /**
     * Security: Central security event logging with context
     */
    private function logSecurityEvent(string $eventType, array $details, string $level = 'info'): void
    {
        $request = $this->requestStack->getCurrentRequest();

        $context = [
            'event_type' => $eventType,
            'timestamp' => (new \DateTime())->format('Y-m-d H:i:s'),
            'ip_address' => $request?->getClientIp() ?? 'CLI',
            'user_agent' => $request?->headers->get('User-Agent') ?? 'N/A',
            'request_uri' => $request?->getRequestUri() ?? 'N/A',
            'details' => $details,
        ];

        // Log to application log
        match ($level) {
            'critical' => $this->logger->critical('[SECURITY] ' . $eventType, $context),
            'warning' => $this->logger->warning('[SECURITY] ' . $eventType, $context),
            default => $this->logger->info('[SECURITY] ' . $eventType, $context),
        };
    }

    /**
     * Security: Sanitize sensitive data from change logs
     */
    private function sanitizeChanges(array $changes): array
    {
        $sensitiveFields = ['password', 'token', 'secret', 'api_key', 'private_key'];

        foreach ($changes as $field => $value) {
            foreach ($sensitiveFields as $sensitiveField) {
                if (stripos($field, $sensitiveField) !== false) {
                    $changes[$field] = '***REDACTED***';
                }
            }
        }

        return $changes;
    }

    /**
     * Security: Sanitize value for logging (don't log sensitive data)
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_string($value) && strlen($value) > 100) {
            return substr($value, 0, 100) . '... (truncated)';
        }

        if (is_array($value)) {
            return $this->sanitizeChanges($value);
        }

        return $value;
    }
}
