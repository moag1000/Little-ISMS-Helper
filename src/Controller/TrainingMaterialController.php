<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\Training;
use App\Security\Voter\TrainingVoter;
use App\Service\Upload\ProtectedUploads;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves an uploaded training material. The files live below var/uploads (outside the
 * web root), so the only way to them is this action: the person must see the training
 * (TrainingVoter::VIEW, same organisation), and only files listed on the training are
 * served. DownloadAuditSubscriber writes the audit entry (Content-Disposition response).
 */
#[RequiresModule('training')]
final class TrainingMaterialController extends AbstractController
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
    ) {
    }

    #[Route(
        '/training/{id}/material/{filename}',
        name: 'app_training_material_download',
        requirements: ['id' => '\d+', 'filename' => '[A-Za-z0-9._-]+'],
        methods: ['GET'],
    )]
    #[IsGranted(TrainingVoter::VIEW, 'training')]
    public function download(Training $training, string $filename): Response
    {
        $entry = null;
        foreach ($training->getMaterialFiles() as $file) {
            if ($file['filename'] === $filename) {
                $entry = $file;
                break;
            }
        }
        $uploads = new ProtectedUploads($this->projectDir);
        $path = $uploads->resolve($uploads->storedPath(ProtectedUploads::TRAINING_MATERIALS, $filename));
        if ($entry === null || $path === null) {
            throw $this->createNotFoundException('Material not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            (string) preg_replace('/[^\w\s.\-]/', '', $entry['originalName']) ?: $filename,
        );

        return $response;
    }
}
