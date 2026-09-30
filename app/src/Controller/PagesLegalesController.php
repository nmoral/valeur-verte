<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\EventListener\AbstractSessionListener;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Pages légalement obligatoires du site public (#228) : mentions légales
 * (LCEN art. 6-III), information des personnes (RGPD art. 13), conditions
 * d'utilisation et conditions de vente (C. com. art. L441-1).
 *
 * @phpstan-type Identite array<string, string>
 */
final class PagesLegalesController extends AbstractController
{
    /**
     * Date de la dernière révision des CGU : c'est elle qui est acceptée à
     * l'inscription, et qui devra être conservée avec le compte.
     */
    public const VERSION_CGU = '2026-08-16';

    /**
     * @param Identite $editeur
     * @param Identite $hebergeur
     */
    public function __construct(
        private readonly array $editeur,
        private readonly array $hebergeur,
    ) {
    }

    #[Route('/mentions-legales', name: 'mentions_legales', host: '%app.domaine%', methods: ['GET'], options: ['sitemap' => true])]
    public function mentionsLegales(): Response
    {
        return $this->pageLegale('legal/mentions_legales.html.twig', [
            'editeur' => $this->editeur,
            'hebergeur' => $this->hebergeur,
        ]);
    }

    #[Route('/confidentialite', name: 'confidentialite', host: '%app.domaine%', methods: ['GET'], options: ['sitemap' => true])]
    public function confidentialite(): Response
    {
        return $this->pageLegale('legal/confidentialite.html.twig', [
            'editeur' => $this->editeur,
            'hebergeur' => $this->hebergeur,
        ]);
    }

    #[Route('/cgu', name: 'cgu', host: '%app.domaine%', methods: ['GET'], options: ['sitemap' => true])]
    public function cgu(): Response
    {
        return $this->pageLegale('legal/cgu.html.twig', [
            'editeur' => $this->editeur,
            'version' => self::VERSION_CGU,
        ]);
    }

    #[Route('/cgv', name: 'cgv', host: '%app.domaine%', methods: ['GET'])]
    public function cgv(): Response
    {
        // Offre commerciale non ouverte (#57) : le site est public et gratuit, sans
        // compte payant. L'URL historique redirige vers les CGU tant que la vente
        // n'est pas ouverte ; la CGV reviendra avec l'offre professionnelle. Retirée
        // aussi du pied de page public et de la sitemap.
        return $this->redirectToRoute('cgu', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function pageLegale(string $gabarit, array $variables): Response
    {
        $reponse = $this->render($gabarit, $variables);

        // Ces pages ne dépendent d'aucun état de session et ne changent qu'au
        // déploiement : on lève le « private » que le noyau pose dès qu'une
        // session existe, sinon le cache partagé serait désactivé pour tout le
        // monde.
        $reponse->headers->set(AbstractSessionListener::NO_AUTO_CACHE_CONTROL_HEADER, 'true');
        $reponse->setPublic();
        $reponse->setSharedMaxAge(86400);

        return $reponse;
    }
}
