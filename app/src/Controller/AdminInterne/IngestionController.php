<?php

declare(strict_types=1);

namespace App\Controller\AdminInterne;

use App\AdminInterne\Ingestion\EtatDernierImport;
use App\AdminInterne\Ingestion\SyntheseIngestion;
use App\AdminInterne\RapportBatch\LectureRapportsBatch;
use App\AdminInterne\RapportBatch\RapportBatch;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * État des imports (#46) : la dernière ingestion de chaque département, avec les
 * signaux des portes DAT-3 (appariement) et DAT-5 (fraîcheur). L'archive complète
 * — toutes les exécutions, calcul compris — reste l'affaire de #131. Les deux
 * écrans lisent la **même** table, aucun ne recalcule sa version des chiffres.
 *
 * Zone « admin. », ROLE_ADMIN, lecture seule sur ``data.rapport_batch``.
 */
final class IngestionController extends AbstractController
{
    public function __construct(
        private readonly LectureRapportsBatch $lecture,
        private readonly ClockInterface $horloge,
    ) {
    }

    #[Route('/ingestion', name: 'admin_ingestion', host: 'admin.%app.domaine%', methods: ['GET'])]
    public function __invoke(): Response
    {
        $precedentes = $this->lecture->volumetriesPrecedentes();
        $synthese = SyntheseIngestion::depuis(array_map(
            static fn (RapportBatch $rapport): EtatDernierImport => new EtatDernierImport(
                $rapport,
                $precedentes[$rapport->departement()] ?? null,
            ),
            $this->lecture->dernieresIngestions(),
        ));

        return $this->render('admin/ingestion.html.twig', [
            'synthese' => $synthese,
            'maintenant' => $this->horloge->now(),
        ]);
    }
}
