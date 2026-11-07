<?php

declare(strict_types=1);

namespace App\Tests\Service\Ai;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Ai\AiAssistant;
use App\Service\Ai\AiUnavailableException;
use App\Service\Ai\PromptRedactor;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Personal data leaves the house only with opt-in (Paket 5, GDPR Art. 5(1)(c)). */
final class PromptRedactorTest extends TestCase
{
    #[Test]
    public function contactDataIsReplacedButDatesAndScoresSurvive(): void
    {
        $out = $this->redactor()->redact(
            'Mail an it@example.org, Tel. +49 30 1234567, IBAN DE89 3704 0044 0532 0130 00, Host 10.0.0.12, am 07.11.2026 mit CVSS 9.8.',
            new Tenant(),
        );

        self::assertStringNotContainsString('it@example.org', $out);
        self::assertStringNotContainsString('1234567', $out);
        self::assertStringNotContainsString('DE89', $out);
        self::assertStringNotContainsString('10.0.0.12', $out);
        self::assertStringContainsString('[E-Mail]', $out);
        self::assertStringContainsString('07.11.2026', $out);
        self::assertStringContainsString('9.8', $out);
    }

    #[Test]
    public function namesOfTheOrganisationArePseudonymised(): void
    {
        $user = new User();
        $user->setFirstName('Erika');
        $user->setLastName('Mustermann');

        $out = $this->redactor([$user])->redact('Erika Mustermann meldete den Vorfall, Mustermann ist zuständig. IT prüft.', new Tenant());

        self::assertStringNotContainsString('Mustermann', $out);
        self::assertStringContainsString('[Person', $out);
        self::assertStringContainsString('IT prüft', $out, 'short words are not names');
    }

    #[Test]
    public function decodeTakesTheJsonObjectOutOfProse(): void
    {
        self::assertSame(['title' => 'X'], AiAssistant::decode("Hier ist es:\n```json\n{\"title\": \"X\"}\n```"));
    }

    #[Test]
    public function decodeRejectsAnswersWithoutJson(): void
    {
        $this->expectException(AiUnavailableException::class);
        AiAssistant::decode('Leider kann ich das nicht.');
    }

    /** @param list<User> $users */
    private function redactor(array $users = []): PromptRedactor
    {
        $userRepo = $this->createStub(EntityRepository::class);
        $userRepo->method('findBy')->willReturn($users);
        $personRepo = $this->createStub(EntityRepository::class);
        $personRepo->method('findBy')->willReturn([]);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [User::class, $userRepo],
            [Person::class, $personRepo],
        ]);

        return new PromptRedactor($em);
    }
}
