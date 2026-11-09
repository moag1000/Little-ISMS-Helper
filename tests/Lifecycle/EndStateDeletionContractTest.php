<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\AuditFinding;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Entity\User;
use App\EventListener\DeletionGuard;
use App\Lifecycle\DeletionPolicy;
use App\Lifecycle\DeletionRefusedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Workflow\Registry;
use Symfony\Component\Yaml\Yaml;

/**
 * Gate 100 (S29): evidence is archived, never deleted — ISO 27001 7.5.3, A.5.33.
 * Everything this test checks it finds itself, so a new lifecycle, delete route
 * or foreign key is covered without editing it:
 *
 * - every lifecycle place that names a finished, evidenced step (closed,
 *   reported, approved, published …) is marked `end_state: true`, unless
 *   listed in NOT_EVIDENCE with a reason;
 * - the {@see DeletionPolicy} refuses a record in every marked end state, read
 *   from the registered workflows (no hand list);
 * - no evidence class loses rows by cascade: no ON DELETE CASCADE from an
 *   evidence class to anything but its organisation, no ORM cascade remove or
 *   orphan removal onto an evidence class (baseline: CASCADE_BASELINE, empty
 *   but for reviewed link collections);
 * - a list's bulk delete needs at least the role of the record's single delete;
 * - every entry point asks the policy, and the flush guard is registered;
 * - functional: a reported audit and an audit with findings are not deleted
 *   while a person acts in a request; a planned audit without findings is.
 */
final class EndStateDeletionContractTest extends KernelTestCase
{
    /** Place names that mean "a step was done and is to be proven". */
    private const array EVIDENCE_PLACE_NAMES = [
        'closed', 'archived', 'disposed', 'reported', 'approved', 'completed', 'terminated',
        'authority_notified', 'published',
    ];

    /** workflow.place => why it is no evidence end state. */
    private const array NOT_EVIDENCE = [
        'compliance_mapping_lifecycle.approved' => 'global catalogue data, guarded by GlobalCatalogueVoter (S3)',
        'compliance_mapping_lifecycle.published' => 'global catalogue data, guarded by GlobalCatalogueVoter (S3)',
        'policy_template_lifecycle.approved' => 'global template catalogue, not a record of the organisation',
        'policy_template_lifecycle.published' => 'global template catalogue, not a record of the organisation',
        'policy_template_lifecycle.archived' => 'global template catalogue, not a record of the organisation',
        'incident_lifecycle.reported' => 'initial status of every new incident (reported internally), not the authority report',
        'patch_lifecycle.approved' => 'planning step before deployment; `deployed` is the evidence',
        'interested_party_lifecycle.archived' => 'context register entry (4.2), no performed step to prove',
        'threat_intelligence_lifecycle.closed' => 'external intelligence feed item, no performed step to prove',
        'notification_delivery_lifecycle.archived' => 'operational delivery queue',
        'permission_lifecycle.archived' => 'system configuration',
        'role_lifecycle.archived' => 'system configuration',
        'tenant_lifecycle.terminated' => 'organisation offboarding is an instance operation (ADMIN_GLOBAL_OP)',
        'tenant_lifecycle.archived' => 'organisation offboarding is an instance operation (ADMIN_GLOBAL_OP)',
    ];

    /**
     * Reviewed cascades onto evidence classes: "Class.field" => reason. Ratchet —
     * new entries need a reason a reviewer accepts; the goal is an empty list.
     */
    private const array CASCADE_BASELINE = [
        'App\Entity\Incident.riskLinks' => 'orphanRemoval of the link collection: unlinking a risk in the incident form;'
            . ' the incident itself is not deleted while links exist (policy rule 3, FK RESTRICT)',
    ];

    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function everyEvidencePlaceIsMarkedAsEndState(): void
    {
        $root = dirname(__DIR__, 2);
        $missing = [];
        $seen = 0;
        foreach (glob($root . '/config/workflows/*.yaml') ?: [] as $file) {
            $config = Yaml::parseFile($file);
            foreach ($config['framework']['workflows'] ?? [] as $name => $workflow) {
                foreach ($workflow['places'] ?? [] as $place) {
                    $placeName = is_array($place) ? (string) $place['name'] : (string) $place;
                    $endState = is_array($place) && (($place['metadata']['end_state'] ?? false) === true);
                    if ($endState) {
                        ++$seen;
                    }
                    if (!in_array($placeName, self::EVIDENCE_PLACE_NAMES, true) || $endState) {
                        continue;
                    }
                    if (!isset(self::NOT_EVIDENCE[$name . '.' . $placeName])) {
                        $missing[] = $name . '.' . $placeName;
                    }
                }
            }
        }

        self::assertGreaterThan(50, $seen, 'end_state markers not found — YAML layout changed?');
        self::assertSame([], $missing, 'Evidence place without `end_state: true` (or a reason in NOT_EVIDENCE).');
    }

    #[Test]
    public function thePolicyRefusesEveryMarkedEndState(): void
    {
        /** @var DeletionPolicy $policy */
        $policy = self::getContainer()->get(DeletionPolicy::class);
        /** @var Registry $registry */
        $registry = self::getContainer()->get('workflow.registry');

        $checked = 0;
        $failures = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            $class = $metadata->getName();
            $endStates = $policy->endStatesOf($class);
            if ($endStates === []) {
                continue;
            }
            foreach ($endStates as $place) {
                $record = $this->recordOf($class);
                $workflow = null;
                foreach ($registry->all($record) as $candidate) {
                    if (str_ends_with($candidate->getName(), '_lifecycle')) {
                        $workflow = $candidate;
                        break;
                    }
                }
                self::assertNotNull($workflow, $class);
                $workflow->getMarkingStore()->setMarking($record, new \Symfony\Component\Workflow\Marking([$place => 1]));
                ++$checked;
                if ($policy->refusal($record) === null) {
                    $failures[] = $class . ' in ' . $place;
                }
            }
        }

        self::assertGreaterThan(25, $checked);
        self::assertSame([], $failures, 'Deletable although in an end state.');
    }

    #[Test]
    public function evidenceClassesLoseNoRowsByCascade(): void
    {
        /** @var DeletionPolicy $policy */
        $policy = self::getContainer()->get(DeletionPolicy::class);
        $evidence = $policy->evidenceClasses();
        self::assertContains(InternalAudit::class, $evidence);
        self::assertContains(\App\Entity\TrainingParticipation::class, $evidence);

        $violations = [];
        foreach ($this->em->getMetadataFactory()->getAllMetadata() as $metadata) {
            foreach ($metadata->getAssociationMappings() as $field => $mapping) {
                $key = $metadata->getName() . '.' . $field;
                // DB cascade: deleting the parent removes evidence rows without any event.
                if (in_array($metadata->getName(), $evidence, true)
                    && $mapping->isToOneOwningSide()
                    && $mapping->targetEntity !== Tenant::class
                    && strtoupper((string) ($mapping->joinColumns[0]->onDelete ?? '')) === 'CASCADE'
                ) {
                    $violations[] = $key . ' (ON DELETE CASCADE)';
                }
                // ORM cascade: the parent's remove takes the evidence rows along.
                $cascadesRemove = in_array('remove', $mapping->cascade, true) || $mapping->orphanRemoval;
                if ($cascadesRemove && in_array($mapping->targetEntity, $evidence, true)
                    && !isset(self::CASCADE_BASELINE[$key])
                ) {
                    $violations[] = $key . ' (cascade remove / orphanRemoval)';
                }
            }
        }

        self::assertSame([], $violations, 'Evidence rows deleted by cascade (ISO 27001 7.5.3).');
    }

    #[Test]
    public function bulkDeleteNeedsAtLeastTheRoleOfTheSingleDelete(): void
    {
        /** @var RouterInterface $router */
        $router = self::getContainer()->get('router');
        /** @var RoleHierarchyInterface $hierarchy */
        $hierarchy = self::getContainer()->get('security.role_hierarchy');
        $routes = $router->getRouteCollection();

        $pairs = 0;
        $weaker = [];
        foreach ($routes as $name => $route) {
            if (!str_ends_with($name, '_bulk_delete')) {
                continue;
            }
            $single = $routes->get(substr($name, 0, -strlen('_bulk_delete')) . '_delete');
            if ($single === null) {
                continue;
            }
            $bulkRole = $this->roleOf((string) $route->getDefault('_controller'));
            $singleRole = $this->roleOf((string) $single->getDefault('_controller'));
            if ($bulkRole === null || $singleRole === null || !str_starts_with($singleRole, 'ROLE_')) {
                continue; // voter attribute on the single action: BulkDeleteService checks a voter per record
            }
            ++$pairs;
            if (!in_array($singleRole, $hierarchy->getReachableRoleNames([$bulkRole]), true)) {
                $weaker[] = sprintf('%s (%s) < single delete (%s)', $name, $bulkRole, $singleRole);
            }
        }

        self::assertGreaterThan(10, $pairs);
        self::assertSame([], $weaker, 'A list may not delete with less than the single delete needs (S29 F7).');
    }

    #[Test]
    public function everyEntryPointAsksThePolicy(): void
    {
        $root = dirname(__DIR__, 2);
        foreach ([
            'src/Controller/AssetController.php' => '->refusal(',
            'src/Service/BulkDeleteService.php' => '->refusal(',
            'src/State/TenantAwareStateProcessor.php' => '->refusal(',
            'src/EventListener/DeletionGuard.php' => '->refusal(',
            'src/Service/RetentionEnforcementService.php' => '->childRefusal(',
        ] as $file => $call) {
            self::assertStringContainsString($call, (string) file_get_contents($root . '/' . $file), $file);
        }

        $listeners = $this->em->getEventManager()->getListeners(\Doctrine\ORM\Events::onFlush);
        $guards = array_filter($listeners, static fn (object $l): bool => $l instanceof DeletionGuard);
        self::assertNotEmpty($guards, 'DeletionGuard is not registered for onFlush.');
    }

    // @mfa-ok: kernel test — a token stands for the acting person, no login happens
    #[Test]
    public function aPersonCannotDeleteEvidenceButAnUnusedDraft(): void
    {
        $this->em->beginTransaction();
        /** @var RequestStack $requestStack */
        $requestStack = self::getContainer()->get('request_stack');
        $tokenStorage = self::getContainer()->get('security.token_storage');
        try {
            $uid = substr(uniqid('', true), -8);
            $tenant = (new Tenant())->setName('S29 ' . $uid)->setCode('s29_' . $uid);
            $this->em->persist($tenant);
            $reported = $this->audit($tenant, 'R' . $uid, 'reported');
            $withFinding = $this->audit($tenant, 'F' . $uid, 'planned');
            $finding = (new AuditFinding())->setFindingNumber('F-' . $uid)->setTitle('t')->setDescription('d');
            $finding->setTenant($tenant);
            $withFinding->addStructuredFinding($finding);
            $this->em->persist($finding);
            $draft = $this->audit($tenant, 'D' . $uid, 'planned');
            $this->em->flush();

            $actor = (new User())->setEmail('s29_' . $uid . '@x.test')->setRoles(['ROLE_ADMIN']);
            $tokenStorage->setToken(new UsernamePasswordToken($actor, 'main', ['ROLE_ADMIN']));
            $requestStack->push(Request::create('/de/audit/delete'));

            foreach ([$reported->getId(), $withFinding->getId()] as $auditId) {
                $audit = $this->em->find(InternalAudit::class, $auditId);
                self::assertNotNull($audit);
                $this->em->remove($audit);
                try {
                    $this->em->flush();
                    self::fail('Evidence deleted: ' . $audit->getAuditNumber());
                } catch (DeletionRefusedException) {
                    $this->em->clear();
                }
                self::assertNotNull($this->em->find(InternalAudit::class, $auditId), 'kept');
            }

            $draftId = $draft->getId();
            $draft = $this->em->find(InternalAudit::class, $draftId);
            self::assertNotNull($draft);
            $this->em->remove($draft);
            $this->em->flush();
            self::assertNull($this->em->find(InternalAudit::class, $draftId));
        } finally {
            $requestStack->pop();
            $tokenStorage->setToken(null);
            if ($this->em->getConnection()->isTransactionActive()) {
                $this->em->rollback();
            }
        }
    }

    #[Test]
    public function aClosedIncidentIsKept(): void
    {
        /** @var DeletionPolicy $policy */
        $policy = self::getContainer()->get(DeletionPolicy::class);
        $incident = new Incident();
        $incident->setStatusValue('closed');

        self::assertNotNull($policy->refusal($incident));
    }

    private function audit(Tenant $tenant, string $number, string $status): InternalAudit
    {
        $audit = new InternalAudit();
        $audit->setTenant($tenant);
        $audit->setAuditNumber('AUD-' . $number);
        $audit->setTitle('Audit ' . $number);
        $audit->setScope('ISMS');
        $audit->setPlannedDate(new \DateTime());
        $audit->setStatus($status);
        $this->em->persist($audit);

        return $audit;
    }

    private function recordOf(string $class): object
    {
        try {
            return new $class();
        } catch (\Throwable) {
            return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
        }
    }

    /** The first role named in #[IsGranted] on the action (else its class). */
    private function roleOf(string $controller): ?string
    {
        if (!str_contains($controller, '::')) {
            return null;
        }
        [$class, $method] = explode('::', $controller, 2);
        if (!method_exists($class, $method)) {
            return null;
        }
        foreach ([new \ReflectionMethod($class, $method), new \ReflectionClass($class)] as $reflection) {
            foreach ($reflection->getAttributes(IsGranted::class) as $attribute) {
                $attribute = $attribute->getArguments()['attribute'] ?? $attribute->getArguments()[0] ?? null;
                if (is_string($attribute)) {
                    return $attribute;
                }
            }
        }

        return null;
    }
}
