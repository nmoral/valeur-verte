<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\EtatIndexation;
use App\AdminInterne\SeoPresence\MetriquesRecherche;
use App\AdminInterne\SeoPresence\RapportPresence;
use App\AdminInterne\SeoPresence\RapportPresenceExterne;
use App\AdminInterne\SeoPresence\RapportPresenceRepository;
use App\AdminInterne\SeoPresence\RequeteTop;
use App\AdminInterne\SeoPresence\WebVitals;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Section « présence externe » de l'écran admin /seo (#346) : dernier snapshot,
 * tendance et fiche de détail (requêtes, indexation, Core Web Vitals).
 */
final class SeoPresenceControllerTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    private const HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaPagePresenteLaSectionMemeSansCollecte(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Présence externe');
        self::assertSelectorTextContains('body', 'non configur');
    }

    public function testLaSectionAfficheLeDernierSnapshot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);
        $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Présence externe');
        self::assertSelectorTextContains('body', '1 234'); // clics formatés
    }

    public function testLaFicheDetailleUnSnapshot(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);
        $id = $this->semer();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo/presence/'.$id);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'valeur verte maison');
        self::assertSelectorTextContains('body', 'Submitted and indexed');
    }

    public function testUneFicheInconnueRend404(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo/presence/999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testLaFicheExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/seo/presence/1');

        self::assertResponseRedirects('/login');
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
        $repository = self::getContainer()->get(RapportPresenceRepository::class);
        self::assertInstanceOf(RapportPresenceRepository::class, $repository);

        $audit = new RapportPresenceExterne(
            true,
            new MetriquesRecherche(1234, 20000, 0.0617, 7.2, [new RequeteTop('valeur verte maison', 800, 10000)]),
            [new EtatIndexation('https://valeur-verte.fr/', 'Submitted and indexed')],
            [new WebVitals('https://valeur-verte.fr/', 2100, 0.05, 180, 92)],
        );
        $rapport = RapportPresence::depuis($audit, new \DateTimeImmutable('2026-08-25 01:30:00'));
        $repository->enregistrer($rapport);

        $id = $rapport->id();
        self::assertIsInt($id);

        return $id;
    }
}
