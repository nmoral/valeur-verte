<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Restitution\Restitution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page de restitution dédiée (§5.3, #31) : le chiffre y est présenté avec son
 * contexte (effectif, fourchette, rayon, millésime) et son indicateur de
 * fiabilité. Le résultat vient de la session posée à la saisie ; un accès direct,
 * sans résultat, renvoie vers l'écran de saisie.
 */
final class AuditResultatController extends AbstractController
{
    #[Route('/audit/resultat', name: 'back_office_audit_resultat', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $resultat = $request->getSession()->get(AuditController::SESSION_RESULTAT);
        if (!\is_array($resultat) || !(($resultat['saisie'] ?? null) instanceof SaisieAudit)) {
            return $this->redirectToRoute('back_office_audit');
        }

        $saisie = $resultat['saisie'];
        $restitution = $resultat['restitution'] ?? null;

        return $this->render('back_office/audit_resultat.html.twig', [
            'page_title' => 'Restitution',
            'nav_active' => 'audit',
            'saisie' => $saisie,
            'restitution' => $restitution instanceof Restitution ? $restitution : null,
            'avertissements' => $saisie->avertissements(),
        ]);
    }
}
