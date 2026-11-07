<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Brute-force protection has to sit where the credentials are checked.
 * form_login answers POST /login inside the firewall, so a limiter in
 * SecurityController::login() never saw a password guess — it only counted
 * page views (and locked out users who reloaded the login page).
 */
final class LoginThrottlingTest extends WebTestCase
{
    #[Test]
    public function sixthWrongPasswordIsThrottled(): void
    {
        $client = static::createClient();
        $username = 'throttle_' . bin2hex(random_bytes(4)) . '@example.com';

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $crawler = $client->request('GET', '/en/login');
            self::assertResponseIsSuccessful('Viewing the login page is never throttled.');
            $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');

            $client->request('POST', '/en/login', [
                '_username' => $username,
                '_password' => 'wrong-' . $attempt,
                '_csrf_token' => $token,
            ]);
            $client->followRedirect();
        }

        $html = (string) $client->getResponse()->getContent();
        self::assertMatchesRegularExpression(
            '/(Too many failed login attempts|Zu viele fehlgeschlagene Anmeldeversuche)/',
            $html,
            'The sixth failed attempt within 15 minutes must be refused by login_throttling.',
        );
    }
}
