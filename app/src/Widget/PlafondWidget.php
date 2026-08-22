<?php

declare(strict_types=1);

namespace App\Widget;

use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Plafond anti-abus des endpoints publics du widget (#43, lot 1, épic #6).
 *
 * Deux dimensions consommées à chaque appel coûteux (estimation, capture de lead) :
 *  - par IP (`widget_ip`) : borne l'usage individuel ;
 *  - par page/tenant (`widget_page`) : borne le trafic d'une page, pour qu'un pic
 *    distribué sur des IP multiples ne la sature pas.
 *
 * Le dépassement de l'une **ou** l'autre lève une `TooManyRequestsHttpException`
 * (429). Les fabriques sont injectées par alias d'autowiring nommé, dérivé du nom
 * du limiteur dans `config/packages/rate_limiter.yaml`.
 */
final readonly class PlafondWidget
{
    public function __construct(
        private RateLimiterFactoryInterface $widgetIpLimiter,
        private RateLimiterFactoryInterface $widgetPageLimiter,
        private RateLimiterFactoryInterface $widgetEmissionLimiter,
        private RateLimiterFactoryInterface $widgetEvenementLimiter,
        private RateLimiterFactoryInterface $widgetAdresseLimiter,
    ) {
    }

    /**
     * @throws TooManyRequestsHttpException au dépassement d'un des deux plafonds
     */
    public function verifier(?string $ip, string $token): void
    {
        $depasse = !$this->widgetIpLimiter->create($ip ?? '')->consume(1)->isAccepted()
            || !$this->widgetPageLimiter->create($token)->consume(1)->isAccepted();

        if ($depasse) {
            throw new TooManyRequestsHttpException();
        }
    }

    /**
     * Plafond d'émission d'un jeton de chargement (GET du formulaire), par IP :
     * borne le moissonnage de jetons frais.
     *
     * @throws TooManyRequestsHttpException au dépassement du plafond d'émission
     */
    public function verifierEmission(?string $ip): void
    {
        if (!$this->widgetEmissionLimiter->create($ip ?? '')->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }

    /**
     * Plafond de la collecte d'événements de parcours (#90), par IP : l'endpoint
     * est bavard et bon marché, le plafond borne le bourrage de faux événements
     * sans gêner un parcours normal.
     *
     * @throws TooManyRequestsHttpException au dépassement du plafond de collecte
     */
    public function verifierEvenement(?string $ip): void
    {
        if (!$this->widgetEvenementLimiter->create($ip ?? '')->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }

    /**
     * Plafond de l'autocomplétion d'adresse (#287), par IP : l'endpoint suit la
     * frappe mais sort du réseau à chaque appel — le plafond borne le moissonnage
     * de la BAN à travers nous, sans gêner une saisie d'adresse normale.
     *
     * @throws TooManyRequestsHttpException au dépassement du plafond d'adresses
     */
    public function verifierAdresse(?string $ip): void
    {
        if (!$this->widgetAdresseLimiter->create($ip ?? '')->consume(1)->isAccepted()) {
            throw new TooManyRequestsHttpException();
        }
    }
}
