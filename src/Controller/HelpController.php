<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
class HelpController extends AbstractController
{
    #[Route('/help/iso9001-bridge', name: 'app_help_iso9001_bridge')]
    public function iso9001Bridge(): Response
    {
        return $this->render('help/iso9001_bridge.html.twig');
    }

    #[Route('/help/glossary', name: 'app_help_glossary')]
    public function glossary(): Response
    {
        return $this->render('help/glossary.html.twig');
    }
}
