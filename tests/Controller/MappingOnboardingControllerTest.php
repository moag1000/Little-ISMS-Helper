<?php
declare(strict_types=1);
namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use PHPUnit\Framework\Attributes\Test;

final class MappingOnboardingControllerTest extends WebTestCase
{
    #[Test]
    public function hub_redirects_when_unauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/de/compliance/mapping-onboarding');
        self::assertTrue($client->getResponse()->isRedirect(), 'unauthenticated hub must redirect to login');
    }
}
