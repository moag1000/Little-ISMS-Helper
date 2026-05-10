<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Comment;
use App\Entity\User;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;

/**
 * Audit V3 C7 — Generic Comment Controller.
 *
 * Persists comments attached to any ISMS entity via (entity_type, entity_id).
 * Whitelist-driven entity-type validation prevents arbitrary polymorphic abuse.
 */
class CommentController extends AbstractController
{
    private const ALLOWED_ENTITY_TYPES = [
        'Risk',
        'AuditFinding',
        'Document',
        'Incident',
        'Asset',
        'Control',
        'CorrectiveAction',
        'DataSubjectRequest',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/comment/{entityType}/{entityId}', name: 'app_comment_post', methods: ['POST'], requirements: ['entityId' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    #[IsCsrfTokenValid('isms-comment')]
    public function post(string $entityType, int $entityId, Request $request): Response
    {
        if (!in_array($entityType, self::ALLOWED_ENTITY_TYPES, true)) {
            throw $this->createNotFoundException('Unknown entity type');
        }

        /** @var User $user */
        $user = $this->getUser();
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant) {
            throw $this->createAccessDeniedException('No tenant context');
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
}
