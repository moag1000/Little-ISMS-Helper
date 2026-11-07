<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\DocumentControlLink;
use App\Entity\Tenant;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ControlRepository;
use App\Repository\DocumentControlLinkRepository;
use App\Service\Compliance\Fulfillment\FulfillmentRuleEngine;
use App\Service\Compliance\Fulfillment\RequirementEvidence;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class RequirementEvidenceTest extends TestCase
{
    private int $nextId = 1;

    #[Test]
    public function evidenceComesFromRequirementFulfilmentAndControlsOfTheOrganisationOnly(): void
    {
        $own = $this->withId(new Tenant());
        $other = $this->withId(new Tenant());

        $policy = $this->document($own, 'policy.pdf');
        $foreign = $this->document($other, 'foreign.pdf');
        $report = $this->document($own, 'pentest.pdf');
        $linked = $this->document($own, 'linked.pdf');

        $control = $this->withId((new Control())->setTenant($own)->setControlId('5.1')->setName('Policies'));
        $control->addEvidenceDocument($policy);
        $foreignControl = $this->withId((new Control())->setTenant($other)->setControlId('5.1')->setName('Foreign'));
        $foreignControl->addEvidenceDocument($foreign);

        $requirement = $this->withId((new ComplianceRequirement())
            ->setFramework((new ComplianceFramework())->setCode('NIS2')->setName('NIS2'))
            ->setRequirementId('NIS2-21.2.a')
            ->setDataSourceMapping(['iso_controls' => ['A.5.1']]));
        $requirement->addMappedControl($foreignControl);
        $requirement->addEvidenceDocument($foreign);
        $requirement->addEvidenceDocument($policy);

        $fulfillment = (new ComplianceRequirementFulfillment())->setTenant($own)->setRequirement($requirement);
        $fulfillment->addEvidenceDocument($report);

        $link = (new DocumentControlLink())->setDocument($linked)->setControl($control);

        $evidence = $this->service([$control, $foreignControl], [$link])->for($requirement, $own, $fulfillment);

        $byName = [];
        foreach ($evidence as $row) {
            $byName[(string) $row['document']->getOriginalFilename()] = array_column($row['sources'], 'type');
        }

        self::assertSame([
            'policy.pdf' => [RequirementEvidence::SOURCE_REQUIREMENT, RequirementEvidence::SOURCE_CONTROL],
            'pentest.pdf' => [RequirementEvidence::SOURCE_FULFILLMENT],
            'linked.pdf' => [RequirementEvidence::SOURCE_CONTROL],
        ], $byName, 'Another organisation\'s documents never show; one document appears once with every source.');
    }

    /**
     * @param list<Control> $controls
     * @param list<DocumentControlLink> $links
     */
    private function service(array $controls, array $links): RequirementEvidence
    {
        $controlRepo = $this->createStub(ControlRepository::class);
        $controlRepo->method('findByTenant')->willReturnCallback(
            static fn (Tenant $t): array => array_values(array_filter($controls, static fn (Control $c): bool => $c->getTenant() === $t)),
        );
        $fulfillments = $this->createStub(ComplianceRequirementFulfillmentRepository::class);
        $linkRepo = $this->createStub(DocumentControlLinkRepository::class);
        $linkRepo->method('findByControl')->willReturnCallback(
            static fn (Control $c): array => array_values(array_filter($links, static fn (DocumentControlLink $l): bool => $l->getControl() === $c)),
        );

        $engine = new FulfillmentRuleEngine($controlRepo, $this->createStub(ComplianceMappingRepository::class), $fulfillments);

        return new RequirementEvidence($engine, $fulfillments, $linkRepo);
    }

    private function document(Tenant $tenant, string $name): Document
    {
        $document = new Document();
        $document->setTenant($tenant);
        $document->setOriginalFilename($name);

        return $this->withId($document);
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function withId(object $entity): object
    {
        (new ReflectionProperty($entity, 'id'))->setValue($entity, $this->nextId++);

        return $entity;
    }
}
