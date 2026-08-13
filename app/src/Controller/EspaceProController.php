<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class EspaceProController extends AbstractController
{
    /**
     * Page d'atterrissage du pro après connexion : tableau de bord minimal
     * (le menu de la coquille back-office donne accès à tous les outils).
     */
    #[Route('/', name: 'espace_pro', host: 'app.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('back_office/dashboard.html.twig', [
            'page_title' => 'Tableau de bord',
            'nav_active' => 'dashboard',
        ]);
    }
}
