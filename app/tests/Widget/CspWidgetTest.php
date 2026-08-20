<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\BackOffice\Branding\Branding;
use App\BackOffice\Branding\Couleur;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Politique de sécurité du contenu de la page publique hébergée (#267, D9).
 *
 * La page est hébergée **sur notre origine** et affiche du contenu configuré par
 * le pro (nom, couleur, logo) : une injection y jouerait sur notre domaine. La CSP
 * est la deuxième barrière, derrière la validation à la saisie — elle borne ce que
 * la page peut charger et exécuter, même si une valeur malveillante passait.
 *
 * Elle borne aussi l'**encadrement** : D9 abandonne le widget en iframe, plus rien
 * n'a de raison d'encadrer cette page (anti-clickjacking).
 */
final class CspWidgetTest extends WebTestCase
{
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';
    private const string HOTE_APP = 'app.valeurverte.localhost';
    private const string TURNSTILE = 'https://challenges.cloudflare.com';

    public function testLaPagePubliquePorteUnePolitiqueDeSecurite(): void
    {
        $directives = $this->directivesDeLaPagePublique();

        self::assertNotSame([], $directives, 'La page publique doit porter un en-tête Content-Security-Policy.');
        self::assertSame(["'self'"], $directives['default-src'] ?? []);
    }

    public function testLaPageNePeutPlusEtreEncadree(): void
    {
        // D9 : la page hébergée remplace le widget en iframe. Plus personne n'a de
        // raison de l'encadrer — on l'interdit franchement (anti-clickjacking).
        self::assertSame(["'none'"], $this->directivesDeLaPagePublique()['frame-ancestors'] ?? []);
    }

    public function testSeulsLesScriptsDeLOrigineEtDeTurnstileSontAutorises(): void
    {
        $scriptSrc = $this->directivesDeLaPagePublique()['script-src'] ?? [];

        self::assertContains("'self'", $scriptSrc);
        self::assertContains(self::TURNSTILE, $scriptSrc);
        self::assertNotContains("'unsafe-inline'", $scriptSrc, 'Un script injecté ne doit jamais s\'exécuter.');
        self::assertNotContains("'unsafe-eval'", $scriptSrc);
    }

    public function testLeDefiTurnstilePeutSAfficherEtDialoguer(): void
    {
        // Turnstile s'affiche dans une iframe et rappelle son origine : sans ces
        // deux directives, l'anti-abus (#43) casserait silencieusement.
        $directives = $this->directivesDeLaPagePublique();

        self::assertContains(self::TURNSTILE, $directives['frame-src'] ?? []);
        self::assertContains(self::TURNSTILE, $directives['connect-src'] ?? []);
    }

    public function testLesImagesSontLimiteesALOrigineEtAuHttps(): void
    {
        // Le logo du pro pointe vers un hôte public en https (UrlLogo) : on ne peut
        // pas le restreindre davantage tant qu'il n'est pas proxifié. En revanche
        // `data:` reste fermé — c'est un vecteur d'injection.
        $imgSrc = $this->directivesDeLaPagePublique()['img-src'] ?? [];

        self::assertSame(["'self'", 'https:'], $imgSrc);
    }

    public function testLesVecteursClassiquesSontFermes(): void
    {
        $directives = $this->directivesDeLaPagePublique();

        self::assertSame(["'none'"], $directives['object-src'] ?? [], 'Aucun plugin.');
        self::assertSame(["'none'"], $directives['base-uri'] ?? [], 'Pas de réécriture de la base des URL relatives.');
        self::assertSame(["'self'"], $directives['form-action'] ?? [], 'Un formulaire ne poste que chez nous.');
    }

    public function testLaCouleurDeMarqueResteAppliquableSansOuvrirLesBlocsDeStyle(): void
    {
        // La couleur voyage par un attribut `style` (variable CSS), qu'aucun nonce
        // ni hash ne peut couvrir. On autorise donc l'inline **d'attribut** seul :
        // un `<style>` injecté reste interdit. Les navigateurs sans `style-src-attr`
        // retombent sur `style-src` et perdent la couleur — dégradation cosmétique.
        $directives = $this->directivesDeLaPagePublique();

        self::assertSame(["'self'"], $directives['style-src'] ?? []);
        self::assertSame(["'unsafe-inline'"], $directives['style-src-attr'] ?? []);
    }

    public function testLaPageDeResultatEstProtegeeCommeLeFormulaire(): void
    {
        // La CSP ne peut pas couvrir la seule première page : c'est le résultat qui
        // porte le mur de contact, donc le formulaire le plus intéressant à détourner.
        [$client, $token] = $this->preparer();

        $client->request('POST', 'http://'.self::HOTE_WIDGET.'/'.$token.'/estimation', []);

        self::assertNotSame([], $this->directives($client), 'Toute réponse de la zone widget est couverte.');
    }

    public function testLaZoneProNestPasConcerneeParCettePolitique(): void
    {
        // Le back-office pro charge Stimulus et les styles de l'espace : cette
        // politique-ci est taillée pour la page publique, elle ne déborde pas.
        $client = static::createClient();

        $client->followRedirects();
        $client->request('GET', 'http://'.self::HOTE_APP.'/');
        self::assertResponseIsSuccessful();

        self::assertFalse(
            $client->getResponse()->headers->has('Content-Security-Policy'),
            'La politique du widget ne doit pas s\'appliquer à la zone pro.',
        );
    }

    /**
     * @return array<string, list<string>>
     */
    private function directivesDeLaPagePublique(): array
    {
        [$client, $token] = $this->preparer();

        $client->request('GET', 'http://'.self::HOTE_WIDGET.'/'.$token.'/agence-durand');
        self::assertResponseIsSuccessful();

        return $this->directives($client);
    }

    /**
     * @return array<string, list<string>>
     */
    private function directives(KernelBrowser $client): array
    {
        $entete = $client->getResponse()->headers->get('Content-Security-Policy');
        if (null === $entete) {
            return [];
        }

        $directives = [];
        foreach (explode(';', $entete) as $morceau) {
            $mots = preg_split('/\s+/', trim($morceau), flags: \PREG_SPLIT_NO_EMPTY);
            if (false === $mots || [] === $mots) {
                continue;
            }
            $nom = array_shift($mots);
            $directives[$nom] = $mots;
        }

        return $directives;
    }

    /**
     * @return array{0: KernelBrowser, 1: string}
     */
    private function preparer(): array
    {
        $client = static::createClient();
        $conteneur = $client->getContainer();

        $tenants = $conteneur->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $em = $conteneur->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $branding = Branding::creer(Couleur::depuisHex('#0E4A5C'), null, null, null, null, 'Agence Durand');
        $branding->setTenantId($tenantId);
        $em->persist($branding);
        $em->persist(PagePublique::creer($tenantId, $token = bin2hex(random_bytes(5)), 'agence-durand'));
        $em->flush();

        return [$client, $token];
    }
}
