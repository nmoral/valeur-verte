<?php

declare(strict_types=1);

namespace App\Controller;

use App\BackOffice\Entreprise\FicheEntrepriseParSiret;
use App\BackOffice\Entreprise\Siret;
use App\Onboarding\Parcours\SuiviInscription;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pré-remplissage de la fiche entreprise (ONB-2) : sur saisie du SIRET,
 * renvoie la fiche complète en JSON. Un SIRET introuvable donne un 404 —
 * le front bascule alors en saisie manuelle sans jamais bloquer l'inscription.
 */
final class RechercheEntrepriseController
{
    #[Route(
        '/inscription/entreprise/{siret}',
        name: 'inscription_entreprise_siret',
        requirements: ['siret' => '\d{14}'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $siret,
        Request $request,
        FicheEntrepriseParSiret $ficheParSiret,
        SuiviInscription $suiviInscription,
    ): JsonResponse {
        // Un SIRET bien formé atteint ce point : l'écran SIRET a donc été renseigné,
        // qu'il soit trouvé ou non (l'engagement est ce qu'on mesure, #111).
        $suiviInscription->siretRenseigne($request->getSession());

        $fiche = $ficheParSiret->parSiret(Siret::depuis($siret));

        if (null === $fiche) {
            return new JsonResponse(['trouvee' => false], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'trouvee' => true,
            'siret' => $fiche->siret()->valeur(),
            'raisonSociale' => $fiche->raisonSociale(),
            'adresse' => $fiche->adresse(),
            'codeNaf' => $fiche->codeNaf(),
            'effectif' => $fiche->effectif(),
            'statutRge' => $fiche->statutRge()->value,
            'statutRgeLibelle' => $fiche->statutRge()->libelle(),
            'domainesRge' => $fiche->domainesRge(),
        ]);
    }
}
