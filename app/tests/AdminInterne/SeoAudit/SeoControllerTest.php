<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\RapportAuditSeo;
use App\AdminInterne\SeoAudit\RapportSeo;
use App\AdminInterne\SeoAudit\RapportSeoRepository;
use App\AdminInterne\SeoAudit\ResultatPage;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * L'écran admin de l'audit SEO (#345) : dernier rapport, liste historisée et
 * fiche de détail par URL/règle. Zone « admin. », ROLE_ADMIN + second facteur.
 */
final class SeoControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPageExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageAfficheLeDernierRapportEtSaListe(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'SEO');
        self::assertSelectorExists('tbody tr');
        // Score de l'audit d'exemple (1 alerte + 1 erreur → 56/100).
        self::assertSelectorTextContains('body', '56');
    }

    public function testLaFicheDetailleUnRapport(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);
        $id = $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'https://a.test/');
        self::assertSelectorTextContains('body', 'robots-txt');
    }

    public function testUneFicheInconnueRend404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo/999999');

        self::assertResponseStatusCodeSame(404);
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
        $this->passerLeSecondFacteur($client, $admin);
    }

    private function semer(): int
    {
        $repository = self::getContainer()->get(RapportSeoRepository::class);
        self::assertInstanceOf(RapportSeoRepository::class, $repository);

        $audit = new RapportAuditSeo(
            [new ResultatPage('https://a.test/', 200, [
                Constat::ok('titre', 'Titre de 30 caractères.'),
                Constat::alerte('canonical', 'Aucune balise canonical.'),
            ])],
            [Constat::erreur('robots-txt', 'robots.txt inaccessible (code HTTP 404).')],
        );
        $rapport = RapportSeo::depuis($audit, new \DateTimeImmutable('2026-08-25 04:00:00'));
        $repository->enregistrer($rapport);

        $id = $rapport->id();
        self::assertIsInt($id);

        return $id;
    }
}
