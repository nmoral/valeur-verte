<?php

declare(strict_types=1);

namespace App\Controller\Widget;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\BrandingRepository;
use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\Restitution;
use App\Widget\PagePublique;
use App\Widget\PagePubliqueRepository;
use App\Widget\PlafondWidget;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Page publique d'estimation hébergée, à la marque du pro (#40/#41, décision D9).
 *
 * Liée au sous-domaine `widget.` (firewall public `^widget\.`, `security: false`).
 * L'URL est `/{token}/{slug}` : le token opaque désigne le tenant (la marque en
 * est déduite), le slug n'est qu'un habillage décoratif sans effet sur la
 * résolution.
 *
 * Parcours particulier (#41, §4) : le visiteur saisit son bien et obtient un
 * premier chiffre (valeur verte, fourchette, effectif, rayon) SANS email ; le mur
 * de contact vient après. L'estimation réutilise le moteur du mode audit
 * (EstimateurValeurVerte, pur, sans tenant) ; rien n'est persisté et aucun quota
 * n'est consommé. La capture du lead et le PDF relèvent de #42 ; l'anti-abus
 * (Turnstile, jeton anti-rejeu) de #43.
 */
final class PagePubliqueController extends AbstractController
{
    #[Route(
        '/{token}/{slug}',
        name: 'widget_page_publique',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+', 'slug' => '[^/]+'],
        methods: ['GET'],
    )]
    public function afficher(
        string $token,
        PagePubliqueRepository $pages,
        BrandingRepository $brandings,
    ): Response {
        $page = $this->pageActive($token, $pages);

        return $this->rendreFormulaire($brandings->pourTenant($page->tenantIdProprietaire()), $token, [], []);
    }

    #[Route(
        '/{token}/estimation',
        name: 'widget_estimation',
        host: 'widget.%app.domaine%',
        requirements: ['token' => '[A-Za-z0-9]+'],
        methods: ['POST'],
    )]
    public function estimer(
        string $token,
        Request $request,
        PagePubliqueRepository $pages,
        BrandingRepository $brandings,
        EstimateurValeurVerte $estimateur,
        PlafondWidget $plafond,
    ): Response {
        $plafond->verifier($request->getClientIp(), $token);

        $page = $this->pageActive($token, $pages);
        $branding = $brandings->pourTenant($page->tenantIdProprietaire());

        $valeurs = [
            'adresse' => $request->request->getString('adresse'),
            'code_insee' => $request->request->getString('code_insee'),
            'surface_batie' => $request->request->getString('surface_batie'),
            'surface_terrain' => $request->request->getString('surface_terrain'),
            'dpe_avant' => $request->request->getString('dpe_avant'),
            'dpe_apres' => $request->request->getString('dpe_apres'),
        ];

        $dpeAvant = Dpe::tryFrom($valeurs['dpe_avant']);
        $dpeApres = Dpe::tryFrom($valeurs['dpe_apres']);
        if (null === $dpeAvant || null === $dpeApres) {
            return $this->rendreFormulaire($branding, $token, $valeurs, ['dpe' => 'Le DPE actuel et le DPE cible sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $saisie = SaisieAudit::depuis(
                $valeurs['adresse'],
                (int) $valeurs['surface_batie'],
                (int) $valeurs['surface_terrain'],
                $dpeAvant,
                $dpeApres,
            );
        } catch (SaisieInvalide $erreur) {
            return $this->rendreFormulaire($branding, $token, $valeurs, $erreur->erreurs(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->render('widget/resultat.html.twig', [
            'branding' => $branding,
            'token' => $token,
            'valeurs' => $valeurs,
            'restitution' => $this->estimation($estimateur, $valeurs['code_insee'], $saisie),
        ]);
    }

    private function pageActive(string $token, PagePubliqueRepository $pages): PagePublique
    {
        $page = $pages->trouverActiveParJeton($token);
        if (null === $page) {
            throw $this->createNotFoundException('Page publique inconnue ou désactivée.');
        }

        return $page;
    }

    /**
     * @param array<string, string> $valeurs
     * @param array<string, string> $erreurs
     */
    private function rendreFormulaire(Branding $branding, string $token, array $valeurs, array $erreurs, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('widget/page.html.twig', [
            'branding' => $branding,
            'token' => $token,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'dpe_options' => Dpe::cases(),
        ], new Response('', $statut));
    }

    /**
     * Premier chiffre : la valeur verte seule, sans chiffrage économique ni
     * ajustement (réservés au mode audit). On ne fabrique jamais de chiffre par
     * défaut — une commune hors couverture reste sans estimation (null).
     */
    private function estimation(EstimateurValeurVerte $estimateur, string $codeInsee, SaisieAudit $saisie): ?Restitution
    {
        if ('' === $codeInsee) {
            return null;
        }

        try {
            return $estimateur->estimer($codeInsee, $saisie)->restitution();
        } catch (EstimationIndisponible|ClasseNonPubliee) {
            return null;
        }
    }
}
