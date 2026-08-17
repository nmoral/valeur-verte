<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Journal d'audit de l'admin interne (#50) : la liste (tri, recherche, pagination,
 * les plus récents d'abord) est rendue par le LiveComponent ListeJournal. Zone
 * « admin. », réservée à ROLE_ADMIN.
 */
final class JournalAuditController extends AbstractController
{
    #[Route('/journal', name: 'admin_journal', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        return $this->render('admin/journal.html.twig');
    }
}
