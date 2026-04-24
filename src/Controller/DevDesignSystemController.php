<?php
declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
final class DevDesignSystemController extends AbstractController
{
    #[Route('/dev/design-system', name: 'dev_design_system', methods: ['GET'])]
    public function index(): Response
    {
        if ($this->getParameter('kernel.environment') !== 'dev') {
            throw $this->createNotFoundException();
        }
        return $this->render('dev/design_system.html.twig');
    }
}
