<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\Comment;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\DataSubjectRequest;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\SafeRedirectTarget;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Generic Comment Controller.
 *
 * Persists comments attached to any ISMS entity via (entity_type, entity_id).
 * Whitelist-driven entity-type validation prevents arbitrary polymorphic abuse.
 *
 * Verifies entity existence + tenant scope before persist. Without this, a Tenant-A user could persist a Comment row that
 * carries Tenant-A's tenant_id but `entity_id` pointing at a Tenant-B resource —
 * breaking ISO 27001 A.5.10 / A.8.2 information access control.
 */
class CommentController extends AbstractController
{
    /**
     * Whitelist mapping entity-type short name → fully-qualified class.
     * Keep in sync with the show-templates that mount the comment thread macro.
     */
    private const ENTITY_TYPE_MAP = [
        'Risk' => Risk::class,
        'AuditFinding' => AuditFinding::class,
        'Document' => Document::class,
        'Incident' => Incident::class,
        'Asset' => Asset::class,
        'Control' => Control::class,
        'CorrectiveAction' => CorrectiveAction::class,
        'DataSubjectRequest' => DataSubjectRequest::class,
        // Comment-Thread adoption widened to GDPR-show-pages.
        'DataBreach' => DataBreach::class,
        'DataProtectionImpactAssessment' => DataProtectionImpactAssessment::class,
    ];

    /**
     * S11: who may read a record's detail page may comment on it — and nobody else.
     * Per type the module its show controller requires (#[RequiresModule]) and the
     * voter attribute its show action checks; tests/Security/CommentTargetAccessTest
     * keeps this in step with the show controllers.
     *
     * @var array<string, array{module: ?string, attribute: ?string}>
     */
    public const array TARGET_ACCESS = [
        'Risk' => ['module' => null, 'attribute' => 'view'],
        'AuditFinding' => ['module' => null, 'attribute' => null],
        'Document' => ['module' => null, 'attribute' => 'view'],
        'Incident' => ['module' => null, 'attribute' => 'view'],
        'Asset' => ['module' => null, 'attribute' => 'view'],
        'Control' => ['module' => null, 'attribute' => 'view'],
        'CorrectiveAction' => ['module' => 'corrective_actions', 'attribute' => null],
        'DataSubjectRequest' => ['module' => 'privacy', 'attribute' => null],
        'DataBreach' => ['module' => 'privacy', 'attribute' => null],
        'DataProtectionImpactAssessment' => ['module' => 'privacy', 'attribute' => null],
    ];

    /** @return array<string, class-string> */
    public static function entityTypes(): array
    {
        return self::ENTITY_TYPE_MAP;
    }

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly ModuleConfigurationService $moduleConfiguration,
    ) {
    }

    #[Route('/comment/{entityType}/{entityId}', name: 'app_comment_post', methods: ['POST'], requirements: ['entityId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('isms-comment')]
    public function post(string $entityType, int $entityId, Request $request): Response
    {
        if (!array_key_exists($entityType, self::ENTITY_TYPE_MAP)) {
            throw $this->createNotFoundException('Unknown entity type');
        }

        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createAccessDeniedException('No tenant context');
        }

        // V4 LB-9: verify the target entity exists AND is reachable from the
        // current tenant. The Doctrine TenantFilter attaches a tenant_id
        // constraint to the SELECT, so a cross-tenant lookup yields null.
        // We additionally do an explicit getTenant() comparison because the
        // filter is disabled for super-admin (no-tenant) sessions, where we
        // still must not let a privileged user post into a tenant they did
        // not actively switch to.
        $entityClass = self::ENTITY_TYPE_MAP[$entityType];
        $target = $this->em->getRepository($entityClass)->find($entityId);

        if ($target === null) {
            $this->auditCommentBlocked($entityType, $entityId, 'entity_not_found_or_cross_tenant', $user);
            throw $this->createNotFoundException('Entity not found');
        }

        // Defense-in-depth: explicit tenant comparison. The TenantFilter is
        // expected to have caught cross-tenant access already, but we re-check
        // here so a misconfigured filter can't silently widen the boundary.
        $targetTenant = $target->getTenant();
        if (!$targetTenant instanceof Tenant
            || !$this->tenantContext->canAccessTenant($targetTenant)
        ) {
            $this->auditCommentBlocked($entityType, $entityId, 'cross_tenant_post_blocked', $user);
            throw new AccessDeniedHttpException('Cross-tenant comment post denied');
        }

        // Same gate as the record's detail page: its module and its voter.
        $access = self::TARGET_ACCESS[$entityType];
        if (($access['module'] !== null && !$this->moduleConfiguration->isModuleActive($access['module']))
            || ($access['attribute'] !== null && !$this->isGranted($access['attribute'], $target))
        ) {
            $this->auditCommentBlocked($entityType, $entityId, 'no_access_to_record', $user);
            throw new AccessDeniedHttpException('No access to the commented record');
        }

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '' || mb_strlen($body) > Comment::MAX_LENGTH) {
            $this->addFlash('error', $body === '' ? 'comments.flash.empty' : 'comments.flash.too_long');
            return $this->backToRecord($request);
        }

        $comment = new Comment();
        $comment->setTenant($tenant);
        $comment->setEntityType($entityType);
        $comment->setEntityId($entityId);
        $comment->setAuthor($user);
        $comment->setBody($body);

        $this->em->persist($comment);
        $this->em->flush();

        $this->addFlash('success', 'comments.flash.saved');
        return $this->backToRecord($request);
    }

    /** Back to the page the form sat on — only a same-origin target (SafeRedirectTarget), else the dashboard. */
    private function backToRecord(Request $request): Response
    {
        $referer = (string) $request->headers->get('referer', '');

        return SafeRedirectTarget::isSafe($referer, $request)
            ? $this->redirect($referer)
            : $this->redirectToRoute('app_dashboard');
    }

    /**
     * Compliance-trail entry for blocked / suspicious comment-post attempts.
     * ISO 27001 A.8.15 (logging) + A.5.10 (information access control).
     */
    private function auditCommentBlocked(string $entityType, int $entityId, string $reason, User $user): void
    {
        $this->auditLogger->logCustom(
            action: 'comment_post_blocked',
            entityType: $entityType,
            entityId: $entityId,
            newValues: [
                'reason' => $reason,
                'attempted_by_user_id' => $user->getId(),
                'attempted_by_email' => $user->getEmail(),
            ],
            description: sprintf(
                'Comment post on %s#%d blocked: %s',
                $entityType,
                $entityId,
                $reason,
            ),
        );
    }
}
