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
        $pages = $em->getRepository(PagePublique::class)->findBy(['tenantId' => $tenantId]);

        self::assertCount(1, $pages);

        return $pages[0];
    }
}
