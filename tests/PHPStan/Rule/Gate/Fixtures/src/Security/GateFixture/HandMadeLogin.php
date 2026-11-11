<?php

declare(strict_types=1);

namespace App\Security\GateFixture;

use App\Security\Mfa\MfaSessionState;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Stopwatch\Stopwatch;

/** Gate 91 fixture: every `expect:` line is a mutation the rule must report. */
final class HandMadeLogin
{
    private const KEY = '_security_main'; // expect: gate91.handMadeLogin

    public function __construct(
        private readonly TokenStorageInterface $tokenStorage,
        private readonly MfaSessionState $state,
        private readonly Stopwatch $stopwatch,
    ) {
    }

    public function withBegin(TokenInterface $token, SessionInterface $session): void
    {
        $this->tokenStorage->setToken($token);
        $session->set('_security_main', serialize($token));
        $this->state->begin($session, $token->getUser());
    }

    // MfaSessionState handles this (a comment is no call)
    public function withoutBegin(TokenInterface $token): void
    {
        $this->tokenStorage->setToken($token); // expect: gate91.handMadeLogin
    }

    public function logout(): void
    {
        $this->tokenStorage->setToken(null);
    }

    public function beginOfSomethingElse(TokenInterface $token): void
    {
        $this->stopwatch->start('x');
        $this->tokenStorage->setToken($token); // expect: gate91.handMadeLogin
    }

    public function stopwatchBegin(TokenInterface $token): void
    {
        $this->stopwatch->getEvent('x')->start();
        $this->tokenStorage->setToken /* spaced */ ($token); // expect: gate91.handMadeLogin
    }

    public function byName(TokenInterface $token): void
    {
        call_user_func([$this->tokenStorage, 'setToken'], $token); // expect: gate91.handMadeLogin
    }

    public function nullsafe(?TokenStorageInterface $storage, TokenInterface $token): void
    {
        $storage?->setToken($token); // expect: gate91.handMadeLogin
    }

    public function sessionKeyBuilt(SessionInterface $session, string $fw, TokenInterface $token): void
    {
        $session->set('_security_' . $fw, serialize($token)); // expect: gate91.handMadeLogin
        $session->set(sprintf('_security_%s', $fw), serialize($token)); // expect: gate91.handMadeLogin
        $session->set(self::KEY, serialize($token));
        $session->replace(['_security_main' => serialize($token)]); // expect: gate91.handMadeLogin
        $session->set('_security' . '_main', serialize($token)); // expect: gate91.handMadeLogin
        $session->set(sprintf('%s_%s', '_security', 'main'), serialize($token)); // expect: gate91.handMadeLogin
        $session->set('_secure' . '_main', serialize($token));
    }

    public function superGlobal(TokenInterface $token): void
    {
        $_SESSION['x'] = serialize($token); // expect: gate91.handMadeLogin
    }

    public function stateKey(SessionInterface $session): mixed
    {
        return $session->get('_security.mfa_verified'); // expect: gate91.sessionKey
    }

    // @mfa-ok: impersonation of a test user in the dev fixture loader, never reachable in prod
    public function reviewed(TokenInterface $token): void
    {
        $this->tokenStorage->setToken($token);
    }

    public function markerInAString(TokenInterface $token): string
    {
        $this->tokenStorage->setToken($token); // expect: gate91.handMadeLogin

        return '@mfa-ok: not a comment';
    }

    public function beginThroughParameter(MfaSessionState $mfa, SessionInterface $session, TokenInterface $token): void
    {
        $this->tokenStorage->setToken($token);
        $mfa->begin($session, $token->getUser());
    }
}
