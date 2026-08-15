<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Aide;

use App\AdminInterne\JournalAudit\JournalAudit;
use App\BackOffice\Aide\BaremeAidesRepository;
use App\BackOffice\Aide\CategorieRevenu;
use App\Security\User;
use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Édition du barème d'aides depuis l'admin interne (#26, épic #7).
 *
 * Le formulaire porte une **date d'effet** : valider ne modifie pas le barème en
 * place, il en publie une nouvelle version et clôture la précédente. Tout
 * changement est tracé au journal d'audit (#50). Zone « admin. », ROLE_ADMIN.
 */
final class BaremeAidesControllerTest extends WebTestCase
{
    private const string HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLEditionDuBaremeExigeUneAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/baremes-aides');

        self::assertResponseRedirects('/login');
    }

    public function testLaPageAfficheLeBaremeCourant(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/baremes-aides');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', "Barème d'aides");
        self::assertSelectorExists('input[name="taux_tres_modeste"][value="80"]');
        self::assertSelectorExists('input[name="plafond_2_classes"][value="30000"]');
        self::assertSelectorExists('input[name="date_effet"]');
    }

    public function testPublierUnBaremeLeMetEnVigueurADaDateDEffetEtRedirige(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire('2026-09-01'));

        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/baremes-aides');
        $publie = $this->baremes()->enVigueurLe(new \DateTimeImmutable('2026-09-01'));
        self::assertSame(70, $publie->tauxPourCent(CategorieRevenu::TresModeste));
        self::assertSame(25000, $publie->plafondDepenses(2)->euros());
    }

    public function testUnBaremePublieNEntrePasEnVigueurAvantSaDateDEffet(): void
    {
        // Tout l'intérêt de la date d'effet : on prépare le barème à l'avance sans
        // que les rapports du jour changent de reste à charge.
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire('2026-09-01'));

        $veille = $this->baremes()->enVigueurLe(new \DateTimeImmutable('2026-08-31'));
        self::assertSame(80, $veille->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testLaPublicationEstTraceeAuJournalAvecLAncienEtLeNouveau(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire());

        $evenements = $this->journal()->evenementsRecents(10);
        $publication = null;
        foreach ($evenements as $evenement) {
            if ('bareme_aides.publie' === $evenement->action()) {
                $publication = $evenement;
            }
        }

        self::assertNotNull($publication, 'La publication doit être inscrite au journal d\'audit.');
        self::assertSame('admin@example.com', $publication->acteur());
        $contexte = $publication->contexte();
        self::assertArrayHasKey('ancien', $contexte);
        self::assertArrayHasKey('nouveau', $contexte);
        self::assertArrayHasKey('date_effet', $contexte);
    }

    public function testUnBaremeInvalideEstRefuseSansRienPublier(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', [
            ...$this->formulaire(),
            'taux_tres_modeste' => '150',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame(80, $this->baremes()->enVigueurLe(new \DateTimeImmutable('2026-09-01'))->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testUneDateDEffetRetroactiveEstRefusee(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);
        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire('2026-09-01'));

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire('2026-03-01'));

        self::assertResponseStatusCodeSame(422);
        self::assertSame(70, $this->baremes()->enVigueurLe(new \DateTimeImmutable('2026-09-01'))->tauxPourCent(CategorieRevenu::TresModeste));
    }

    public function testUnPostAsyncValideRenvoieUnJsonOk(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', $this->formulaire());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertTrue($charge['ok']);
    }

    public function testUnPostAsyncInvalideRenvoieUnJson422(): void
    {
        $client = static::createClient();
        $this->connecterAdmin($client);

        $client->xmlHttpRequest('POST', 'http://'.self::HOTE_ADMIN.'/baremes-aides', [
            ...$this->formulaire(),
            'plafond_2_classes' => '-1',
        ]);

        self::assertResponseStatusCodeSame(422);
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertFalse($charge['ok']);
        self::assertArrayHasKey('erreur', $charge);
    }

    /**
     * @return array<string, string>
     */
    private function formulaire(string $dateEffet = '2026-09-01'): array
    {
        return [
            'date_effet' => $dateEffet,
            'taux_tres_modeste' => '70',
            'taux_modeste' => '55',
            'taux_intermediaire' => '40',
            'taux_superieur' => '5',
            'plafond_2_classes' => '25000',
            'plafond_3_classes' => '35000',
            'plafond_eco_ptz' => '50000',
            'taux_tva_pour_mille' => '55',
        ];
    }

    private function connecterAdmin(KernelBrowser $client): void
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $admin = new User('admin@example.com', ['ROLE_ADMIN']);
        $repository->enregistrer($admin);
        $client->loginUser($admin);
    }

    private function baremes(): BaremeAidesRepository
    {
        $repository = self::getContainer()->get(BaremeAidesRepository::class);
        self::assertInstanceOf(BaremeAidesRepository::class, $repository);

        return $repository;
    }

    private function journal(): JournalAudit
    {
        $journal = self::getContainer()->get(JournalAudit::class);
        self::assertInstanceOf(JournalAudit::class, $journal);

        return $journal;
    }
}
