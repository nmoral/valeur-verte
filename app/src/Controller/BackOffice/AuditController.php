<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de saisie du mode audit (§4) : le pro consigne ce qu'il a constaté.
 * La validation des bornes (§9.8) est bloquante — protection de crédibilité —
 * tandis que l'incohérence surface/pièces se contente d'avertir.
 *
 * Sur saisie valide : Post/Redirect/Get. La confirmation transite par un flash
 * consommé au GET suivant, ce qui évite la re-soumission au rafraîchissement.
 */
final class AuditController extends AbstractController
{
    public function __construct(private readonly EstimateurValeurVerte $estimateur)
    {
    }

    #[Route('/audit', name: 'back_office_audit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->traiter($request);
        }

        $saisie = $this->confirmationEnAttente($request);

        return $this->rendre($this->champsVides(), [], $saisie, $this->restitutionEnAttente($request));
    }

    private function traiter(Request $request): Response
    {
        $valeurs = [
            'adresse' => $request->request->getString('adresse'),
            'surface_batie' => $request->request->getString('surface_batie'),
            'surface_terrain' => $request->request->getString('surface_terrain'),
            'dpe_avant' => $request->request->getString('dpe_avant'),
            'dpe_apres' => $request->request->getString('dpe_apres'),
            'nombre_pieces' => $request->request->getString('nombre_pieces'),
            'code_insee' => $request->request->getString('code_insee'),
            'valeur_bien' => $request->request->getString('valeur_bien'),
        ];

        if (!$this->isCsrfTokenValid('audit', $request->request->getString('_csrf_token'))) {
            return $this->rendre($valeurs, ['global' => 'Votre session a expiré, merci de renvoyer le formulaire.'], null, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dpeAvant = Dpe::tryFrom($valeurs['dpe_avant']);
        $dpeApres = Dpe::tryFrom($valeurs['dpe_apres']);
        if (null === $dpeAvant || null === $dpeApres) {
            return $this->rendre($valeurs, ['dpe' => 'Le DPE actuel et le DPE cible sont obligatoires.'], null, null, Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->rendre($valeurs, $erreur->erreurs(), null, null, Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->addFlash('audit_confirmation', $saisie);

        $valeurBien = $valeurs['valeur_bien'];
        $restitution = $this->estimer(
            $valeurs['code_insee'],
            $saisie,
            '' === $valeurBien ? null : (int) $valeurBien,
        );
        if (null !== $restitution) {
            $this->addFlash('audit_restitution', $restitution);
        }

        return $this->redirectToRoute('back_office_audit');
    }

    private function estimer(string $codeInsee, SaisieAudit $saisie, ?int $valeurBien): ?Restitution
    {
        if ('' === $codeInsee) {
            return null;
        }

        try {
            return $this->estimateur->estimer($codeInsee, $saisie, $valeurBien);
        } catch (EstimationIndisponible|ClasseNonPubliee) {
            return null;
        }
    }

    private function confirmationEnAttente(Request $request): ?SaisieAudit
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return null;
        }

        foreach ($session->getFlashBag()->get('audit_confirmation') as $valeur) {
            if ($valeur instanceof SaisieAudit) {
                return $valeur;
            }
        }

        return null;
    }

    private function restitutionEnAttente(Request $request): ?Restitution
    {
        $session = $request->hasSession() ? $request->getSession() : null;
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return null;
        }

        foreach ($session->getFlashBag()->get('audit_restitution') as $valeur) {
            if ($valeur instanceof Restitution) {
                return $valeur;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function champsVides(): array
    {
        return [
            'adresse' => '',
            'surface_batie' => '',
            'surface_terrain' => '',
            'dpe_avant' => '',
            'dpe_apres' => '',
            'nombre_pieces' => '',
            'code_insee' => '',
            'valeur_bien' => '',
        ];
    }

    /**
     * @param array<string, string> $valeurs
     * @param array<string, string> $erreurs
     */
    private function rendre(array $valeurs, array $erreurs, ?SaisieAudit $saisie, ?Restitution $restitution = null, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('back_office/audit.html.twig', [
            'page_title' => 'Nouvel audit',
            'nav_active' => 'audit',
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'restitution' => $restitution,
            'avertissements' => null === $saisie ? [] : $saisie->avertissements(),
            'dpe_options' => Dpe::cases(),
        ], new Response('', $statut));
    }
}
