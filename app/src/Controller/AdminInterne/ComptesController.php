<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vue de pilotage des comptes pro (#47) : la liste (tri, recherche, pagination)
 * est rendue par le LiveComponent ListeComptes. Zone « admin. », ROLE_ADMIN.
 */
final class ComptesController extends AbstractController
{
    #[Route('/comptes', name: 'admin_comptes', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/comptes.html.twig');
    }
}
