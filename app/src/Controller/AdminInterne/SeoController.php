<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\SeoAudit\RapportSeoRepository;
use App\AdminInterne\SeoPresence\RapportPresenceRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran admin du SEO : audit technique/on-page (#345) et présence externe (#346).
 * Dernier rapport + tendance de chaque volet, listes historisées (LiveComponents) et
 * fiches de détail. Lecture seule sur les snapshots. Zone « admin. », ROLE_ADMIN.
 */
final class SeoController extends AbstractController
{
    public function __construct(
        private readonly RapportSeoRepository $rapports,
        private readonly RapportPresenceRepository $presences,
    ) {
    }

    #[Route('/seo', name: 'admin_seo', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function tableau(): Response
    {
        return $this->render('admin/seo.html.twig', [
            'dernier' => $this->rapports->dernier(),
            'tendance' => $this->rapports->tendance(20),
            'dernierePresence' => $this->presences->dernier(),
            'tendancePresence' => $this->presences->tendance(20),
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

    #[Route('/seo/presence/{id}', name: 'admin_presence_seo', host: 'admin.%app.domaine%', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function detailPresence(int $id): Response
    {
        $rapport = $this->presences->find($id);
        if (null === $rapport) {
            throw $this->createNotFoundException('Rapport de présence externe introuvable.');
        }

        return $this->render('admin/seo_presence_detail.html.twig', ['rapport' => $rapport]);
    }
}
