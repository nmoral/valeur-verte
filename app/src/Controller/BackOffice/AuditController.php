<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Rapport\Dpe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de saisie du mode audit (§4) : le pro consigne ce qu'il a constaté.
 * La validation des bornes (§9.8) est bloquante — protection de crédibilité —
 * tandis que l'incohérence surface/pièces se contente d'avertir. La saisie
 * validée est mise en session pour la restitution (#31).
 */
final class AuditController extends AbstractController
{
    #[Route('/back-office/audit', name: 'back_office_audit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        $valeurs = [
            'adresse' => $request->request->getString('adresse'),
            'surface_batie' => $request->request->getString('surface_batie'),
            'surface_terrain' => $request->request->getString('surface_terrain'),
            'dpe_avant' => $request->request->getString('dpe_avant'),
            'dpe_apres' => $request->request->getString('dpe_apres'),
            'nombre_pieces' => $request->request->getString('nombre_pieces'),
        ];

        if (!$request->isMethod('POST')) {
            return $this->rendre($valeurs, [], null, []);
        }

        if (!$this->isCsrfTokenValid('audit', $request->request->getString('_csrf_token'))) {
            return $this->rendre($valeurs, ['global' => 'Session expirée, merci de réessayer.'], null, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dpeAvant = Dpe::tryFrom($valeurs['dpe_avant']);
        $dpeApres = Dpe::tryFrom($valeurs['dpe_apres']);
        if (null === $dpeAvant || null === $dpeApres) {
            return $this->rendre($valeurs, ['dpe' => 'Le DPE actuel et le DPE cible sont obligatoires.'], null, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saisie = SaisieAudit::depuis(
                $valeurs['adresse'],
                (int) $valeurs['surface_batie'],
                (int) $valeurs['surface_terrain'],
                $dpeAvant,
                $dpeApres,
                '' === $valeurs['nombre_pieces'] ? null : (int) $valeurs['nombre_pieces'],
            );
        } catch (SaisieInvalide $erreur) {
            return $this->rendre($valeurs, $erreur->erreurs(), null, [], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $request->getSession()->set('audit.saisie', $saisie);

        return $this->rendre($valeurs, [], $saisie, $saisie->avertissements());
    }

    /**
     * @param array<string, string> $valeurs
     * @param array<string, string> $erreurs
     * @param list<string>          $avertissements
     */
    private function rendre(array $valeurs, array $erreurs, ?SaisieAudit $saisie, array $avertissements, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('back_office/audit.html.twig', [
            'page_title' => 'Nouvel audit',
            'nav_active' => 'audit',
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'avertissements' => $avertissements,
            'dpe_options' => Dpe::cases(),
        ], new Response('', $statut));
    }
}
