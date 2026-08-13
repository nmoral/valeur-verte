<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\JournalAudit\JournalAudit;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Journal d'audit de l'admin interne (#50) : liste les événements sensibles,
 * les plus récents d'abord. Zone « admin. », réservée à ROLE_ADMIN.
 */
final class JournalAuditController extends AbstractController
{
    #[Route('/journal', name: 'admin_journal', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(JournalAudit $journal): Response
    {
        return $this->render('admin/journal.html.twig', [
            'evenements' => $journal->evenementsRecents(),
        ]);
    }
}
