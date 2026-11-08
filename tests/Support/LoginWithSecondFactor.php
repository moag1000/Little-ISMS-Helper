<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\User;
use App\Security\Mfa\MfaSessionState;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * `loginUser()` for a person with an active second factor. A session without a login event fails
 * closed since S8 (the enforcer asks for the code); a test that is not about MFA marks the login
 * as having passed it, the way MfaLoginController does after the code.
 */
final class LoginWithSecondFactor
{
    private function __construct()
    {
    }

    public static function login(KernelBrowser $client, User $user): void
    {
        $client->loginUser($user);

        $session = $client->getContainer()->get('session.factory')->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        if ($cookie === null) {
            throw new \LogicException('loginUser() left no session cookie.');
        }
        $session->setId($cookie->getValue());
        $session->start();
        $state = new MfaSessionState();
        $state->begin($session, $user);
        $state->markVerified($session, $user);
        $session->save();
    }
}
