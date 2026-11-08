<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion\Kind;

use App\Entity\ProcessingActivity;
use App\Entity\Suggestion;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\ProcessingActivityRepository;
use App\Repository\SuggestionRepository;
use App\Repository\SupplierRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\Kind\AiProcessingActivityFromSupplierKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class AiProcessingActivityFromSupplierKindTest extends TestCase
{
    #[Test]
    public function aSupplierWithAvvAndNoProcessingActivityIsACandidate(): void
    {
        $supplier = $this->supplier(new Tenant());

        self::assertTrue($this->kind($supplier)->supportsRecord($supplier));
        self::assertFalse($this->kind($supplier, existing: [new ProcessingActivity()])->supportsRecord($supplier));
        self::assertFalse($this->kind($supplier)->supportsRecord($this->supplier(new Tenant())->setHasDPA(false)), 'no AVV');
    }

    #[Test]
    public function theDraftKeepsOnlyValuesTheFormOffers(): void
    {
        $supplier = $this->supplier(new Tenant());

        $draft = $this->kind($supplier)->draftFromAssistant(new Tenant(), $supplier, [
            'purpose' => 'Lohn- und Gehaltsabrechnung.',
            'data_subjects' => ['employees', 'aliens'],
            'data_categories' => ['identification', 'financial', 'secret'],
            'retention' => '10 Jahre nach HGB.',
            'legal_basis' => 'vibes',
        ], 'MCP');

        self::assertNotNull($draft);
        self::assertSame(['employees'], $draft->payload['subjects']);
        self::assertSame(['identification', 'financial'], $draft->payload['categories']);
        self::assertSame('contract', $draft->payload['legalBasis']);
        self::assertSame(['contract_fulfillment'], $draft->payload['purposes']);
        self::assertNull($this->kind($supplier)->draftFromAssistant(new Tenant(), $supplier, ['purpose' => 'x', 'data_subjects' => ['aliens']], 'MCP'));
    }

    #[Test]
    public function acceptingCreatesADraftActivityWithTheSupplierAsProcessor(): void
    {
        $tenant = new Tenant();
        $supplier = $this->supplier($tenant);
        $user = new User();
        $created = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($supplier);
        $em->expects(self::once())->method('persist')->with(self::callback(static function (object $o) use (&$created): bool {
            $created = $o;

            return true;
        }));

        $result = $this->kind($supplier, em: $em)->apply($this->suggestion($tenant), $user, "suggestions.text.ai_vvt.retention:\n6 Jahre.");

        self::assertInstanceOf(ProcessingActivity::class, $result);
        self::assertSame($created, $result);
        self::assertSame('draft', $result->getStatus());
        self::assertSame($tenant, $result->getTenant());
        self::assertSame('Lohnabrechnung durch Acme', $result->getName());
        self::assertSame('Lohn- und Gehaltsabrechnung.', $result->getDescription());
        self::assertSame('6 Jahre.', $result->getRetentionPeriod());
        self::assertSame(['employees'], $result->getDataSubjectCategories());
        self::assertTrue($result->getProcessorSuppliers()->contains($supplier));
    }

    #[Test]
    public function aForeignSupplierOrAnActivityWrittenMeanwhileIsStale(): void
    {
        $supplier = $this->supplier(new Tenant());
        try {
            $this->kind($supplier)->apply($this->suggestion(new Tenant()), new User(), null);
            self::fail('tenant mismatch must be stale');
        } catch (StaleSuggestionException) {
            self::addToAssertionCount(1);
        }

        $tenant = new Tenant();
        $own = $this->supplier($tenant);
        $this->expectException(StaleSuggestionException::class);
        $this->kind($own, existing: [new ProcessingActivity()])->apply($this->suggestion($tenant), new User(), null);
    }

    private function supplier(Tenant $tenant): Supplier
    {
        return (new Supplier())
            ->setTenant($tenant)
            ->setName('Acme')
            ->setServiceProvided('Lohn- und Gehaltsabrechnung als Dienstleistung')
            ->setHasDPA(true);
    }

    private function suggestion(Tenant $tenant): Suggestion
    {
        return (new Suggestion($tenant, AiProcessingActivityFromSupplierKind::KEY, SuggestionOrigin::Ai, 'T', 'R', 'k'))
            ->setSubject(Supplier::class, 8)
            ->setPayload([
                'name' => 'Lohnabrechnung durch Acme',
                'purposes' => ['accounting'],
                'subjects' => ['employees'],
                'categories' => ['identification', 'financial'],
                'legalBasis' => 'contract',
                'fields' => ['description' => 'Lohn- und Gehaltsabrechnung.', 'retentionPeriod' => '10 Jahre nach HGB.'],
                'labels' => ['description' => 'suggestions.text.ai_vvt.purpose', 'retentionPeriod' => 'suggestions.text.ai_vvt.retention'],
            ]);
    }

    /** @param list<ProcessingActivity> $existing */
    private function kind(Supplier $found, array $existing = [], ?EntityManagerInterface $em = null): AiProcessingActivityFromSupplierKind
    {
        $activities = $this->createStub(ProcessingActivityRepository::class);
        $activities->method('findByProcessorSupplier')->willReturn($existing);
        if ($em === null) {
            $em = $this->createStub(EntityManagerInterface::class);
            $em->method('find')->willReturn($found);
        }
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        return new AiProcessingActivityFromSupplierKind(
            $this->createStub(AiAssistant::class),
            $this->createStub(SuggestionRepository::class),
            $this->createStub(SupplierRepository::class),
            $activities,
            $em,
            new SuggestionText($translator),
            $translator,
        );
    }
}
