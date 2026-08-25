<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\AdminInterne\Rgpd\DroitRgpd;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Suivi des demandes RGPD (#309) : depuis l'admin, servir une demande d'accès,
 * de rectification ou de portabilité arrivée par email — générer l'export d'un
 * compte, consigner l'arrivée d'une demande et sa réponse. Le suivi s'appuie sur
 * le journal d'audit (`rgpd.*`), pas sur un registre dédié. Zone « admin. »,
 * ROLE_ADMIN. L'effacement, lui, se traite depuis /comptes (#308).
 */
final class RgpdController extends AbstractController
{
    #[Route('/rgpd', name: 'admin_rgpd', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(JournalAudit $journal): Response
    {
        return $this->render('admin/rgpd.html.twig', [
            'droits' => DroitRgpd::cases(),
            'evenements' => $journal->evenementsParPrefixe('rgpd.', 100),
        ]);
    }
}
