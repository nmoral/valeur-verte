<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Compte\SupervisionComptes;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue de pilotage des comptes pro (#47) : identité, rapports consommés et statut
 * d'essai. Zone « admin. », réservée à ROLE_ADMIN.
 */
final class ComptesController extends AbstractController
{
    #[Route('/comptes', name: 'admin_comptes', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(SupervisionComptes $supervision): Response
    {
        return $this->render('admin/comptes.html.twig', [
            'comptes' => $supervision->comptes(),
        ]);
    }
}
