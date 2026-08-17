<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\RapportBatch\LectureRapportsBatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Rapport de batch archivé (#131) : liste chronologique (LiveComponent
 * ListeRapportsBatch) et fiche de détail. Lecture seule sur ``data.rapport_batch``.
 * Zone « admin. », ROLE_ADMIN — « la première chose à regarder six mois plus tard ».
 */
final class RapportsBatchController extends AbstractController
{
    public function __construct(
        private readonly LectureRapportsBatch $lecture,
    ) {
    }

    #[Route('/rapports-batch', name: 'admin_rapports_batch', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function liste(): Response
    {
        return $this->render('admin/rapports_batch.html.twig');
    }

    #[Route('/rapports-batch/{id}', name: 'admin_rapport_batch', host: 'admin.%app.domaine%', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $rapport = $this->lecture->parId($id);
        if (null === $rapport) {
            throw $this->createNotFoundException('Rapport de batch introuvable.');
        }

        return $this->render('admin/rapports_batch_detail.html.twig', ['rapport' => $rapport]);
    }
}
