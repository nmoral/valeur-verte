<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\FichesCommunales;
use App\Publication\RepertoireCommunes;
use App\Publication\RepertoireDepartements;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vague 2 SEO (#362, épic #336) — page publique d'une commune, cible « valeur verte
 * {commune} ». Le moat programmatique : une page par commune mesurée, gatée par la
 * fiabilité (#361).
 *
 * L'URL porte les *slugs* du département et de la commune ({@see RepertoireCommunes}),
 * pas les codes. Décision produit (comme le hub #337) : la page n'existe que si la
 * commune est réellement mesurée au millésime publié — sinon 404, jamais de page vide.
 * La meta robots, elle, découle du gating d'indexation (#361) : une commune sous le
 * seuil est servie en `noindex,follow` (repli), pas retirée.
 */
final class CommuneController extends AbstractController
{
    #[Route(
        '/valeur-verte/{departement}/{commune}',
        name: 'commune_valeur_verte',
        host: '%app.domaine%',
        requirements: ['departement' => '[a-z0-9-]+', 'commune' => '[a-z0-9-]+'],
        methods: ['GET'],
    )]
    public function __invoke(
        string $departement,
        string $commune,
        RepertoireCommunes $communes,
        FichesCommunales $fiches,
        RepertoireDepartements $departements,
    ): Response {
        $laCommune = $communes->resoudre($departement, $commune);
        if (null === $laCommune) {
            throw $this->createNotFoundException(\sprintf('Commune inconnue : %s / %s.', $departement, $commune));
        }

        $fiche = $fiches->pour($laCommune);
        if (null === $fiche) {
            throw $this->createNotFoundException(\sprintf('Commune non mesurée : %s.', $commune));
        }

        $reponse = $this->render('seo/commune.html.twig', [
            'fiche' => $fiche,
            'commune_nom' => $laCommune->nom,
            'departement_nom' => $departements->nom($laCommune->codeDepartement),
            // Slugs normalisés depuis le référentiel : le canonical ne dépend pas de la
            // casse/graphie de l'URL demandée (comme le hub #337).
            'departement_slug' => $departements->slug($laCommune->codeDepartement),
            'commune_slug' => $laCommune->slug(),
        ]);

        // Page stable tant que le millésime ne change pas : cachable par le proxy partagé.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
