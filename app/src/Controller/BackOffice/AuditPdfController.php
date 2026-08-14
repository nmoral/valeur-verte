<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Rapport\RapportRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Téléchargement du PDF de l'audit courant (#32, #33). Le rapport a déjà été
 * archivé à la fin de la saisie (voir AuditController) ; on régénère ici le PDF
 * depuis son instantané immuable, jamais recalculé. Un accès sans audit en
 * session, ou dont le rapport a disparu, renvoie vers l'écran de saisie.
 */
final class AuditPdfController extends AbstractController
{
    public function __construct(
        private readonly GenerateurPdfRapport $generateur,
        private readonly RapportRepository $rapports,
    ) {
    }

    #[Route('/audit/resultat/pdf', name: 'back_office_audit_pdf', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        $reference = \is_array($resultat) ? ($resultat['reference'] ?? null) : null;
        $rapport = \is_string($reference) ? $this->rapports->parReference($reference) : null;
        if (null === $rapport) {
            return $this->redirectToRoute('back_office_audit');
        }

        $pdf = $this->generateur->depuisInstantane($rapport->instantane(), $rapport->ajustement());

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
