<?php

declare(strict_types=1);

namespace App\Controller\BackOffice;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Audit\SaisieInvalide;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetRepository;
use App\BackOffice\Estimation\ClasseNonPubliee;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Estimation\ResultatEstimation;
use App\BackOffice\Rapport\ArchiveurRapport;
use App\BackOffice\Rapport\Dpe;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Écran de saisie du mode audit (§4) : le pro consigne ce qu'il a constaté.
 * La validation des bornes (§9.8) est bloquante — protection de crédibilité —
 * tandis que l'incohérence surface/pièces se contente d'avertir.
 *
 * Sur saisie valide : Post/Redirect/Get vers la page de restitution dédiée
 * (§5.3). Le résultat transite par la session, ce qui laisse l'écran de saisie
 * propre et rend la restitution rafraîchissable.
 */
final class AuditController extends AbstractController
{
    public const SESSION_RESULTAT = 'audit_resultat';

    public function __construct(
        private readonly EstimateurValeurVerte $estimateur,
        private readonly ArchiveurRapport $archiveur,
        private readonly BouquetRepository $bouquets,
    ) {
    }

    #[Route('/audit', name: 'back_office_audit', methods: ['GET', 'POST'])]
    public function __invoke(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            return $this->traiter($request);
        }

        return $this->rendre($this->champsVides(), []);
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
            'bouquet' => $request->request->getString('bouquet'),
        ];

        if (!$this->isCsrfTokenValid('audit', $request->request->getString('_csrf_token'))) {
            return $this->rendre($valeurs, ['global' => 'Votre session a expiré, merci de renvoyer le formulaire.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $dpeAvant = Dpe::tryFrom($valeurs['dpe_avant']);
        $dpeApres = Dpe::tryFrom($valeurs['dpe_apres']);
        if (null === $dpeAvant || null === $dpeApres) {
            return $this->rendre($valeurs, ['dpe' => 'Le DPE actuel et le DPE cible sont obligatoires.'], Response::HTTP_UNPROCESSABLE_ENTITY);
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
            return $this->rendre($valeurs, $erreur->erreurs(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $valeurBien = $valeurs['valeur_bien'];
        $resultat = $this->estimer($valeurs['code_insee'], $saisie, '' === $valeurBien ? null : (int) $valeurBien);
        $bouquet = $this->bouquetChoisi($valeurs['bouquet']);

        // Un audit terminé est archivé immédiatement (#33/#37) : il apparaît dans
        // « Rapports & leads » et son PDF (#32) se régénère depuis l'instantané.
        // La restitution reste en session pour l'affichage de la page dédiée.
        $rapport = $this->archiveur->archiver($saisie, $resultat?->restitution(), $resultat?->contexte(), $bouquet);
        $request->getSession()->set(self::SESSION_RESULTAT, [
            'saisie' => $saisie,
            'restitution' => $resultat?->restitution(),
            'reference' => $rapport->reference(),
            'bouquet' => $bouquet,
        ]);

        return $this->redirectToRoute('back_office_audit_resultat');
    }

    private function bouquetChoisi(string $nom): Bouquet
    {
        // Sans bouquet explicite (ou nom inconnu), on retient le bouquet complet —
        // un rapport reste produisible sans choix préalable (§9.2).
        return $this->bouquets->parNom($nom) ?? Bouquet::renovationAmpleurStandard();
    }

    private function estimer(string $codeInsee, SaisieAudit $saisie, ?int $valeurBien): ?ResultatEstimation
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
            'bouquet' => Bouquet::renovationAmpleurStandard()->nom(),
        ];
    }

    /**
     * @param array<string, string> $valeurs
     * @param array<string, string> $erreurs
     */
    private function rendre(array $valeurs, array $erreurs, int $statut = Response::HTTP_OK): Response
    {
        return $this->render('back_office/audit.html.twig', [
            'page_title' => 'Nouvel audit',
            'nav_active' => 'audit',
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'dpe_options' => Dpe::cases(),
            'bouquets' => $this->bouquets->disponibles(),
        ], new Response('', $statut));
    }
}
