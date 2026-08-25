<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\EtudePubliee;
use App\Publication\FichesDepartementales;
use App\Publication\RepertoireDepartements;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vague 1 SEO (#337, épic #336) — page-carte nationale. Landing cible « carte
 * valeur verte France », pensée comme aimant à backlinks : elle reprend la carte
 * choroplèthe publiée (#48/#224) et l'ouvre sur l'annuaire des hubs
 * départementaux, dont elle porte le maillage descendant.
 *
 * Sans millésime publié (REG-2), il n'y a pas de carte à montrer : 404, comme la
 * page d'étude.
 */
final class CarteNationaleController extends AbstractController
{
    #[Route(
        '/carte-valeur-verte-france',
        name: 'carte_nationale',
        host: '%app.domaine%',
        methods: ['GET'],
        options: ['sitemap' => true],
    )]
    public function __invoke(
        EtudePubliee $etudePubliee,
        FichesDepartementales $fiches,
        RepertoireDepartements $repertoire,
    ): Response {
        $etude = $etudePubliee->courante();
        if (null === $etude) {
            throw $this->createNotFoundException('Aucun millésime publié.');
        }

        $reponse = $this->render('seo/carte-nationale.html.twig', [
            'etude' => $etude,
            'annuaire' => $repertoire->annuaire($fiches->codesMesures()),
        ]);

        // Carte identique pour tous tant que le millésime ne change pas : on la rend
        // cachable par le proxy partagé (setSharedMaxAge marque déjà « public »).
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
