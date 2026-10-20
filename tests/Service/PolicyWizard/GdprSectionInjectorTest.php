<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\DocumentSection;
use App\Entity\EntityTag;
use App\Entity\PolicyTemplate;
use App\Entity\Tag;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\DocumentSectionRepository;
use App\Repository\TagRepository;
use App\Service\PolicyWizard\GdprSectionCatalogue;
use App\Service\PolicyWizard\GdprSectionInjector;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class GdprSectionInjectorTest extends TestCase
{
    /** @var list<object> */
    private array $persisted = [];

    private function injector(?DocumentSection $existing = null): GdprSectionInjector
    {
        $this->persisted = [];
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });
        $tags = $this->createStub(TagRepository::class);
        $tags->method('findOneByName')->willReturn(null);
        $sections = $this->createStub(DocumentSectionRepository::class);
        $sections->method('findOneByDocumentAndKey')->willReturn($existing);

        return new GdprSectionInjector($em, $tags, new GdprSectionCatalogue(), $sections);
    }

    private function document(string $standard, string $topic): Document
    {
        $template = (new PolicyTemplate())->setStandard($standard)->setTopic($topic);
        $document = new Document();
        $document->setTenant($this->createStub(Tenant::class));
        $document->setGeneratedFromTemplate($template);
        (new \ReflectionProperty(Document::class, 'id'))->setValue($document, 42);

        return $document;
    }

    /** @return list<object> */
    private function persisted(): array
    {
        return $this->persisted;
    }

    /** @param list<string> $standards */
    private function wizardRun(array $standards): WizardRun
    {
        return (new WizardRun())->setStandardsAdopted($standards);
    }

    #[Test]
    public function gdprScopeAcceptsGdprAndIso27701Alias(): void
    {
        self::assertTrue(GdprSectionInjector::isGdprScopeActive($this->wizardRun(['iso27001', 'gdpr'])));
        self::assertTrue(GdprSectionInjector::isGdprScopeActive($this->wizardRun(['iso27701'])));
        self::assertFalse(GdprSectionInjector::isGdprScopeActive($this->wizardRun(['iso27001'])));
    }

    #[Test]
    public function injectsDraftSectionWithCatalogueRoleAndAppliedTag(): void
    {
        $injector = $this->injector();
        $injector->inject($this->document('iso27001', 'incident_management'), $this->wizardRun(['iso27001', 'gdpr']));

        $sections = array_values(array_filter($this->persisted, static fn(object $o): bool => $o instanceof DocumentSection));
        self::assertCount(1, $sections);
        self::assertSame('gdpr_breach_72h', $sections[0]->getSectionKey());
        self::assertSame(DocumentSection::STATUS_DRAFT, $sections[0]->getStatus());
        self::assertNotNull($sections[0]->getApprovalRole());

        $tags = array_values(array_filter($this->persisted, static fn(object $o): bool => $o instanceof Tag));
        self::assertSame(['gdpr-section:gdpr_breach_72h:applied'], array_map(static fn(Tag $t): string => (string) $t->getName(), $tags));
        $links = array_filter($this->persisted, static fn(object $o): bool => $o instanceof EntityTag);
        self::assertCount(1, $links);
    }

    #[Test]
    public function skipsRunsWithoutGdprScope(): void
    {
        $this->injector()->inject($this->document('iso27001', 'incident_management'), $this->wizardRun(['iso27001']));
        self::assertSame([], $this->persisted());
    }

    #[Test]
    public function skipsNonIsoHostDocuments(): void
    {
        $this->injector()->inject($this->document('dora', 'incident_management'), $this->wizardRun(['dora', 'gdpr']));
        self::assertSame([], $this->persisted());
    }

    #[Test]
    public function isIdempotentWhenTheSectionAlreadyExists(): void
    {
        $this->injector(new DocumentSection())
            ->inject($this->document('iso27001', 'incident_management'), $this->wizardRun(['iso27001', 'gdpr']));
        self::assertSame([], $this->persisted());
    }
}
