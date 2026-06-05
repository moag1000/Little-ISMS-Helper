<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Document;
use App\Service\Export\DocxExportService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F20 — DOCX + Markdown export for ISMS Documents.
 *
 * Split out of the (already large) DocumentController so the export endpoints
 * live in a focused, single-responsibility controller. Both routes are gated by
 * the `download` voter, mirroring the PDF/file download.
 */
#[IsGranted('ROLE_USER')]
final class DocumentExportController extends AbstractController
{
    public function __construct(
        private readonly DocxExportService $docxExporter,
    ) {
    }

    #[Route('/document/{id}/export.docx', name: 'app_document_export_docx', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportDocx(Document $document): Response
    {
        $this->denyAccessUnlessGranted('download', $document);

        return new Response($this->docxExporter->exportDocument($document), Response::HTTP_OK, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => sprintf('attachment; filename="%s.docx"', $this->slug($document)),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    #[Route('/document/{id}/export.md', name: 'app_document_export_markdown', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function exportMarkdown(Document $document): Response
    {
        $this->denyAccessUnlessGranted('download', $document);

        return new Response($this->docxExporter->exportMarkdown($document), Response::HTTP_OK, [
            'Content-Type'        => 'text/markdown; charset=UTF-8',
            'Content-Disposition' => sprintf('attachment; filename="%s.md"', $this->slug($document)),
            'Cache-Control'       => 'no-cache, no-store, must-revalidate',
        ]);
    }

    private function slug(Document $document): string
    {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', (string) ($document->getFilename() ?? 'document'));

        return trim((string) $slug, '-') ?: 'document';
    }
}
