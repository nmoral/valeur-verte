<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Rapport\RapportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Régénération du PDF d'un rapport archivé (#33, §9.1, D5) : par sa référence,
 * le document est reproduit **à l'identique** depuis l'instantané immuable, sans
 * recalcul et quel que soit l'état courant des tables de paramètres. C'est le
 * lien durable de la liste des rapports (#37). Référence inconnue → 404.
 */
final class RapportPdfController extends AbstractController
{
    public function __construct(
        private readonly RapportRepository $rapports,
        private readonly GenerateurPdfRapport $generateur,
    ) {
    }

    #[Route('/rapport/{reference}/pdf', name: 'back_office_rapport_pdf', host: 'app.%app.domaine%', methods: ['GET'])]
    public function __invoke(string $reference): Response
    {
        $rapport = $this->rapports->parReference($reference);
        if (null === $rapport) {
            throw $this->createNotFoundException('Rapport introuvable.');
        }

        // Les déclarations (#26) vivent hors de l'instantané : sans elles, le PDF
        // afficherait une fourchette là où l'écran montre un montant.
        $pdf = $this->generateur->depuisInstantane(
            $rapport->instantane(),
            $rapport->ajustement(),
            $rapport->declarations(),
        );

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('rapport-%s.pdf', $rapport->reference()),
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
