<?php

declare(strict_types=1);

namespace App\Security\Mfa;

use App\Entity\User;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The one record of "has this login passed the second factor" (NIS-2 Art. 21(2)(j), ISO 27001 A.8.5).
 *
 * The state belongs to a login, not to the session: it carries the id of the person who logged in,
 * and it is written fresh on every successful login ({@see MfaLoginListener}). A state for another
 * person counts as no state, and a person without state who has a second factor is asked for it
 * ({@see \App\EventSubscriber\MfaEnforcerSubscriber}). So a second login in the same session never
 * inherits the first one's verification, and a session that was born without a login event
 * (old session after an update, a path nobody thought of) fails closed.
 *
 * Under impersonation the state stays with the impersonating admin ({@see self::subjectOf()}).
 */
final class MfaSessionState
{
    public const string KEY = '_security.mfa_state';

    /** Keys of the earlier flag scheme: removed on every write so nothing reads them again. */
    private const array LEGACY_KEYS = ['_security.mfa_required', '_security.mfa_verified', '_security.mfa_user_id'];

    /** The person whose login the state describes: the impersonator while switched. */
    public static function subjectOf(?TokenInterface $token): ?User
    {
        if ($token instanceof SwitchUserToken) {
            $token = $token->getOriginalToken();
        }
        $user = $token?->getUser();

        return $user instanceof User && $user->getId() !== null ? $user : null;
    }

    /**
     * New login of $user: forgets every earlier state of the session.
     *
     * @param bool $secondFactorProven the login itself proves both factors (remember-me cookie,
     *                                 which is issued only after the second factor)
     *
     * @return bool true when the second factor is now outstanding
     */
    public function begin(SessionInterface $session, User $user, bool $secondFactorProven = false): bool
    {
        foreach (self::LEGACY_KEYS as $key) {
            $session->remove($key);
        }
        $pending = !$secondFactorProven && $user->hasActiveMfaToken();
        $session->set(self::KEY, ['user' => $user->getId(), 'verified' => !$pending, 'remember' => false]);

        return $pending;
    }

    public function isVerified(SessionInterface $session, User $user): bool
    {
        $state = $this->stateFor($session, $user);

        return $state !== null && $state['verified'] === true;
    }

    public function isPending(SessionInterface $session, User $user): bool
    {
        $state = $this->stateFor($session, $user);

        return $state !== null && $state['verified'] !== true;
    }

    /** The person asked for "stay logged in"; the cookie waits for the second factor. */
    public function rememberAfterVerification(SessionInterface $session, User $user): void
    {
        $state = $this->stateFor($session, $user);
        if ($state !== null && $state['verified'] !== true) {
            $state['remember'] = true;
            $session->set(self::KEY, $state);
        }
    }

    /**
     * The second factor was given.
     *
     * @return bool whether the remember-me cookie is to be issued now
     */
    public function markVerified(SessionInterface $session, User $user): bool
    {
        $state = $this->stateFor($session, $user);
        $remember = $state !== null && ($state['remember'] ?? false) === true;
        $session->set(self::KEY, ['user' => $user->getId(), 'verified' => true, 'remember' => false]);

        return $remember;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function stateFor(SessionInterface $session, User $user): ?array
    {
        $state = $session->get(self::KEY);
        if (!is_array($state) || !isset($state['user']) || $user->getId() === null) {
            return null;
        }

        return (int) $state['user'] === $user->getId() ? $state : null;
    }
}
