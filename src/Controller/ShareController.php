<?php

namespace App\Controller;

use App\Service\TenantContext;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Share Target Controller
 *
 * Handles content shared to the PWA via the Web Share Target API.
 * Allows users to share text, URLs, and files directly to the ISMS Helper.
 */
class ShareController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * Handle shared content from Web Share Target API
     */
    #[Route('/share', name: 'app_share_target', methods: ['GET', 'POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function handleShare(Request $request): Response
    {
        $title = $request->request->get('title') ?? $request->query->get('title', '');
        $text = $request->request->get('text') ?? $request->query->get('text', '');
        $url = $request->request->get('url') ?? $request->query->get('url', '');

        // Handle uploaded files
        $uploadedFiles = [];
        $files = $request->files->get('files');

        if ($files) {
            $files = is_array($files) ? $files : [$files];
            foreach ($files as $file) {
                if ($file instanceof UploadedFile && $file->isValid()) {
                    $uploadedFiles[] = [
                        'name' => $file->getClientOriginalName(),
                        'type' => $file->getMimeType(),
                        'size' => $file->getSize(),
                        'path' => $this->saveTemporaryFile($file),
                    ];
                }
            }
        }

        $this->logger->info('Content shared to PWA', [
            'title' => $title,
            'text' => substr($text, 0, 100),
            'url' => $url,
            'files_count' => count($uploadedFiles),
        ]);

        // Determine the best action based on content
        $shareContext = $this->analyzeSharedContent($title, $text, $url, $uploadedFiles);

        return $this->render('share/index.html.twig', [
            'title' => $title,
            'text' => $text,
            'url' => $url,
            'files' => $uploadedFiles,
            'shareContext' => $shareContext,
        ]);
    }

    /**
     * Process the shared content into a specific entity
     */
    #[Route('/share/process', name: 'app_share_process', methods: ['POST'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function processShare(Request $request): Response
    {
        $action = $request->request->get('action');
        $title = $request->request->get('title', '');
        $text = $request->request->get('text', '');
        $url = $request->request->get('url', '');
        $fileIds = $request->request->all('file_ids');

        switch ($action) {
            case 'incident':
                return $this->redirectToRoute('app_incident_new', [
                    '_locale' => $request->getLocale(),
                    'prefill_title' => $title,
                    'prefill_description' => $text . ($url ? "\n\nSource: $url" : ''),
                ]);

            case 'risk':
                return $this->redirectToRoute('app_risk_new', [
                    '_locale' => $request->getLocale(),
                    'prefill_title' => $title,
                    'prefill_description' => $text . ($url ? "\n\nSource: $url" : ''),
                ]);

            case 'document':
                return $this->redirectToRoute('app_document_new', [
                    '_locale' => $request->getLocale(),
                    'prefill_title' => $title,
                    'prefill_content' => $text,
                    'prefill_url' => $url,
                ]);

            case 'note':
                // Store as a quick note (could be implemented as a simple entity)
                $this->addFlash('success', 'Note saved successfully');
                return $this->redirectToRoute('app_dashboard', [
                    '_locale' => $request->getLocale(),
                ]);

            default:
                $this->addFlash('warning', 'Unknown action');
                return $this->redirectToRoute('app_dashboard', [
                    '_locale' => $request->getLocale(),
                ]);
        }
    }

    /**
     * Save uploaded file temporarily
     */
    private function saveTemporaryFile(UploadedFile $file): string
    {
        $tempDir = $this->projectDir . '/var/share_uploads';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $fileName = uniqid('share_') . '_' . $file->getClientOriginalName();
        $file->move($tempDir, $fileName);

        return $tempDir . '/' . $fileName;
    }

    /**
     * Analyze shared content to suggest appropriate actions
     */
    private function analyzeSharedContent(
        string $title,
        string $text,
        string $url,
        array $files
    ): array {
        $context = [
            'suggestedAction' => 'note',
            'actions' => [
                [
                    'id' => 'note',
                    'label' => 'share.action.save_note',
                    'icon' => 'bi-sticky',
                    'description' => 'share.action.note_desc',
                ],
            ],
        ];

        // Check for incident-related keywords
        $incidentKeywords = ['incident', 'breach', 'attack', 'vulnerability', 'security', 'threat', 'alert', 'warning'];
        $fullText = strtolower($title . ' ' . $text);

        foreach ($incidentKeywords as $keyword) {
            if (str_contains($fullText, $keyword)) {
                $context['suggestedAction'] = 'incident';
                array_unshift($context['actions'], [
                    'id' => 'incident',
                    'label' => 'share.action.create_incident',
                    'icon' => 'bi-exclamation-triangle',
                    'description' => 'share.action.incident_desc',
                    'highlight' => true,
                ]);
                break;
            }
        }

        // Check for risk-related keywords
        $riskKeywords = ['risk', 'threat', 'impact', 'likelihood', 'vulnerability', 'exposure'];
        foreach ($riskKeywords as $keyword) {
            if (str_contains($fullText, $keyword) && $context['suggestedAction'] !== 'incident') {
                $context['suggestedAction'] = 'risk';
                array_unshift($context['actions'], [
                    'id' => 'risk',
                    'label' => 'share.action.create_risk',
                    'icon' => 'bi-shield-exclamation',
                    'description' => 'share.action.risk_desc',
                    'highlight' => true,
                ]);
                break;
            }
        }

        // If files are shared, suggest document
        if (!empty($files)) {
            $context['actions'][] = [
                'id' => 'document',
                'label' => 'share.action.create_document',
                'icon' => 'bi-file-earmark-text',
                'description' => 'share.action.document_desc',
            ];
        }

        // If URL is shared, add document option
        if (!empty($url)) {
            $context['hasUrl'] = true;
            $context['actions'][] = [
                'id' => 'document',
                'label' => 'share.action.create_document',
                'icon' => 'bi-file-earmark-text',
                'description' => 'share.action.document_desc',
            ];
        }

        // Remove duplicates from actions
        $seen = [];
        $context['actions'] = array_filter($context['actions'], function ($action) use (&$seen) {
            if (in_array($action['id'], $seen)) {
                return false;
            }
            $seen[] = $action['id'];
            return true;
        });

        return $context;
    }
}
