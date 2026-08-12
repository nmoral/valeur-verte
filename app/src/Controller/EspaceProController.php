<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EspaceProController extends AbstractController
{
    #[Route('/', name: 'espace_pro', host: 'app.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('espace_pro/accueil.html.twig');
    }
}
