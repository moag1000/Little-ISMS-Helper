<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\CrisisTeam;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * S14 Cluster A — Quick-Create API Endpoint
 *
 * Lightweight JSON endpoint that creates a minimal-viable entity (name +
 * required-by-DB defaults) and returns its ID + display label so the
 * Stimulus `quick-create-modal` controller can append the new option to
 * a parent TomSelect / native select on the page.
 *
 * Scope: deliberately minimal. The intent is "empty-state-rescue" — the
 * user is filling out a parent form, realises a child entity is missing,
 * creates a stub right here, and continues. Full entity editing happens
 * later via the normal CRUD UI; the stub created here is just enough to
 * satisfy the parent form's FK constraint.
 *
 * Whitelisted entity types (slug => FQCN + factory):
 *   - asset           → Asset (BusinessProcess.supportingAssets, BC plan critical_assets, Supplier.supportedAssets)
 *   - crisis-team     → CrisisTeam (BC plan crisisTeams)
 *   - business-process → BusinessProcess (BC plan businessProcess — though for C1-03 we use a CRUD link)
 *
 * All creates are tenant-scoped via TenantContext. Validation runs via
 * Symfony Validator before persist. CSRF protected via `quick_create`
 * token id.
 */
#[Route('/api/quick-create', name: 'api_quick_create_')]
#[IsGranted('ROLE_USER')]
final class QuickCreateController extends AbstractController
{
    /**
     * @var array<string, array{class: class-string, factory: callable, label: callable}>
     */
    private array $registry;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly ValidatorInterface $validator,
    ) {
        // Build registry lazily so PHPStan sees the closures with proper types.
        $this->registry = [
            'asset' => [
                'class'   => Asset::class,
                'factory' => $this->buildAsset(...),
                'label'   => static fn (Asset $a): string => (string) $a->getName(),
            ],
            'crisis-team' => [
                'class'   => CrisisTeam::class,
                'factory' => $this->buildCrisisTeam(...),
                'label'   => static fn (CrisisTeam $t): string => (string) $t->getTeamName(),
            ],
            'business-process' => [
                'class'   => BusinessProcess::class,
                'factory' => $this->buildBusinessProcess(...),
                'label'   => static fn (BusinessProcess $p): string => (string) $p->getName(),
            ],
        ];
    }

    /**
     * POST /api/quick-create/{entityType}
     *
     * Body: { "name": "Foo", "_token": "..." }
     * Returns: 201 { "ok": true, "id": 42, "label": "Foo" }
     *      or 422 { "ok": false, "errors": ["..."] }
     *      or 400 { "ok": false, "error": "Unknown entity type" }
     *      or 403 (no tenant context)
     */
    #[Route('/{entityType}', name: 'create', methods: ['POST'])]
    public function create(string $entityType, Request $request): JsonResponse
    {
        if (!isset($this->registry[$entityType])) {
            return $this->json([
                'ok'    => false,
                'error' => 'Unknown entity type: ' . $entityType,
            ], 400);
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            return $this->json([
                'ok'    => false,
                'error' => 'No tenant context.',
            ], 403);
        }

        $payload = json_decode((string) $request->getContent(), true);
        if (!is_array($payload)) {
            $payload = $request->request->all();
        }

        $token = is_array($payload) ? (string) ($payload['_token'] ?? '') : '';
        if (!$this->isCsrfTokenValid('quick_create', $token)) {
            return $this->json([
                'ok'    => false,
                'error' => 'Invalid CSRF token.',
            ], 419);
        }

        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            return $this->json([
                'ok'     => false,
                'errors' => ['name is required'],
            ], 422);
        }

        $entry  = $this->registry[$entityType];
        /** @var callable $factory */
        $factory = $entry['factory'];
        $entity  = $factory($name, $payload);
        if (method_exists($entity, 'setTenant')) {
            $entity->setTenant($tenant);
        }

        $violations = $this->validator->validate($entity);
        if (count($violations) > 0) {
            $errors = [];
            foreach ($violations as $v) {
                $errors[] = $v->getPropertyPath() . ': ' . $v->getMessage();
            }
            return $this->json([
                'ok'     => false,
                'errors' => $errors,
            ], 422);
        }

        $this->em->persist($entity);
        $this->em->flush();

        /** @var callable $labelFn */
        $labelFn = $entry['label'];

        return $this->json([
            'ok'    => true,
            'id'    => $entity->getId(),
            'label' => $labelFn($entity),
        ], 201);
    }

    /**
     * Minimal Asset stub. Sets C/I/A=3 (medium) by default so DB NotNull
     * constraints are satisfied; the user can refine these later via the
     * normal Asset edit form.
     *
     * @param array<string, mixed> $payload
     */
    private function buildAsset(string $name, array $payload): Asset
    {
        $a = new Asset();
        $a->setName($name);
        $a->setAssetType((string) ($payload['assetType'] ?? 'data'));
        // Status defaults to 'active' via Asset entity property —
        // direct setStatus() would trip lifecycle.directSetStatus PHPStan rule.
        $a->setConfidentialityValue(3);
        $a->setIntegrityValue(3);
        $a->setAvailabilityValue(3);
        return $a;
    }

    /**
     * Minimal CrisisTeam stub. Defaults: teamType=operational, isActive=true.
     *
     * @param array<string, mixed> $payload
     */
    private function buildCrisisTeam(string $name, array $payload): CrisisTeam
    {
        $t = new CrisisTeam();
        $t->setTeamName($name);
        $t->setTeamType((string) ($payload['teamType'] ?? 'operational'));
        $t->setIsActive(true);
        return $t;
    }

    /**
     * Minimal BusinessProcess stub. Sets criticality=medium + RTO/RPO/MTPD
     * placeholders so NotBlank constraints pass; user refines later in the
     * BusinessProcess edit form.
     *
     * @param array<string, mixed> $payload
     */
    private function buildBusinessProcess(string $name, array $payload): BusinessProcess
    {
        $p = new BusinessProcess();
        $p->setName($name);
        $p->setCriticality((string) ($payload['criticality'] ?? 'medium'));
        $p->setProcessOwner((string) ($payload['processOwner'] ?? 'TBD'));
        $p->setRto((int) ($payload['rto'] ?? 24));
        $p->setRpo((int) ($payload['rpo'] ?? 24));
        $p->setMtpd((int) ($payload['mtpd'] ?? 48));
        $p->setReputationalImpact((int) ($payload['reputationalImpact'] ?? 3));
        $p->setRegulatoryImpact((int) ($payload['regulatoryImpact'] ?? 3));
        $p->setOperationalImpact((int) ($payload['operationalImpact'] ?? 3));
        return $p;
    }
}
