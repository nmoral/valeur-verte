<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Plafond de force brute indexé sur l'**identité** du compte visé (#331, #307).
 *
 * Le limiteur de Symfony construit sa clé sur l'identifiant saisi (minusculé).
 * Depuis que la connexion résout l'identité — casse, alias « +tag », points Gmail
 * —, cet identifiant n'est plus unique : `pro+1@`, `pro+2@`… désignent le même
 * compte mais ouvrent chacun un compteur neuf.
 *
 * Avant cette résolution, faire varier l'alias ne testait **rien** : la recherche
 * exacte ne trouvait aucun compte, donc aucun mot de passe n'était comparé. Depuis,
 * chaque variante teste réellement le mot de passe du compte visé. Sans ce
 * décorateur, un attaquant échappe donc au plafond par identifiant (5 essais) et
 * n'est plus retenu que par le plafond global par IP, que Symfony fixe à
 * `5 × max_attempts` (25) : cinq fois plus d'essais depuis une IP, et cinq fois
 * plus de débit pour une attaque distribuée.
 *
 * Ce décorateur ramène l'identifiant à sa forme canonique **avant** de déléguer.
 * Une saisie qui n'est pas une adresse est laissée telle quelle : elle ne désigne
 * aucun compte, elle mérite son propre compteur.
 *
 * ⚠️ Il doit rester **peekable** : le listener de Symfony choisit sa stratégie sur
 * ce seul critère. Peekable, il consulte le compteur et ne consomme qu'en cas
 * d'échec ; sinon il consomme à chaque tentative et **remet le compteur à zéro à
 * la moindre connexion réussie** — un attaquant disposant d'un compte valide
 * réarmerait alors le plafond global par IP à volonté, ce que ce plafond existe
 * précisément pour empêcher. Décorer en ne gardant que RequestRateLimiterInterface
 * échange donc un trou contre un autre.
 */
#[AsDecorator('security.login_throttling.main.limiter')]
final readonly class LimiteurConnexionParIdentite implements PeekableRequestRateLimiterInterface
{
    public function __construct(private PeekableRequestRateLimiterInterface $interne)
    {
    }

    public function consume(Request $request): RateLimit
    {
        return $this->interne->consume(self::parIdentite($request));
    }

    public function peek(Request $request): RateLimit
    {
        return $this->interne->peek(self::parIdentite($request));
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
