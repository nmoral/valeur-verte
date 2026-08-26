<?php

declare(strict_types=1);

namespace App\Controller;

use App\Publication\LectureModelePublie;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Vague 1 SEO (#337, épic #336) — page-outil P0. Landing publique optimisée sur
 * les requêtes « outil » (calculateur valeur verte, simulateur plus-value DPE),
 * portant le différenciateur maison : la mesure se fait *par commune*.
 *
 * Le calculateur réel est le parcours produit (widget/audit) : cette page présente
 * la méthode et convertit vers l'inscription. C'est un aimant à trafic permanent
 * qui **n'exige pas** de millésime publié — sans modèle publié elle existe toujours
 * (jamais de 404). La source des données (DVF/DPE) y est affichée en clair, **datée
 * du millésime publié quand il y en a un** (critère d'acceptation : source +
 * millésime), pour situer la donnée qu'elle décrit.
 */
final class PageOutilController extends AbstractController
{
    #[Route(
        '/calculateur',
        name: 'page_calculateur',
        host: '%app.domaine%',
        methods: ['GET'],
        options: ['sitemap' => true],
    )]
    public function __invoke(LectureModelePublie $modele): Response
    {
        $reponse = $this->render('seo/calculateur.html.twig', [
            // Best-effort : dater la source du millésime servi, sans jamais
            // conditionner l'existence de la page à sa présence (null ⇒ pas de date).
            'millesime' => $modele->publication()?->millesime,
        ]);

        // Landing statique : rien n'y dépend de la session, on rend donc la page
        // cachable par le proxy partagé (le noyau poserait sinon « private »).
        // setSharedMaxAge() marque déjà la réponse « public » : pas de setPublic()
        // redondant.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
