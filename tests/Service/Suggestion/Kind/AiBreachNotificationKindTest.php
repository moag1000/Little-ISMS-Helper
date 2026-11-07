<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\DataBreach;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\DataBreachRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiBreachNotificationKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiBreachNotificationKindTest extends TestCase
{
    private const array LABELS = [
        'breachNature' => 'Art der Verletzung',
        'likelyConsequences' => 'Wahrscheinliche Folgen',
        'measuresTaken' => 'Maßnahmen',
    ];

    #[Test]
    public function acceptingFillsOnlyTheEmptyFieldsFromTheEditedText(): void
    {
        $tenant = new Tenant();
        $breach = (new DataBreach())->setTenant($tenant)->setLikelyConsequences('Schon beschrieben.');
        $edited = "Art der Verletzung:\nLaptop gestohlen, 120 Kundendatensätze.\n\nWahrscheinliche Folgen:\nÜberschrieben?\n\nMaßnahmen:\nGerät gesperrt.";

        $result = $this->kind($breach)->apply($this->suggestion($tenant), new User(), $edited);

        self::assertSame($breach, $result);
        self::assertSame('Laptop gestohlen, 120 Kundendatensätze.', $breach->getBreachNature());
        self::assertSame('Schon beschrieben.', $breach->getLikelyConsequences(), 'a field written meanwhile stays');
        self::assertSame('Gerät gesperrt.', $breach->getMeasuresTaken());
    }

    #[Test]
    public function aBlockWhoseHeadingWasRemovedKeepsTheDraftedValue(): void
    {
        $tenant = new Tenant();
        $breach = (new DataBreach())->setTenant($tenant);

        $this->kind($breach)->apply($this->suggestion($tenant), new User(), "Maßnahmen:\nAnders formuliert.");

        self::assertSame('Entwurf Art', $breach->getBreachNature());
        self::assertSame('Anders formuliert.', $breach->getMeasuresTaken());
    }

    #[Test]
    public function nothingLeftToFillIsStale(): void
    {
        $tenant = new Tenant();
        $breach = (new DataBreach())->setTenant($tenant)
            ->setBreachNature('a')->setLikelyConsequences('b')->setMeasuresTaken('c');

        $this->expectException(StaleSuggestionException::class);
        $this->kind($breach)->apply($this->suggestion($tenant), new User(), null);
    }

    #[Test]
    public function aBreachAlreadyNotifiedIsNoCandidate(): void
    {
        $breach = (new DataBreach())->setTitle('Laptop gestohlen in der Bahn')
            ->setSupervisoryAuthorityNotifiedAt(new \DateTime());

        self::assertFalse($this->kind($breach)->supportsRecord($breach));
        self::assertTrue($this->kind($breach)->supportsRecord((new DataBreach())->setTitle('Laptop gestohlen in der Bahn')));
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiBreachNotificationKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(DataBreach::class, 3)
            ->setPayload([
                'fields' => ['breachNature' => 'Entwurf Art', 'measuresTaken' => 'Entwurf Maßnahmen'],
                'labels' => self::LABELS,
            ]);
    }

    private function kind(DataBreach $breach): AiBreachNotificationKind
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($breach);

        return new AiBreachNotificationKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(DataBreachRepository::class),
            $em,
            new SuggestionText($this->createStub(TranslatorInterface::class)),
        );
    }
}
