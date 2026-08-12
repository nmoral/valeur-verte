<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class AdminController extends AbstractController
{
    #[Route('/', name: 'admin', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/accueil.html.twig');
    }
}
