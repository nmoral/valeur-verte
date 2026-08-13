<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Pdf\GenerateurPdfRapport;
use App\BackOffice\Pdf\GenerateurReferenceRapport;
use App\BackOffice\Restitution\Restitution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Téléchargement du PDF à l'en-tête du pro (#32, §4/§10.4). Le document est
 * généré côté serveur à partir du résultat en session (saisie + restitution) et
 * de la marque du pro, avec un identifiant de rapport unique (§9.6). Un accès
 * sans résultat renvoie vers l'écran de saisie, comme la page de restitution.
 */
final class AuditPdfController extends AbstractController
{
    public function __construct(
        private readonly GenerateurPdfRapport $generateur,
        private readonly GenerateurReferenceRapport $references,
        private readonly BrandingRepository $brandings,
    ) {
    }

    #[Route('/audit/resultat/pdf', name: 'back_office_audit_pdf', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        if (!\is_array($resultat) || !(($resultat['saisie'] ?? null) instanceof SaisieAudit)) {
            return $this->redirectToRoute('back_office_audit');
        }

        $saisie = $resultat['saisie'];
        $restitution = ($resultat['restitution'] ?? null) instanceof Restitution ? $resultat['restitution'] : null;
        $reference = $this->references->generer();

        $pdf = $this->generateur->generer($saisie, $restitution, $this->brandings->courant(), $reference);

        $disposition = HeaderUtils::makeDisposition(
            HeaderUtils::DISPOSITION_ATTACHMENT,
            \sprintf('rapport-%s.pdf', $reference->valeur()),
        );

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => $disposition,
        ]);
    }
}
