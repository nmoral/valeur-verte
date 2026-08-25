<?php

declare(strict_types=1);

namespace App\Controller;

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
 * la méthode et convertit vers l'inscription. C'est un aimant à trafic permanent,
 * volontairement indépendant de l'état du modèle publié — elle existe toujours et
 * ne touche pas la base. La source des données (DVF/DPE) y est affichée en clair ;
 * le millésime servi est porté par les pages qui lisent la donnée (carte, hubs).
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
    public function __invoke(): Response
    {
        $reponse = $this->render('seo/calculateur.html.twig');

        // Landing statique : rien n'y dépend de la session, on rend donc la page
        // cachable par le proxy partagé (le noyau poserait sinon « private »).
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setPublic();
        $reponse->setSharedMaxAge(3600);

        return $reponse;
    }
}
