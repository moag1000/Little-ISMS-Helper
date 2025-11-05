<?php

namespace App\Controller;

use App\Entity\Document;
use App\Form\DocumentType;
use App\Repository\DocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

#[Route('/document')]
#[IsGranted('ROLE_USER')]
class DocumentController extends AbstractController
{
    public function __construct(
        private DocumentRepository $documentRepository,
        private EntityManagerInterface $entityManager,
        private string $projectDir
    ) {}

    #[Route('/', name: 'app_document_index')]
    public function index(): Response
    {
        $documents = $this->documentRepository->findBy(
            ['status' => 'active'],
            ['uploadedAt' => 'DESC']
        );
        $stats = $this->documentRepository->getStatistics();

        return $this->render('document/index.html.twig', [
            'documents' => $documents,
            'stats' => $stats,
        ]);
    }

    #[Route('/new', name: 'app_document_new')]
    public function new(Request $request): Response
    {
        $document = new Document();
        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $document->setUploadedBy($this->getUser());
            $this->entityManager->persist($document);
            $this->entityManager->flush();

            $this->addFlash('success', 'Document uploaded successfully.');
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        return $this->render('document/new.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_document_show', requirements: ['id' => '\d+'])]
    public function show(Document $document): Response
    {
        return $this->render('document/show.html.twig', [
            'document' => $document,
        ]);
    }

    #[Route('/{id}/download', name: 'app_document_download', requirements: ['id' => '\d+'])]
    public function download(Document $document): Response
    {
        $filePath = $this->projectDir . '/public/uploads/documents/' . $document->getFileName();

        if (!file_exists($filePath)) {
            throw $this->createNotFoundException('File not found');
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $document->getOriginalName()
        );

        return $response;
    }

    #[Route('/{id}/edit', name: 'app_document_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Document $document): Response
    {
        $form = $this->createForm(DocumentType::class, $document);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Document updated successfully.');
            return $this->redirectToRoute('app_document_show', ['id' => $document->getId()]);
        }

        return $this->render('document/edit.html.twig', [
            'document' => $document,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_document_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Document $document): Response
    {
        if ($this->isCsrfTokenValid('delete'.$document->getId(), $request->request->get('_token'))) {
            // Mark as deleted instead of actually removing
            $document->setStatus('deleted');
            $this->entityManager->flush();

            $this->addFlash('success', 'Document deleted successfully.');
        }

        return $this->redirectToRoute('app_document_index');
    }

    #[Route('/type/{type}', name: 'app_document_by_type')]
    public function byType(string $type): Response
    {
        $documents = $this->documentRepository->findByType($type);

        return $this->render('document/by_type.html.twig', [
            'documents' => $documents,
            'type' => $type,
        ]);
    }
}
