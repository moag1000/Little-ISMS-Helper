<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ImportRowEvent;
use App\Entity\ImportSession;
use App\Entity\User;
use App\Form\Admin\ComplianceImportUploadType;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceMappingRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ImportSessionRepository;
use App\Service\CompliancePolicyService;
use App\Service\Import\BsiProfileXmlImporter;
use App\Service\Import\ImportSessionRecorder;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * 3-step Import-Wizard for compliance mappings CSV files (WS-2 Frontend).
 *
 * The wizard reuses the parse logic of App\Command\ImportMappingCsvCommand
 * to ensure CLI and UI behave identically. Uploaded CSVs are stored in
 * var/uploads/compliance-import/ and tracked via a session-scoped
 * preview record. Only a preview (no DB writes) is produced before the
 * explicit commit step.
 */
#[IsGranted('ROLE_ADMIN')]
#[Route(
    path: '/admin/import/compliance',
    name: 'admin_compliance_import_'
)]
final class ComplianceImportController extends AbstractController
{
    private const REQUIRED_HEADERS = [
        'source_framework',
        'source_requirement_id',
        'target_framework',
        'target_requirement_id',
        'mapping_percentage',
        'mapping_type',
        'confidence',
        'bidirectional',
        'rationale',
        'source_catalog',
        'validated_at',
        'validated_by',
    ];

    private const SESSION_KEY = 'compliance_import.preview';
    private const FORMAT_CSV = 'csv_generic_v1';
    private const FORMAT_BSI_XML = 'bsi_profile_xml_v1';
    private const SUPPORTED_FORMATS = [self::FORMAT_CSV, self::FORMAT_BSI_XML];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceMappingRepository $mappingRepository,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        private readonly CompliancePolicyService $policy,
        private readonly BsiProfileXmlImporter $bsiProfileXmlImporter,
        private readonly ImportSessionRecorder $importSessionRecorder,
        private readonly ImportSessionRepository $importSessionRepository,
        private readonly TenantContext $tenantContext,
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route('/template', name: 'template', methods: ['GET'])]
    public function template(): Response
    {
        $path = $this->projectDir . '/fixtures/mappings/_templates/import_template_v1.csv';
        if (!is_file($path) || !is_readable($path)) {
            throw $this->createNotFoundException('Import template not found.');
        }

        $response = new Response((string) file_get_contents($path));
        $response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
        $response->headers->set(
            'Content-Disposition',
            'attachment; filename="import_template_v1.csv"'
        );

        return $response;
    }

    #[Route('/upload', name: 'upload', methods: ['GET', 'POST'])]
    public function upload(Request $request): Response
    {
        $maxSizeMb = $this->policy->getInt(CompliancePolicyService::KEY_IMPORT_MAX_UPLOAD_MB, 5);
        $form = $this->createForm(ComplianceImportUploadType::class, null, [
            'max_size_mb' => $maxSizeMb,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            /** @var UploadedFile|null $file */
            $file = $form->get('file')->getData();
            $format = (string) $form->get('format')->getData();

            if (!$file instanceof UploadedFile) {
                $this->addFlash('error', $this->translator->trans(
                    'compliance_import.flash.no_file',
                    [],
                    'compliance_import'
                ));

                return $this->redirectToRoute('admin_compliance_import_upload');
            }

            if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
                $this->addFlash('error', $this->translator->trans(
                    'compliance_import.flash.unsupported_format',
                    [],
                    'compliance_import'
                ));

                return $this->redirectToRoute('admin_compliance_import_upload');
            }

            $uploadDir = $this->projectDir . '/var/uploads/compliance-import';
            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $this->addFlash('error', $this->translator->trans(
                    'compliance_import.flash.upload_dir_failed',
                    [],
                    'compliance_import'
                ));

                return $this->redirectToRoute('admin_compliance_import_upload');
            }

            $sessionId = bin2hex(random_bytes(12));
            $extension = $format === self::FORMAT_BSI_XML ? 'xml' : 'csv';
            $storedName = $sessionId . '.' . $extension;

            try {
                $file->move($uploadDir, $storedName);
            } catch (FileException $exception) {
                $this->logger->error('Compliance-Import upload failed', [
                    'error' => $exception->getMessage(),
                ]);
                $this->addFlash('error', $this->translator->trans(
                    'compliance_import.flash.upload_failed',
                    [],
                    'compliance_import'
                ));

                return $this->redirectToRoute('admin_compliance_import_upload');
            }

            // ISB MINOR-1: create the persistent ImportSession header so the
            // full audit trail (file hash + per-row events) is queryable
            // even after the Symfony session expires.
            $tenant = $this->tenantContext->getCurrentTenant();
            $importSessionId = null;
            if ($tenant !== null) {
                /** @var User|null $user */
                $user = $this->getUser();
                try {
                    $importSession = $this->importSessionRecorder->openSession(
                        $uploadDir . '/' . $storedName,
                        $format,
                        $file->getClientOriginalName(),
                        $user instanceof User ? $user : null,
                        $tenant,
                    );
                    $importSessionId = $importSession->getId();
                } catch (\Throwable $exception) {
                    $this->logger->error('Compliance-Import ImportSession open failed', [
                        'error' => $exception->getMessage(),
                    ]);
                }
            }

            $request->getSession()->set(self::SESSION_KEY, [
                'id' => $sessionId,
                'format' => $format,
                'original_name' => $file->getClientOriginalName(),
                'stored_path' => $uploadDir . '/' . $storedName,
                'created_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'import_session_id' => $importSessionId,
            ]);

            return $this->redirectToRoute('admin_compliance_import_preview');
        }

        return $this->render('admin/compliance_import/upload.html.twig', [
            'form' => $form->createView(),
            'active_step' => 1,
            'template_url' => $this->generateUrl('admin_compliance_import_template'),
        ]);
    }

    #[Route('/preview', name: 'preview', methods: ['GET'])]
    public function preview(Request $request): Response
    {
        $session = $request->getSession()->get(self::SESSION_KEY);
        if (!is_array($session) || !is_file($session['stored_path'] ?? '')) {
            $this->addFlash('warning', $this->translator->trans(
                'compliance_import.flash.no_session',
                [],
                'compliance_import'
            ));

            return $this->redirectToRoute('admin_compliance_import_upload');
        }

        $format = (string) ($session['format'] ?? self::FORMAT_CSV);
        $analysis = $this->dispatchAnalyse($format, (string) $session['stored_path']);
        $fourEyesThreshold = $this->policy->getInt(CompliancePolicyService::KEY_IMPORT_FOUR_EYES_ROW_THRESHOLD, 50);

        return $this->render('admin/compliance_import/preview.html.twig', [
            'active_step' => 2,
            'session_info' => $session,
            'rows' => $analysis['rows'],
            'summary' => $analysis['summary'],
            'header_error' => $analysis['header_error'],
            'four_eyes_threshold' => $fourEyesThreshold,
            'requires_four_eyes' => count($analysis['rows']) >= $fourEyesThreshold,
        ]);
    }

    #[Route('/commit', name: 'commit', methods: ['POST'])]
    public function commit(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('compliance_import_commit', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans(
                'compliance_import.flash.csrf_invalid',
                [],
                'compliance_import'
            ));

            return $this->redirectToRoute('admin_compliance_import_preview');
        }

        $session = $request->getSession()->get(self::SESSION_KEY);
        if (!is_array($session) || !is_file($session['stored_path'] ?? '')) {
            $this->addFlash('warning', $this->translator->trans(
                'compliance_import.flash.no_session',
                [],
                'compliance_import'
            ));

            return $this->redirectToRoute('admin_compliance_import_upload');
        }

        $format = (string) ($session['format'] ?? self::FORMAT_CSV);

        $importSession = null;
        if (isset($session['import_session_id']) && is_int($session['import_session_id'])) {
            $importSession = $this->importSessionRepository->find($session['import_session_id']);
        }

        $result = $this->dispatchImport($format, (string) $session['stored_path'], $importSession);

        // Finalise the per-row audit trail (counts + committedAt timestamp).
        if ($importSession instanceof ImportSession) {
            try {
                $this->importSessionRecorder->closeSession($importSession, ImportSession::STATUS_COMMITTED);
            } catch (\Throwable $exception) {
                $this->logger->error('Compliance-Import ImportSession close failed', [
                    'error' => $exception->getMessage(),
                    'session_id' => $importSession->getId(),
                ]);
            }
        }

        // Remove uploaded file + session record after commit.
        @unlink((string) $session['stored_path']);
        $request->getSession()->remove(self::SESSION_KEY);

        $this->addFlash(
            $result['errors'] === [] ? 'success' : 'warning',
            $this->translator->trans(
                'compliance_import.flash.commit_summary',
                [
                    '%imported%' => $result['imported'],
                    '%superseded%' => $result['superseded'],
                    '%skipped%' => $result['skipped'],
                ],
                'compliance_import'
            )
        );

        return $this->render('admin/compliance_import/result.html.twig', [
            'active_step' => 3,
            'imported' => $result['imported'],
            'superseded' => $result['superseded'],
            'skipped' => $result['skipped'],
            'errors' => $result['errors'],
        ]);
    }

    #[Route('/cancel', name: 'cancel', methods: ['POST'])]
    public function cancel(Request $request): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('compliance_import_cancel', (string) $request->request->get('_token'))) {
            $this->addFlash('error', $this->translator->trans(
                'compliance_import.flash.csrf_invalid',
                [],
                'compliance_import'
            ));

            return $this->redirectToRoute('admin_compliance_import_preview');
        }

        $session = $request->getSession()->get(self::SESSION_KEY);
        if (is_array($session)) {
            if (is_file($session['stored_path'] ?? '')) {
                @unlink((string) $session['stored_path']);
            }
            if (isset($session['import_session_id']) && is_int($session['import_session_id'])) {
                $importSession = $this->importSessionRepository->find($session['import_session_id']);
                if ($importSession instanceof ImportSession) {
                    try {
                        $this->importSessionRecorder->closeSession(
                            $importSession,
                            ImportSession::STATUS_CANCELLED,
                        );
                    } catch (\Throwable $exception) {
                        $this->logger->error('Compliance-Import ImportSession cancel failed', [
                            'error' => $exception->getMessage(),
                            'session_id' => $importSession->getId(),
                        ]);
                    }
                }
            }
        }
        $request->getSession()->remove(self::SESSION_KEY);

        $this->addFlash('info', $this->translator->trans(
            'compliance_import.flash.cancelled',
            [],
            'compliance_import'
        ));

        return $this->redirectToRoute('admin_compliance_import_upload');
    }

    /**
     * Dispatch the analyse step to the correct importer based on session format.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, header_error: ?string}
     */
    private function dispatchAnalyse(string $format, string $path): array
    {
        return match ($format) {
            self::FORMAT_BSI_XML => $this->bsiProfileXmlImporter->analyse($path),
            default => $this->analyseFile($path),
        };
    }

    /**
     * Dispatch the commit step to the correct importer based on session format.
     *
     * @return array{imported: int, superseded: int, skipped: int, errors: list<string>}
     */
    private function dispatchImport(string $format, string $path, ?ImportSession $importSession): array
    {
        return match ($format) {
            self::FORMAT_BSI_XML => $this->bsiProfileXmlImporter->import(
                $path,
                $importSession !== null ? $this->importSessionRecorder : null,
                $importSession,
            ),
            default => $this->importFile($path, $importSession),
        };
    }

    /**
     * Parse CSV without writing to DB. Returns per-row status (new/update/conflict/error)
     * plus aggregated counters.
     *
     * @return array{rows: list<array<string, mixed>>, summary: array<string, int>, header_error: ?string}
     */
    private function analyseFile(string $path): array
    {
        $rows = [];
        $summary = ['new' => 0, 'update' => 0, 'conflict' => 0, 'error' => 0];
        $headerError = null;

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [
                'rows' => [],
                'summary' => $summary,
                'header_error' => 'compliance_import.preview.file_unreadable',
            ];
        }

        $headers = null;
        $lineNo = 0;
        $frameworkCache = [];
        $requirementCache = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($row === [null] || $row === [] || ($row[0] ?? '') === '') {
                continue;
            }
            if (str_starts_with((string) $row[0], '#')) {
                continue;
            }
            if ($headers === null) {
                $headers = $row;
                foreach (self::REQUIRED_HEADERS as $required) {
                    if (!in_array($required, $headers, true)) {
                        $headerError = 'compliance_import.preview.missing_header:' . $required;
                        fclose($handle);

                        return [
                            'rows' => [],
                            'summary' => $summary,
                            'header_error' => $headerError,
                        ];
                    }
                }
                continue;
            }

            if (count($row) !== count($headers)) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => 'compliance_import.preview.col_count_mismatch',
                    'source_framework' => (string) ($row[0] ?? ''),
                    'source_requirement_id' => (string) ($row[1] ?? ''),
                    'target_framework' => (string) ($row[2] ?? ''),
                    'target_requirement_id' => (string) ($row[3] ?? ''),
                    'percentage' => '',
                ];
                $summary['error']++;
                continue;
            }

            $data = array_combine($headers, $row);

            $sourceFramework = $this->getFramework((string) $data['source_framework'], $frameworkCache);
            $targetFramework = $this->getFramework((string) $data['target_framework'], $frameworkCache);

            if ($sourceFramework === null || $targetFramework === null) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => 'compliance_import.preview.framework_missing',
                    'source_framework' => (string) $data['source_framework'],
                    'source_requirement_id' => (string) $data['source_requirement_id'],
                    'target_framework' => (string) $data['target_framework'],
                    'target_requirement_id' => (string) $data['target_requirement_id'],
                    'percentage' => (string) $data['mapping_percentage'],
                ];
                $summary['error']++;
                continue;
            }

            $sourceReq = $this->getRequirement($sourceFramework, (string) $data['source_requirement_id'], $requirementCache);
            $targetReq = $this->getRequirement($targetFramework, (string) $data['target_requirement_id'], $requirementCache);

            if ($sourceReq === null || $targetReq === null) {
                $rows[] = [
                    'line' => $lineNo,
                    'status' => 'error',
                    'message' => 'compliance_import.preview.requirement_missing',
                    'source_framework' => (string) $data['source_framework'],
                    'source_requirement_id' => (string) $data['source_requirement_id'],
                    'target_framework' => (string) $data['target_framework'],
                    'target_requirement_id' => (string) $data['target_requirement_id'],
                    'percentage' => (string) $data['mapping_percentage'],
                ];
                $summary['error']++;
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => $data['source_catalog'],
            ]);

            $status = $existing instanceof ComplianceMapping ? 'update' : 'new';

            // Treat percentage deltas larger than 25pp between existing and incoming row
            // as "conflict" for a visible heads-up without blocking the commit.
            if ($status === 'update'
                && $existing instanceof ComplianceMapping
                && abs($existing->getMappingPercentage() - (int) $data['mapping_percentage']) > 25
            ) {
                $status = 'conflict';
            }

            $rows[] = [
                'line' => $lineNo,
                'status' => $status,
                'message' => null,
                'source_framework' => (string) $data['source_framework'],
                'source_requirement_id' => (string) $data['source_requirement_id'],
                'target_framework' => (string) $data['target_framework'],
                'target_requirement_id' => (string) $data['target_requirement_id'],
                'percentage' => (string) $data['mapping_percentage'],
            ];
            $summary[$status]++;
        }
        fclose($handle);

        return [
            'rows' => $rows,
            'summary' => $summary,
            'header_error' => $headerError,
        ];
    }

    /**
     * Execute the real import. Mirrors ImportMappingCsvCommand::execute().
     *
     * ISB MINOR-1: when $importSession is supplied, one ImportRowEvent is
     * emitted per CSV row, so auditors can retrieve the full provenance of
     * a mapping via ImportRowEventRepository::findByTarget().
     *
     * @return array{imported: int, superseded: int, skipped: int, errors: list<string>}
     */
    private function importFile(string $path, ?ImportSession $importSession): array
    {
        $imported = 0;
        $superseded = 0;
        $skipped = 0;
        $errors = [];

        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return [
                'imported' => 0,
                'superseded' => 0,
                'skipped' => 0,
                'errors' => ['File not readable.'],
            ];
        }

        $headers = null;
        $lineNo = 0;
        $frameworkCache = [];
        $requirementCache = [];

        while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
            $lineNo++;
            if ($row === [null] || $row === [] || ($row[0] ?? '') === '') {
                continue;
            }
            if (str_starts_with((string) $row[0], '#')) {
                continue;
            }
            if ($headers === null) {
                $headers = $row;
                foreach (self::REQUIRED_HEADERS as $required) {
                    if (!in_array($required, $headers, true)) {
                        fclose($handle);

                        return [
                            'imported' => 0,
                            'superseded' => 0,
                            'skipped' => 0,
                            'errors' => [sprintf('Missing required header: %s', $required)],
                        ];
                    }
                }
                continue;
            }

            $rawRow = $this->buildRawRow($headers, $row);

            if (count($row) !== count($headers)) {
                $errMsg = sprintf('Line %d: column count mismatch', $lineNo);
                $errors[] = $errMsg;
                $skipped++;
                $this->recordRowEventIfEnabled(
                    $importSession, $lineNo, ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $rawRow, $errMsg,
                );
                continue;
            }

            $data = array_combine($headers, $row);

            $sourceFramework = $this->getFramework((string) $data['source_framework'], $frameworkCache);
            $targetFramework = $this->getFramework((string) $data['target_framework'], $frameworkCache);
            if ($sourceFramework === null || $targetFramework === null) {
                $errMsg = sprintf(
                    'Line %d: framework not found (%s -> %s)',
                    $lineNo,
                    $data['source_framework'],
                    $data['target_framework']
                );
                $errors[] = $errMsg;
                $skipped++;
                $this->recordRowEventIfEnabled(
                    $importSession, $lineNo, ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $data, $errMsg,
                );
                continue;
            }

            $sourceReq = $this->getRequirement($sourceFramework, (string) $data['source_requirement_id'], $requirementCache);
            $targetReq = $this->getRequirement($targetFramework, (string) $data['target_requirement_id'], $requirementCache);
            if ($sourceReq === null || $targetReq === null) {
                $missing = [];
                if ($sourceReq === null) {
                    $missing[] = sprintf('source=%s:%s', $sourceFramework->getCode(), $data['source_requirement_id']);
                }
                if ($targetReq === null) {
                    $missing[] = sprintf('target=%s:%s', $targetFramework->getCode(), $data['target_requirement_id']);
                }
                $errMsg = sprintf('Line %d: %s', $lineNo, implode(', ', $missing));
                $errors[] = $errMsg;
                $skipped++;
                $this->recordRowEventIfEnabled(
                    $importSession, $lineNo, ImportRowEvent::DECISION_ERROR,
                    null, null, null, null, $data, $errMsg,
                );
                continue;
            }

            $existing = $this->mappingRepository->findOneBy([
                'sourceRequirement' => $sourceReq,
                'targetRequirement' => $targetReq,
                'source' => $data['source_catalog'],
            ]);

            $beforeState = null;
            if ($existing instanceof ComplianceMapping) {
                $beforeState = [
                    'mapping_percentage' => $existing->getMappingPercentage(),
                    'mapping_type' => $existing->getMappingType(),
                    'confidence' => $existing->getConfidence(),
                    'rationale' => $existing->getMappingRationale(),
                    'version' => $existing->getVersion(),
                    'valid_from' => $existing->getValidFrom()?->format(DATE_ATOM),
                ];
                $existing->setValidUntil(new DateTimeImmutable());
                $superseded++;
            }

            $mapping = (new ComplianceMapping())
                ->setSourceRequirement($sourceReq)
                ->setTargetRequirement($targetReq)
                ->setMappingPercentage((int) $data['mapping_percentage'])
                ->setMappingType((string) $data['mapping_type'])
                ->setConfidence((string) $data['confidence'])
                ->setBidirectional(strtolower((string) $data['bidirectional']) === 'true')
                ->setMappingRationale((string) $data['rationale'])
                ->setSource((string) $data['source_catalog'])
                ->setVersion(($existing?->getVersion() ?? 0) + 1)
                ->setValidFrom(new DateTimeImmutable());

            $this->entityManager->persist($mapping);
            $imported++;

            // Flush so the new mapping has an id before we reference it on
            // the audit row event.
            $this->entityManager->flush();

            $afterState = [
                'mapping_percentage' => $mapping->getMappingPercentage(),
                'mapping_type' => $mapping->getMappingType(),
                'confidence' => $mapping->getConfidence(),
                'rationale' => $mapping->getMappingRationale(),
                'version' => $mapping->getVersion(),
                'valid_from' => $mapping->getValidFrom()?->format(DATE_ATOM),
            ];

            $this->recordRowEventIfEnabled(
                $importSession,
                $lineNo,
                $existing instanceof ComplianceMapping
                    ? ImportRowEvent::DECISION_UPDATE
                    : ImportRowEvent::DECISION_IMPORT,
                'ComplianceMapping',
                $mapping->getId(),
                $beforeState,
                $afterState,
                $data,
                null,
            );
        }
        fclose($handle);

        $this->entityManager->flush();

        return [
            'imported' => $imported,
            'superseded' => $superseded,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param list<string>      $headers
     * @param list<string|null> $row
     *
     * @return array<string, mixed>
     */
    private function buildRawRow(array $headers, array $row): array
    {
        $raw = [];
        foreach ($headers as $idx => $key) {
            $raw[$key] = $row[$idx] ?? null;
        }

        return $raw;
    }

    /**
     * @param array<string, mixed>|null $beforeState
     * @param array<string, mixed>|null $afterState
     * @param array<string, mixed>|null $rawRow
     */
    private function recordRowEventIfEnabled(
        ?ImportSession $importSession,
        int $lineNumber,
        string $decision,
        ?string $targetEntityType,
        ?int $targetEntityId,
        ?array $beforeState,
        ?array $afterState,
        ?array $rawRow,
        ?string $errorMessage,
    ): void {
        if ($importSession === null) {
            return;
        }

        $this->importSessionRecorder->recordRow(
            $importSession,
            $lineNumber,
            $decision,
            $targetEntityType,
            $targetEntityId,
            $beforeState,
            $afterState,
            $rawRow,
            $errorMessage,
        );
    }

    /**
     * @param array<string, ComplianceFramework|null> $cache
     */
    private function getFramework(string $code, array &$cache): ?ComplianceFramework
    {
        if (!array_key_exists($code, $cache)) {
            $cache[$code] = $this->frameworkRepository->findOneBy(['code' => $code]);
        }

        return $cache[$code];
    }

    /**
     * @param array<string, ComplianceRequirement|null> $cache
     */
    private function getRequirement(
        ComplianceFramework $framework,
        string $requirementId,
        array &$cache,
    ): ?ComplianceRequirement {
        $key = $framework->getCode() . '::' . $requirementId;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        foreach ($this->candidateIds($framework, $requirementId) as $candidate) {
            $hit = $this->requirementRepository->findOneBy([
                'complianceFramework' => $framework,
                'requirementId' => $candidate,
            ]);
            if ($hit instanceof ComplianceRequirement) {
                $cache[$key] = $hit;

                return $hit;
            }
        }

        foreach ($this->prefixCandidates($framework, $requirementId) as $prefix) {
            $qb = $this->requirementRepository->createQueryBuilder('r')
                ->andWhere('r.framework = :f')
                ->andWhere('r.requirementId LIKE :p')
                ->setParameter('f', $framework)
                ->setParameter('p', $prefix . '%')
                ->orderBy('r.requirementId', 'ASC')
                ->setMaxResults(1);
            $hit = $qb->getQuery()->getOneOrNullResult();
            if ($hit instanceof ComplianceRequirement) {
                $cache[$key] = $hit;

                return $hit;
            }
        }

        $cache[$key] = null;

        return null;
    }

    /**
     * @return list<string>
     */
    private function prefixCandidates(ComplianceFramework $framework, string $id): array
    {
        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        $candidates = [$stripped . '.', $strippedAnnex . '.'];
        foreach ($this->prefixesFor($framework->getCode()) as $prefix) {
            foreach ([$stripped, $strippedAnnex] as $core) {
                $candidates[] = $prefix . '-' . $core . '.';
                $candidates[] = $prefix . '-' . $core;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return list<string>
     */
    private function candidateIds(ComplianceFramework $framework, string $id): array
    {
        $code = $framework->getCode();
        $candidates = [$id];

        $stripped = preg_replace('/^(Art\.|§)/i', '', $id) ?? $id;
        $strippedAnnex = preg_replace('/^A\./i', '', $stripped) ?? $stripped;
        foreach ([$stripped, $strippedAnnex] as $variant) {
            if ($variant !== $id && $variant !== null) {
                $candidates[] = $variant;
            }
        }

        foreach ([$id, $stripped, $strippedAnnex] as $core) {
            if ($core === null || $core === '') {
                continue;
            }
            foreach ($this->prefixesFor($code) as $prefix) {
                $candidates[] = $prefix . '-' . $core;
                $candidates[] = $prefix . '_' . $core;
            }
        }

        foreach ($this->prefixesFor($code) as $prefix) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '[-_]/', $id)) {
                $suffix = substr($id, strlen($prefix) + 1);
                $candidates[] = 'Art.' . $suffix;
                $candidates[] = $suffix;
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return list<string>
     */
    private function prefixesFor(string $code): array
    {
        $map = [
            'ISO27701' => ['27701', 'ISO27701'],
            'ISO27001' => ['ISO27001'],
            'ISO27005' => ['27005', 'ISO27005'],
            'ISO-22301' => ['ISO22301', 'ISO-22301', '22301'],
            'EU-AI-ACT' => ['AIACT', 'EUAIACT', 'EU-AI-ACT'],
            'BSI-C5-2026' => ['C5-2026', 'C52026', 'BSI-C5-2026'],
            'BSI-C5' => ['C5', 'BSI-C5'],
            'CIS-CONTROLS' => ['CIS', 'CIS-CONTROLS'],
            'TKG-2024' => ['TKG', 'TKG-2024'],
            'KRITIS' => ['KRITIS'],
            'NIS2UMSUCG' => ['NIS2UMSUCG', 'NIS2UmsuCG'],
            'NIST-CSF' => ['NIST-CSF', 'NISTCSF'],
        ];

        if (isset($map[$code])) {
            return $map[$code];
        }

        return array_values(array_unique([$code, str_replace(['-', '_'], '', $code)]));
    }
}
