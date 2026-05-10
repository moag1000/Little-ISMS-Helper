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
use App\Service\AuditLogger;
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
 * Audit V3 C7 — Generic Comment Controller.
 *
 * Persists comments attached to any ISMS entity via (entity_type, entity_id).
 * Whitelist-driven entity-type validation prevents arbitrary polymorphic abuse.
 *
 * Audit V4 LB-9 (2026-05-10): adds entity-existence + tenant-scope verification
 * before persist. Without this, a Tenant-A user could persist a Comment row that
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
        // V3 W3-Aurora: Comment-Thread adoption widened to GDPR-show-pages.
        'DataBreach' => DataBreach::class,
        'DataProtectionImpactAssessment' => DataProtectionImpactAssessment::class,
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
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
        $targetTenant = method_exists($target, 'getTenant') ? $target->getTenant() : null;
        if (!$targetTenant instanceof Tenant
            || !$this->tenantContext->canAccessTenant($targetTenant)
        ) {
            $this->auditCommentBlocked($entityType, $entityId, 'cross_tenant_post_blocked', $user);
            throw new AccessDeniedHttpException('Cross-tenant comment post denied');
        }

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '') {
            $this->addFlash('error', 'Kommentar darf nicht leer sein.');
            return $this->redirect($request->headers->get('referer') ?? '/');
        }

        $comment = new Comment();
        $comment->setTenant($tenant);
        $comment->setEntityType($entityType);
        $comment->setEntityId($entityId);
        $comment->setAuthor($user);
        $comment->setBody($body);

        $this->em->persist($comment);
        $this->em->flush();

        $this->addFlash('success', 'Kommentar gespeichert.');
        return $this->redirect($request->headers->get('referer') ?? '/');
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
