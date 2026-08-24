<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RateLimiter\RequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\RateLimiter\DefaultLoginRateLimiter;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Plafond de force brute indexé sur l'**identité** du compte visé (#331, #307).
 *
 * Le limiteur de Symfony construit sa clé sur l'identifiant saisi (minusculé).
 * Depuis que la connexion résout l'identité — casse, alias « +tag », points Gmail
 * —, cet identifiant n'est plus unique : `pro+1@`, `pro+2@`… désignent le même
 * compte mais ouvrent chacun un compteur neuf. Le plafond de cinq essais se
 * multiplierait alors par le nombre d'alias imaginables, c'est-à-dire par l'infini.
 *
 * Ce décorateur ramène l'identifiant à sa forme canonique **avant** de déléguer.
 * Une saisie qui n'est pas une adresse est laissée telle quelle : elle ne désigne
 * aucun compte, elle mérite son propre compteur.
 */
#[AsDecorator('security.login_throttling.main.limiter')]
final readonly class LimiteurConnexionParIdentite implements RequestRateLimiterInterface
{
    public function __construct(private DefaultLoginRateLimiter $interne)
    {
    }

    public function consume(Request $request): RateLimit
    {
        return $this->interne->consume(self::parIdentite($request));
    }

    public function reset(Request $request): void
    {
        $this->interne->reset(self::parIdentite($request));
    }

    private static function parIdentite(Request $request): Request
    {
        $saisi = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);

        if (!\is_string($saisi) || !AdresseEmail::estValide($saisi)) {
            return $request;
        }

        // Request::__clone() duplique ses sacs : l'attribut réécrit ici ne fuit pas
        // vers la requête réelle, dont le « dernier identifiant » reste celui que le
        // pro a tapé (c'est lui que le formulaire de login réaffiche).
        $pourLeCompteur = clone $request;
        $pourLeCompteur->attributes->set(
            SecurityRequestAttributes::LAST_USERNAME,
            AdresseEmail::depuis($saisi)->canonique(),
        );

        return $pourLeCompteur;
    }
}
