<?php

declare(strict_types=1);

namespace App\Erreur\Twig;

use App\Erreur\Zone;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose aux gabarits d'erreur l'accueil de la zone courante (#197) : l'URL
 * absolue de retour et son libellé. Hors contexte HTTP, on retombe sur
 * l'accueil public pour que la page d'erreur reste toujours rendable.
 */
final class AccueilZoneExtension extends AbstractExtension
{
    public function __construct(
        private RequestStack $requetes,
        private UrlGeneratorInterface $generateurUrl,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_accueil_zone', $this->urlAccueilZone(...)),
            new TwigFunction('libelle_accueil_zone', $this->libelleAccueilZone(...)),
        ];
    }

    public function urlAccueilZone(): string
    {
        return $this->generateurUrl->generate(
            $this->zoneCourante()->route(),
            [],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    public function libelleAccueilZone(): string
    {
        return $this->zoneCourante()->libelle();
    }

    private function zoneCourante(): Zone
    {
        $requete = $this->requetes->getMainRequest();

        if (null === $requete) {
            return Zone::Public;
        }

        return Zone::depuisHote($requete->getHost());
    }
}
