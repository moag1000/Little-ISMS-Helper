<?php

namespace App\Service;

use App\Entity\User;
use App\Entity\UserSession;
use App\Repository\UserSessionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Session Manager Service for NIS2 Compliance
 *
 * Manages user sessions:
 * - Creates session records on login
 * - Tracks session activity
 * - Enforces session limits
 * - Enables force logout by administrators
 *
 * Gracefully handles missing user_sessions table for backward compatibility.
 *
 * NIS2 Compliance: Art. 21.2.e (Incident detection and response)
 * ISO 27001: A.9.2.5 (Review of user access rights), A.9.4.1 (Access restriction)
 */
class SessionManager
{
    private const MAX_CONCURRENT_SESSIONS = 5; // Maximum concurrent sessions per user
    private const SESSION_LIFETIME = 3600; // 1 hour in seconds

    private ?bool $tableExists = null; // Cache for table existence check

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserSessionRepository $sessionRepository,
        private readonly RequestStack $requestStack,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Check if user_sessions table exists in database
     * Caches the result to avoid repeated database checks
     */
    private function isTableAvailable(): bool
    {
        if ($this->tableExists !== null) {
            return $this->tableExists;
        }

        try {
            $connection = $this->entityManager->getConnection();
            $schemaManager = $connection->createSchemaManager();
            $tables = $schemaManager->listTableNames();
            $this->tableExists = in_array('user_sessions', $tables, true);

            if (!$this->tableExists) {
                $this->logger->info('user_sessions table does not exist - session tracking disabled. Run migrations to enable.');
            }

            return $this->tableExists;
        } catch (\Exception $e) {
            $this->logger->error('Failed to check if user_sessions table exists', [
                'error' => $e->getMessage(),
            ]);
            $this->tableExists = false;
            return false;
        }
    }

    /**
     * Create a new session record when user logs in
     * Returns null if table doesn't exist (graceful degradation)
     */
    public function createSession(User $user, string $sessionId): ?UserSession
    {
        if (!$this->isTableAvailable()) {
            return null;
        }

        try {
            $request = $this->requestStack->getCurrentRequest();

            $session = new UserSession();
            $session->setUser($user);
            $session->setSessionId($sessionId);
            $session->setIpAddress($request?->getClientIp());
            $session->setUserAgent($request?->headers->get('User-Agent'));

            // Check concurrent session limit
            $activeSessions = $this->sessionRepository->countActiveByUser($user);

            if ($activeSessions >= self::MAX_CONCURRENT_SESSIONS) {
                // Terminate oldest session
                $oldestSession = $this->sessionRepository->findActiveByUser($user)[self::MAX_CONCURRENT_SESSIONS - 1] ?? null;

                if ($oldestSession) {
                    $this->terminateSession($oldestSession->getSessionId(), 'limit_exceeded');

                    $this->logger->warning('Session limit exceeded, terminated oldest session', [
                        'user_email' => $user->getEmail(),
                        'limit' => self::MAX_CONCURRENT_SESSIONS,
                    ]);
                }
            }

            $this->entityManager->persist($session);
            $this->entityManager->flush();

            $this->logger->info('Session created', [
                'user_email' => $user->getEmail(),
                'session_id' => substr($sessionId, 0, 8) . '...',
                'ip_address' => $session->getIpAddress(),
            ]);

            return $session;
        } catch (\Exception $e) {
            $this->logger->error('Failed to create session record', [
                'user' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
            // Mark table as unavailable to prevent repeated failed attempts
            $this->tableExists = false;
            return null;
        }
    }

    /**
     * Update session activity timestamp
     */
    public function updateActivity(string $sessionId): void
    {
        if (!$this->isTableAvailable()) {
            return;
        }

        try {
            $session = $this->sessionRepository->findBySessionId($sessionId);

            if ($session && $session->isActive()) {
                $session->updateActivity();
                $this->entityManager->flush();
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to update session activity', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * End session (user logout)
     */
    public function endSession(string $sessionId, string $reason = 'logout'): void
    {
        if (!$this->isTableAvailable()) {
            return;
        }

        try {
            $session = $this->sessionRepository->findBySessionId($sessionId);

            if ($session && $session->isActive()) {
                $session->terminate($reason);
                $this->entityManager->flush();

                $this->logger->info('Session ended', [
                    'user_email' => $session->getUser()->getEmail(),
                    'reason' => $reason,
                    'duration' => $session->getFormattedDuration(),
                ]);

                $this->auditLogger->logCustom(
                    'session_ended',
                    'UserSession',
                    $session->getId(),
                    null,
                    ['reason' => $reason, 'duration_seconds' => $session->getDuration()],
                    sprintf('Session ended for user %s (%s)', $session->getUser()->getEmail(), $reason)
                );
            }
        } catch (\Exception $e) {
            $this->logger->debug('Failed to end session', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Force terminate a session (admin action)
     */
    public function terminateSession(string $sessionId, string $reason = 'forced', ?string $terminatedBy = null): bool
    {
        if (!$this->isTableAvailable()) {
            return false;
        }

        try {
            $session = $this->sessionRepository->findBySessionId($sessionId);

            if (!$session || !$session->isActive()) {
                return false;
            }

            $userEmail = $session->getUser()->getEmail();
            $session->terminate($reason, $terminatedBy);
            $this->entityManager->flush();

            $this->logger->warning('Session forcefully terminated', [
                'user_email' => $userEmail,
                'terminated_by' => $terminatedBy,
                'reason' => $reason,
            ]);

            $this->auditLogger->logCustom(
                'session_terminated',
                'UserSession',
                $session->getId(),
                ['is_active' => true],
                ['is_active' => false, 'terminated_by' => $terminatedBy, 'reason' => $reason],
                sprintf('Session terminated for user %s by %s', $userEmail, $terminatedBy ?? 'system')
            );

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to terminate session', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Terminate all active sessions for a user
     */
    public function terminateUserSessions(User $user, ?string $terminatedBy = null): int
    {
        if (!$this->isTableAvailable()) {
            return 0;
        }

        try {
            $count = $this->sessionRepository->terminateUserSessions($user, 'forced', $terminatedBy);

            if ($count > 0) {
                $this->logger->warning('All user sessions terminated', [
                    'user_email' => $user->getEmail(),
                    'terminated_by' => $terminatedBy,
                    'count' => $count,
                ]);

                $this->auditLogger->logCustom(
                    'all_sessions_terminated',
                    'User',
                    $user->getId(),
                    null,
                    ['terminated_count' => $count, 'terminated_by' => $terminatedBy],
                    sprintf('All sessions (%d) terminated for user %s by %s', $count, $user->getEmail(), $terminatedBy ?? 'system')
                );
            }

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to terminate user sessions', [
                'user' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Check if a session is still valid
     */
    public function isSessionValid(string $sessionId): bool
    {
        if (!$this->isTableAvailable()) {
            return false;
        }

        try {
            $session = $this->sessionRepository->findBySessionId($sessionId);

            if (!$session) {
                return false;
            }

            // Check if session is marked as inactive
            if (!$session->isActive()) {
                return false;
            }

            // Check if session has expired due to inactivity
            if ($session->isExpired(self::SESSION_LIFETIME)) {
                // Mark as expired
                $this->endSession($sessionId, 'timeout');
                return false;
            }

            return true;
        } catch (\Exception $e) {
            $this->logger->debug('Failed to validate session', [
                'session_id' => substr($sessionId, 0, 8) . '...',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get active sessions for a user
     *
     * @return UserSession[]
     */
    public function getUserActiveSessions(User $user): array
    {
        if (!$this->isTableAvailable()) {
            return [];
        }

        try {
            return $this->sessionRepository->findActiveByUser($user);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get user active sessions', [
                'user' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get all currently active sessions
     *
     * @return UserSession[]
     */
    public function getAllActiveSessions(?string $userEmail = null, ?int $limit = null): array
    {
        if (!$this->isTableAvailable()) {
            return [];
        }

        try {
            return $this->sessionRepository->getActiveSessions($limit, $userEmail);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get all active sessions', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Get session statistics
     */
    public function getStatistics(): array
    {
        if (!$this->isTableAvailable()) {
            return [
                'total_sessions' => 0,
                'active_sessions' => 0,
                'inactive_sessions' => 0,
            ];
        }

        try {
            return $this->sessionRepository->getStatistics();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get session statistics', [
                'error' => $e->getMessage(),
            ]);
            return [
                'total_sessions' => 0,
                'active_sessions' => 0,
                'inactive_sessions' => 0,
            ];
        }
    }

    /**
     * Clean up expired sessions (run periodically via cron/command)
     */
    public function cleanupExpiredSessions(): int
    {
        if (!$this->isTableAvailable()) {
            return 0;
        }

        try {
            $count = $this->sessionRepository->cleanupExpiredSessions(self::SESSION_LIFETIME);

            if ($count > 0) {
                $this->logger->info('Expired sessions cleaned up', ['count' => $count]);
            }

            return $count;
        } catch (\Exception $e) {
            $this->logger->error('Failed to cleanup expired sessions', [
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Get maximum allowed concurrent sessions
     */
    public function getMaxConcurrentSessions(): int
    {
        return self::MAX_CONCURRENT_SESSIONS;
    }

    /**
     * Get session lifetime in seconds
     */
    public function getSessionLifetime(): int
    {
        return self::SESSION_LIFETIME;
    }
}
