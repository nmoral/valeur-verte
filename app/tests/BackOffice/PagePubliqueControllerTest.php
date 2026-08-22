<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\Security\CreateurDeCompte;
use App\Security\User;
use App\Security\UserRepository;
use App\Tenant\TenantRepository;
use App\Widget\PagePublique;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Écran « Ma page publique » de l'espace pro (#38, épic #6, décision D9).
 *
 * Le pro n'intègre plus rien chez lui (fin de l'iframe et de la liste blanche de
 * domaines, D9) : il **partage un lien**. L'écran ne sert donc qu'à ça — donner
 * l'adresse en clair, dire si elle est en ligne, et renvoyer vers la marque pour
 * l'apparence, qui se configure ailleurs et ne se duplique pas ici.
 */
final class PagePubliqueControllerTest extends WebTestCase
{
    private const string HOTE_PRO = 'app.valeurverte.localhost';
    private const string HOTE_WIDGET = 'widget.valeurverte.localhost';

    public function testLEcranAfficheLeLienPartageableDeLaPage(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('nav.side a.on', 'Ma page publique');
        $page = $this->pageDuTenant($tenantId);
        $lien = \sprintf('https://widget.valeurverte.localhost/%s/%s', $page->token(), $page->slug());
        // Le lien se copie : il doit être lisible en clair, pas seulement cliquable.
        self::assertSelectorExists(\sprintf('input[value="%s"]', $lien));
        self::assertSelectorExists('[data-copier]');
    }

    public function testLEcranDitQueLaPageEstEnLigne(): void
    {
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-page-en-ligne@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertSelectorTextContains('[data-etat]', 'en ligne');
    }

    public function testLEcranRappelleQueLaPagePubliqueNeConsommePasLEssai(): void
    {
        // D9 §3 : seul un audit consomme le quota. Sans ce rappel, un pro peut
        // hésiter à diffuser son lien de peur de brûler son essai.
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-page-quota@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertSelectorTextContains('body', 'ne consomme pas');
    }

    public function testLEcranRenvoieVersLaMarquePourLApparence(): void
    {
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-page-marque@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        // L'apparence se configure sur l'écran Marque : on y renvoie plutôt que
        // d'en dupliquer la configuration ici.
        self::assertSelectorExists('a[href="/marque"]');
    }

    public function testUnCompteAnterieurALaFonctionnaliteObtientSaPageALaPremiereVisite(): void
    {
        // Les comptes créés avant #38 n'ont pas de page : l'écran leur en ouvre
        // une plutôt que de leur montrer un trou.
        $client = static::createClient();
        $tenantId = $this->connecterArtisanSansProvisionnement($client, 'artisan-page-ancien@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertResponseIsSuccessful();
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $this->pageDuTenant($tenantId)->token());
    }

    public function testLeProRenommeLeSlugDeSonLien(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-slug@example.com');
        $jeton = $this->pageDuTenant($tenantId)->token();

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', ['slug' => 'Agence Durand']);

        self::assertResponseRedirects('/page-publique');
        $page = $this->pageDuTenant($tenantId);
        // Le slug voyage dans une URL : ce que le pro saisit y est rendu lisible.
        self::assertSame('agence-durand', $page->slug());
        // Renommer n'est pas déménager : le jeton, lui, ne bouge pas.
        self::assertSame($jeton, $page->token());
    }

    public function testUnSlugVideEstRefuseAvecUnMessageEtNeCasseRienDuLien(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-slug-vide@example.com');
        $avant = $this->pageDuTenant($tenantId)->slug();

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', ['slug' => '   ']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[data-erreur]', 'slug');
        self::assertSame($avant, $this->pageDuTenant($tenantId)->slug(), 'Rien n\'a été enregistré.');
    }

    public function testUnSlugTropLongEstRefuseAuLieuDePartirEnErreurSql(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-slug-long@example.com');
        $avant = $this->pageDuTenant($tenantId)->slug();

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', [
            'slug' => str_repeat('a', PagePublique::LONGUEUR_MAX_SLUG + 1),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame($avant, $this->pageDuTenant($tenantId)->slug());
    }

    public function testDeuxProsPeuventPorterLeMemeSlug(): void
    {
        // Le slug est décoratif : il n'entre pas dans la résolution (#40), donc
        // rien n'oblige deux agences homonymes à se disputer un nom.
        $client = static::createClient();
        $premier = $this->connecterArtisan($client, 'artisan-page-homonyme-1@example.com');
        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', ['slug' => 'agence-durand']);

        $second = $this->connecterArtisan($client, 'artisan-page-homonyme-2@example.com');
        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', ['slug' => 'agence-durand']);

        self::assertResponseRedirects('/page-publique');
        self::assertSame('agence-durand', $this->pageDuTenant($premier)->slug());
        self::assertSame('agence-durand', $this->pageDuTenant($second)->slug());
    }

    public function testRetirerLaPageLaFaitDisparaitreDuVisiteur(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-retrait@example.com');
        $lien = $this->cheminPublic($tenantId);

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/retirer');

        self::assertResponseRedirects('/page-publique');
        self::assertFalse($this->pageDuTenant($tenantId)->estActive());

        $client->request('GET', 'http://'.self::HOTE_WIDGET.$lien);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLEcranDitQueLaPageEstRetiree(): void
    {
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-page-etat-retire@example.com');

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/retirer');
        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertSelectorTextContains('[data-etat]', 'retirée');
    }

    public function testRemettreEnLigneRendLaPageAuVisiteur(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-retour@example.com');
        $lien = $this->cheminPublic($tenantId);
        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/retirer');

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/remettre-en-ligne');

        self::assertResponseRedirects('/page-publique');
        self::assertTrue($this->pageDuTenant($tenantId)->estActive());

        $client->request('GET', 'http://'.self::HOTE_WIDGET.$lien);
        self::assertResponseIsSuccessful();
    }

    public function testLeProNeLevePasUneSuspensionAdminEnRebasculantSonInterrupteur(): void
    {
        // Les deux verrous sont indépendants (#268) : rebasculer le sien ne doit
        // pas rendre au visiteur une page coupée par l'admin.
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-suspendue@example.com');
        $lien = $this->cheminPublic($tenantId);
        $this->suspendre($tenantId);

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/retirer');
        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/remettre-en-ligne');

        self::assertTrue($this->pageDuTenant($tenantId)->estSuspendue());
        $client->request('GET', 'http://'.self::HOTE_WIDGET.$lien);
        self::assertResponseStatusCodeSame(404);
    }

    public function testRegenererLeLienInvalideImmediatementLAncienneAdresse(): void
    {
        // Fuite du lien, fin de contrat avec un apporteur : le pro doit pouvoir
        // couper l'ancienne adresse sans attendre.
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-regen@example.com');
        $ancien = $this->cheminPublic($tenantId);
        $ancienJeton = $this->pageDuTenant($tenantId)->token();

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/regenerer');

        self::assertResponseRedirects('/page-publique');
        $page = $this->pageDuTenant($tenantId);
        self::assertNotSame($ancienJeton, $page->token());
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $page->token());

        $client->request('GET', 'http://'.self::HOTE_WIDGET.$ancien);
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaNouvelleAdresseSertLaPageEtGardeLeSlug(): void
    {
        $client = static::createClient();
        $tenantId = $this->connecterArtisan($client, 'artisan-page-regen-slug@example.com');
        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/slug', ['slug' => 'agence-durand']);

        $client->request('POST', 'http://'.self::HOTE_PRO.'/page-publique/regenerer');

        self::assertResponseRedirects('/page-publique');
        // Régénérer, c'est changer d'adresse, pas de nom : le slug survit.
        self::assertSame('agence-durand', $this->pageDuTenant($tenantId)->slug());
        $client->request('GET', 'http://'.self::HOTE_WIDGET.$this->cheminPublic($tenantId));
        self::assertResponseIsSuccessful();
    }

    public function testLaRegenerationDemandeUneConfirmation(): void
    {
        // Elle casse tous les liens déjà diffusés, et rien ne les rétablit :
        // elle ne doit pas partir sur un clic distrait.
        $client = static::createClient();
        $this->connecterArtisan($client, 'artisan-page-regen-confirm@example.com');

        $client->request('GET', 'http://'.self::HOTE_PRO.'/page-publique');

        self::assertSelectorExists('form[action="/page-publique/regenerer"] [data-confirmer]');
    }

    private function cheminPublic(int $tenantId): string
    {
        $page = $this->pageDuTenant($tenantId);

        return \sprintf('/%s/%s', $page->token(), $page->slug());
    }

    private function suspendre(int $tenantId): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $this->pageDuTenant($tenantId);
        $page = $em->getRepository(PagePublique::class)->findOneBy(['tenantId' => $tenantId]);
        self::assertInstanceOf(PagePublique::class, $page);
        $page->suspendre(new \DateTimeImmutable('2026-08-22 10:00:00'));
        $em->flush();
    }

    private function connecterArtisan(KernelBrowser $client, string $email): int
    {
        $createur = self::getContainer()->get(CreateurDeCompte::class);
        self::assertInstanceOf(CreateurDeCompte::class, $createur);
        $user = $createur->creer($email, 'motdepasse-solide', '2026-08-16');
        $client->loginUser($user);
        $tenantId = $user->getTenantId();
        self::assertNotNull($tenantId);

        return $tenantId;
    }

    /**
     * Un compte tel qu'il existait avant #38 : un tenant, un utilisateur, aucune
     * page publique.
     */
    private function connecterArtisanSansProvisionnement(KernelBrowser $client, string $email): int
    {
        $tenants = self::getContainer()->get(TenantRepository::class);
        self::assertInstanceOf(TenantRepository::class, $tenants);
        $tenantId = $tenants->nouveau()->id();
        self::assertIsInt($tenantId);

        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        $user = new User($email, ['ROLE_PRO']);
        $user->rattacherAuTenant($tenantId);
        $user->definirMotDePasse('peu-importe');
        $utilisateurs->enregistrer($user);
        $client->loginUser($user);

        return $tenantId;
    }

    private function pageDuTenant(int $tenantId): PagePublique
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $em->clear();
        // La requête vient d'armer le cloisonnement sur le pro connecté : pour
        // relire la page d'un *autre* tenant, le test doit le suspendre.
        $filtres = $em->getFilters();
        $cloisonne = $filtres->isEnabled('tenant');
        if ($cloisonne) {
            $filtres->suspend('tenant');
        }

        try {
            $pages = $em->getRepository(PagePublique::class)->findBy(['tenantId' => $tenantId]);
        } finally {
            if ($cloisonne) {
                $filtres->restore('tenant');
            }
        }

        self::assertCount(1, $pages);

        return $pages[0];
    }
}
