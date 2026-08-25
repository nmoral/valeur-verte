<?php

declare(strict_types=1);

namespace App\Controller;

use App\Sitemap\SitemapGenerator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Twig\Environment;

/**
 * Sitemap des pages publiques indexables (#340). Le rendu est mémoïsé : les
 * routes ne changent qu'au déploiement et l'état « millésime publié » à une
 * décision manuelle — inutile de reconstruire (et de requêter la base) à chaque
 * passage d'un robot. Le cache HTTP partagé complète le cache applicatif.
 */
final class SitemapController extends AbstractController
{
    private const CLE_CACHE = 'sitemap.public.xml';
    private const DUREE = 3600;

    #[Route('/sitemap.xml', name: 'sitemap', host: '%app.domaine%', methods: ['GET'])]
    public function __invoke(
        SitemapGenerator $generateur,
        Environment $twig,
        #[Autowire(service: 'cache.sitemap')]
        CacheInterface $cache,
    ): Response
    {
        $xml = $cache->get(self::CLE_CACHE, function (ItemInterface $item) use ($generateur, $twig): string {
            $item->expiresAfter(self::DUREE);

            return $twig->render('sitemap/index.xml.twig', ['urls' => $generateur->urls()]);
        });

        $reponse = new Response($xml);
        $reponse->headers->set('Content-Type', 'application/xml; charset=UTF-8');
        // La page ne dépend d'aucun état de session : on lève le « private » que
        // le noyau pose dès qu'une session existe, sinon le cache partagé serait
        // désactivé pour tout le monde.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setPublic();
        $reponse->setSharedMaxAge(self::DUREE);

        return $reponse;
    }
}
