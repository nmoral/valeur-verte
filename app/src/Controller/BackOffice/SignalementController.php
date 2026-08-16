<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\AdminInterne\Signalement\Signalement;
use App\AdminInterne\Signalement\SignalementRepository;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Restitution\Restitution;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Canal de retour « ce chiffre me semble faux » (#49) : depuis la restitution, le
 * pro signale une estimation douteuse. Le contexte complet est reconstruit
 * **côté serveur** depuis le résultat en session (fiable), non depuis le client.
 */
final class SignalementController extends AbstractController
{
    #[Route('/audit/resultat/signalement', name: 'back_office_audit_signalement', methods: ['POST'])]
    public function __invoke(Request $request, SignalementRepository $signalements, ClockInterface $clock): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        $saisie = \is_array($resultat) ? ($resultat['saisie'] ?? null) : null;
        if (!$saisie instanceof SaisieAudit) {
            return $this->json(['ok' => false, 'erreur' => 'Aucune estimation à signaler.'], Response::HTTP_BAD_REQUEST);
        }

        $restitution = $resultat['restitution'] ?? null;

        $signalements->enregistrer(Signalement::creer(
            $saisie->adresse(),
            $this->contexte($saisie, $restitution instanceof Restitution ? $restitution : null),
            $request->request->getString('message'),
            $clock->now(),
        ));

        return $this->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function contexte(SaisieAudit $saisie, ?Restitution $restitution): array
    {
        $contexte = [
            'surface_batie' => $saisie->surfaceBatie(),
            'surface_terrain' => $saisie->surfaceTerrain(),
            'dpe_avant' => $saisie->dpeAvant()->value,
            'dpe_apres' => $saisie->dpeApres()->value,
            'nombre_pieces' => $saisie->nombrePieces(),
        ];

        if (null !== $restitution) {
            $contexte['montant_estime'] = $restitution->montantEuros();
            $contexte['n'] = $restitution->n();
            $contexte['rayon_km'] = $restitution->rayonKm();
            $contexte['millesime'] = $restitution->millesime();
        }

        return $contexte;
    }
}
