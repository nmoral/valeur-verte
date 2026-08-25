<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\SeoAudit\RapportSeoRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran admin de l'audit SEO (#345) : dernier rapport et sa tendance, liste
 * historisée (LiveComponent ListeRapportsSeo) et fiche de détail par URL/règle.
 * Lecture seule sur les snapshots ``rapport_seo``. Zone « admin. », ROLE_ADMIN.
 */
final class SeoController extends AbstractController
{
    public function __construct(
        private readonly RapportSeoRepository $rapports,
    ) {
    }

    #[Route('/seo', name: 'admin_seo', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function tableau(): Response
    {
        return $this->render('admin/seo.html.twig', [
            'dernier' => $this->rapports->dernier(),
            'tendance' => $this->rapports->tendance(20),
        ]);
    }

    #[Route('/seo/{id}', name: 'admin_rapport_seo', host: 'admin.%app.domaine%', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detail(int $id): Response
    {
        $rapport = $this->rapports->find($id);
        if (null === $rapport) {
            throw $this->createNotFoundException('Rapport d\'audit SEO introuvable.');
        }

        return $this->render('admin/seo_detail.html.twig', ['rapport' => $rapport]);
    }
}
