<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pilotage des pages publiques (#268) : la liste (tri, recherche, pagination) et le
 * kill switch sont rendus par le LiveComponent ListePagesPubliques. Zone « admin. »,
 * ROLE_ADMIN.
 */
final class PagesPubliquesController extends AbstractController
{
    #[Route('/pages-publiques', name: 'admin_pages_publiques', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/pages_publiques.html.twig');
    }
}
