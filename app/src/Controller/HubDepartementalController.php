<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\AnnuaireEpciDuDepartement;
use App\Publication\CarteDepartement;
use App\Publication\CommunesTraceesDbal;
use App\Publication\FichesDepartementales;
use App\Publication\NomsDepartements;
use App\Publication\PerimetrePublic;
use App\Publication\RepertoireDepartements;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vague 1 SEO (#337, épic #336) — hub départemental. Une page par département,
 * cible « valeur verte {département} », porte d'entrée du maillage : elle remonte
 * vers la carte nationale et l'étude, et recevra plus tard le maillage montant des
 * EPCI/communes (vagues suivantes).
 *
 * L'URL porte le *slug* du département ({@see RepertoireDepartements}), pas son
 * code. Décision produit (#337) : la page n'existe que si le département est
 * réellement mesuré au millésime publié — sinon 404, jamais de page vide.
 */
final class HubDepartementalController extends AbstractController
{
    #[Route(
        '/valeur-verte/{departement}',
        name: 'hub_departemental',
        host: '%app.domaine%',
        requirements: ['departement' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $departement,
        FichesDepartementales $fiches,
        RepertoireDepartements $repertoire,
        AnnuaireEpciDuDepartement $annuaireEpci,
        PerimetrePublic $perimetre,
        CommunesTraceesDbal $communes,
    ): Response {
        $code = $repertoire->code($departement);
        if (null === $code) {
            throw $this->createNotFoundException(\sprintf('Département inconnu : %s.', $departement));
        }

        // Mise en ligne progressive (#393) : un département valide mais hors du
        // périmètre publié n'est pas encore ouvert. Page « pas encore disponible »
        // servie en 404 (statut correct pour les moteurs, message clair pour l'humain).
        if (!$perimetre->contient($code)) {
            $reponse = $this->render('seo/region-indisponible.html.twig', [
                'departement_nom' => NomsDepartements::pour($code),
            ]);
            $reponse->setStatusCode(Response::HTTP_NOT_FOUND);

            return $reponse;
        }

        $fiche = $fiches->pour($code);
        if (null === $fiche) {
            throw $this->createNotFoundException(\sprintf('Département non mesuré : %s.', $departement));
        }

        $reponse = $this->render('seo/hub-departemental.html.twig', [
            'fiche' => $fiche,
            'slug' => $repertoire->slug($code),
            // Maillage descendant (#364) : les EPCI mesurés du département.
            'annuaire_epci' => $annuaireEpci->pour($code),
            // Choroplèthe communale (#392) : contours + gain vert par commune ;
            // vide (donc masquée) si les contours ne sont pas chargés.
            'carte_dept' => (new CarteDepartement($communes->pour($code)))->donnees(),
        ]);

        // Page stable tant que le millésime ne change pas : cachable par le proxy
        // partagé (setSharedMaxAge marque déjà « public »).
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
