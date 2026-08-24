<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\LimiteurConnexionParIdentite;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RateLimiter\PeekableRequestRateLimiterInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimit;
use Symfony\Component\Security\Http\SecurityRequestAttributes;

/**
 * Le décorateur doit rester **peekable** : le listener de Symfony choisit sa
 * stratégie sur ce seul critère. Peekable, il consulte le compteur puis ne
 * consomme qu'en cas d'échec ; sinon il consomme à chaque tentative et
 * **remet le compteur à zéro à la moindre connexion réussie** — un attaquant
 * disposant d'un compte valide réarmerait alors le plafond global par IP à
 * volonté, ce que ce plafond existe précisément pour empêcher.
 */
final class LimiteurConnexionParIdentiteTest extends TestCase
{
    public function testLesTroisOperationsVoientLIdentiteCanonique(): void
    {
        $interne = new LimiteurEspion();
        $limiteur = new LimiteurConnexionParIdentite($interne);

        $limiteur->peek($this->requetePour('P.R.O+Devis@GoogleMail.com'));
        $limiteur->consume($this->requetePour('pro+autre@gmail.com'));
        $limiteur->reset($this->requetePour('PRO@GMAIL.COM'));

        // Trois écritures, un seul seau : c'est là tout l'objet du décorateur.
        self::assertSame(['pro@gmail.com', 'pro@gmail.com', 'pro@gmail.com'], $interne->identifiantsVus);
    }

    public function testUneSaisieQuiNestPasUneAdresseEstLaisseeTelleQuelle(): void
    {
        $interne = new LimiteurEspion();
        $limiteur = new LimiteurConnexionParIdentite($interne);

        $limiteur->consume($this->requetePour('pas-une-adresse'));

        // Elle ne désigne aucun compte : elle mérite son propre compteur.
        self::assertSame(['pas-une-adresse'], $interne->identifiantsVus);
    }

    public function testLaRequeteReelleGardeLIdentifiantTapeParLePro(): void
    {
        $requete = $this->requetePour('P.R.O+Devis@GoogleMail.com');

        (new LimiteurConnexionParIdentite(new LimiteurEspion()))->consume($requete);

        // C'est lui que le formulaire de login réaffiche après un échec.
        self::assertSame('P.R.O+Devis@GoogleMail.com', $requete->attributes->get(SecurityRequestAttributes::LAST_USERNAME));
    }

    private function requetePour(string $identifiant): Request
    {
        $requete = Request::create('/login', 'POST');
        $requete->attributes->set(SecurityRequestAttributes::LAST_USERNAME, $identifiant);

        return $requete;
    }
}

final class LimiteurEspion implements PeekableRequestRateLimiterInterface
{
    /**
     * @var list<mixed>
     */
    public array $identifiantsVus = [];

    public function consume(Request $request): RateLimit
    {
        return $this->noter($request);
    }

    public function peek(Request $request): RateLimit
    {
        return $this->noter($request);
    }

    public function reset(Request $request): void
    {
        $this->noter($request);
    }

    private function noter(Request $request): RateLimit
    {
        $this->identifiantsVus[] = $request->attributes->get(SecurityRequestAttributes::LAST_USERNAME);

        return new RateLimit(1, new \DateTimeImmutable('@0'), true, 5);
    }
}
