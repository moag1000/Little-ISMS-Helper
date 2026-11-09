<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use App\Attribute\ApiReadOnlyEvidence;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementDecision;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Contract of every REST resource, DB-free:
 *
 * - it names its serialization groups, so the API never falls back to "all
 *   properties" (that leaked linked User and Tenant objects of BC plans,
 *   BC exercises, change requests and interested parties);
 * - a lifecycle-managed entity exposes its status read-only — transitions go
 *   through LifecycleService (roles, four-eyes, top management, audit log),
 *   exactly as the HTML forms keep the status field unmapped;
 * - the norm records added with the package waves are read-only resources.
 */
final class ApiResourceContractTest extends TestCase
{
    /**
     * Approval evidence: who approved/verified, when, board acceptance. Written by
     * the acceptance and lifecycle flows, never by an API client (MCP writes go
     * through these operations too).
     */
    private const array APPROVAL_FIELDS = [
        \App\Entity\Risk::class => [
            'formallyAccepted', 'acceptanceApprovedBy', 'acceptanceApprovedByUser', 'acceptanceApprovedAt',
            'decisionApprovedByUser', 'decisionApprovalDate',
            'acceptanceJustification', 'acceptanceExpiryDate', // S12: written by the acceptance request
        ],
        \App\Entity\Supplier::class => ['boardLevelRiskAcceptance'],
        \App\Entity\ChangeRequest::class => [
            'approvedBy', 'approvedDate', 'verifiedBy', 'verifiedDate',
            'approvalComments', 'implementedBy', 'actualImplementationDate', 'closedDate',
        ],
        // S4 (2026-11): report times decide whether the NIS-2/DORA deadline was
        // kept — written only by "Als gemeldet markieren", never by a request body.
        \App\Entity\Incident::class => [
            'earlyWarningReportedAt', 'detailedNotificationReportedAt', 'finalReportSubmittedAt',
            'initialReportSubmittedAt', 'intermediateReportSubmittedAt', 'doraFinalReportSubmittedAt',
            'nis2InterimRequestedAt', 'nis2InterimDueAt', 'nis2InterimReportedAt', 'resolvedAt', 'closedAt',
            // W3: authority and reference of the report are part of the report evidence.
            'nationalAuthorityNotified', 'authorityReferenceNumber',
        ],
        // W3: test results and measurements (ISO 27001 9.1, ISO 22301 8.5).
        \App\Entity\Control::class => ['effectiveness', 'lastEffectivenessTest'],
        \App\Entity\BCExercise::class => ['reportCompleted', 'actualRtoAchieved'],
        \App\Entity\BusinessContinuityPlan::class => ['lastTested'],
        \App\Entity\ISMSObjective::class => ['currentValue'],
    ];

    private const array READ_ONLY_RESOURCES = [
        ManagementDecision::class,
        IsmsRegisterEntry::class,
        ComplianceRequirementFulfillment::class,
    ];

    /** @return iterable<string, array{class-string}> */
    public static function apiResources(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            if ((new ReflectionClass($class))->getAttributes(ApiResource::class) !== []) {
                yield (new ReflectionClass($class))->getShortName() => [$class];
            }
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('apiResources')]
    public function everyResourceNamesItsSerializationGroups(string $class): void
    {
        $resource = $this->resource($class);

        self::assertNotEmpty(
            $resource->getNormalizationContext()['groups'] ?? [],
            sprintf('%s: #[ApiResource] needs normalizationContext groups — without them every property is serialized.', $class),
        );

        $writes = array_filter(
            iterator_to_array($resource->getOperations() ?? []),
            static fn ($op): bool => !$op instanceof Get && !$op instanceof GetCollection,
        );
        if ($writes !== []) {
            self::assertNotEmpty(
                $resource->getDenormalizationContext()['groups'] ?? [],
                sprintf('%s: a writable resource needs denormalizationContext groups.', $class),
            );
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('apiResources')]
    public function lifecycleStatusIsReadOnlyOnTheApi(string $class): void
    {
        if (!in_array($class, EntityTypeRegistry::managedEntityClasses(), true)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $ref = new ReflectionClass($class);
        foreach (['status', 'statusValue'] as $property) {
            if (!$ref->hasProperty($property)) {
                continue;
            }
            foreach ($ref->getProperty($property)->getAttributes(Groups::class) as $attribute) {
                foreach ($attribute->newInstance()->groups as $group) {
                    self::assertStringEndsNotWith(
                        ':write',
                        $group,
                        sprintf('%s::$%s is in write group "%s" — status changes must go through LifecycleService.', $class, $property, $group),
                    );
                }
            }
        }
        self::assertTrue(true);
    }

    #[Test]
    public function approvalEvidenceIsNotWritableOverTheApi(): void
    {
        // The known evidence fields must carry the attribute ...
        foreach (self::APPROVAL_FIELDS as $class => $properties) {
            $ref = new ReflectionClass($class);
            foreach ($properties as $property) {
                self::assertTrue($ref->hasProperty($property), sprintf('%s::$%s no longer exists.', $class, $property));
                self::assertNotSame(
                    [],
                    $ref->getProperty($property)->getAttributes(ApiReadOnlyEvidence::class),
                    sprintf('%s::$%s is workflow/approval evidence: mark it #[ApiReadOnlyEvidence].', $class, $property),
                );
            }
        }

        // ... and no field carrying it, on any entity, is in a write group.
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if ($property->getAttributes(ApiReadOnlyEvidence::class) === []) {
                    continue;
                }
                ++$checked;
                foreach ($property->getAttributes(Groups::class) as $attribute) {
                    foreach ($attribute->newInstance()->groups as $group) {
                        self::assertStringEndsNotWith(':write', $group, sprintf('%s::$%s is API-writable.', $class, $property->getName()));
                    }
                }
            }
        }
        self::assertGreaterThanOrEqual(25, $checked);
    }

    /** @return iterable<string, array{class-string}> */
    public static function readOnlyResources(): iterable
    {
        foreach (self::READ_ONLY_RESOURCES as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('readOnlyResources')]
    public function normRecordsAreReadOnly(string $class): void
    {
        foreach ($this->resource($class)->getOperations() ?? [] as $operation) {
            self::assertTrue(
                $operation instanceof Get || $operation instanceof GetCollection,
                sprintf('%s must stay read-only on the API, found %s.', $class, $operation::class),
            );
            self::assertNotNull($operation->getSecurity(), sprintf('%s: every operation needs a security expression.', $class));
        }
    }

    #[Test]
    public function managementDecisionsAreForReviewersAndAbove(): void
    {
        foreach ($this->resource(ManagementDecision::class)->getOperations() ?? [] as $operation) {
            self::assertStringContainsString('ROLE_AUDITOR', (string) $operation->getSecurity());
        }
    }

    /**
     * S6: every operation decides access itself — an operation without a
     * security expression is open to any token. Item operations vote on the
     * record (`object`), which is where the organisation is compared.
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('apiResources')]
    public function everyOperationNamesItsSecurity(string $class): void
    {
        foreach ($this->resource($class)->getOperations() ?? [] as $operation) {
            $expression = $operation->getSecurity() ?? $operation->getSecurityPostDenormalize();
            $label = sprintf('%s %s', $class, $operation::class);
            self::assertNotNull($expression, $label . ': every operation needs security or securityPostDenormalize.');
            if (!$operation instanceof GetCollection && !$operation instanceof \ApiPlatform\Metadata\Post) {
                self::assertStringContainsString('object', (string) $operation->getSecurity(), $label . ': an item operation votes on the record (object).');
            }
        }
    }

    /**
     * S6: a writing resource of an organisation stores through
     * TenantAwareStateProcessor, which pins a new record to the writer's
     * organisation whatever the body says.
     *
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('apiResources')]
    public function everyWritingResourcePinsTheOrganisation(string $class): void
    {
        $resource = $this->resource($class);
        if (!(new ReflectionClass($class))->hasProperty('tenant')) {
            $this->expectNotToPerformAssertions();

            return;
        }
        foreach ($resource->getOperations() ?? [] as $operation) {
            if ($operation instanceof Get || $operation instanceof GetCollection) {
                continue;
            }
            self::assertSame(
                \App\State\TenantAwareStateProcessor::class,
                $operation->getProcessor() ?? $resource->getProcessor(),
                sprintf('%s %s must store through TenantAwareStateProcessor.', $class, $operation::class),
            );
        }
        self::assertTrue(true);
    }

    /**
     * S6: credentials and secrets are never serialized, on any entity — a
     * group on such a property would ship it in every response that embeds it.
     */
    #[Test]
    public function noCredentialIsInASerializationGroup(): void
    {
        $credential = '/password|secret|token|mfa|totp|backup_?code|reset_?(code|token)|api_?key|private_?key|sso_?external|credential/i';
        $checked = 0;
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            foreach ((new ReflectionClass($class))->getProperties() as $property) {
                if (preg_match($credential, $property->getName()) !== 1) {
                    continue;
                }
                ++$checked;
                self::assertSame(
                    [],
                    $property->getAttributes(Groups::class),
                    sprintf('%s::$%s looks like a credential and must not carry serialization groups.', $class, $property->getName()),
                );
            }
        }
        self::assertGreaterThan(5, $checked, 'the credential pattern no longer finds the User/ApiToken fields');
    }

    /** @param class-string $class */
    private function resource(string $class): ApiResource
    {
        $attributes = (new ReflectionClass($class))->getAttributes(ApiResource::class);
        self::assertNotEmpty($attributes, $class . ' is not an API resource');

        return $attributes[0]->newInstance();
    }

    #[Test]
    public function theDetectionTimeOfAnIncidentIsSetOnceOnCreate(): void
    {
        // W3: detectedAt anchors the NIS-2 / GDPR deadlines — an update must not move it.
        $groups = [];
        foreach ((new ReflectionClass(\App\Entity\Incident::class))->getProperty('detectedAt')->getAttributes(Groups::class) as $attribute) {
            $groups = [...$groups, ...$attribute->newInstance()->groups];
        }

        self::assertContains('incident:create', $groups);
        self::assertNotContains('incident:write', $groups);
    }
}
