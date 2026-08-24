<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\AdminInterne\Millesime\DecisionMillesime;
use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Liste des mailles écartées (REG-1/#130) et déblocage depuis cette liste.
 *
 * Deux pièges à éviter, tous deux vécus comme « il ne se passe rien » :
 *  - proposer le bouton alors que le millésime n'est pas publié (le déblocage est
 *    alors refusé côté SQL) ;
 *  - laisser une maille débloquée dans la liste (le déblocage a réussi mais la
 *    ligne reste, faute d'être exclue une fois servie).
 */
final class MaillesBloqueesListeTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    use ProvisionneSchemaData;

    private const string HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testSansPublicationLeBoutonEstRemplaceParUneConsigne(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/bloquees');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/millesimes/2024/bloquees/31003/debloquer"]');
        self::assertSelectorTextContains('body', 'publier le millésime');
    }

    public function testLeBoutonDeDeblocageApparaitQuandLeMillesimeEstPublie(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);
        $this->publier(2024);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/bloquees');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/millesimes/2024/bloquees/31003/debloquer"]');
    }

    public function testUneMailleDebloqueeDisparaitDeLaListe(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);
        $this->publier(2024);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/bloquees/31003/debloquer');
        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/millesimes/2024/bloquees');
        $client->followRedirect();

        // La seule maille bloquée ayant été mise en service, la liste est vide.
        self::assertSelectorTextContains('.liste-table', 'Aucune maille écartée');
        self::assertSelectorNotExists('form[action="/millesimes/2024/bloquees/31003/debloquer"]');
    }

    public function testUnDeblocageRefuseAfficheUneErreurVisible(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);

        // Millésime non publié : la garde SQL refuse. Le message doit être visible
        // (bandeau d'erreur), pas un texte nu qui passe inaperçu.
        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/bloquees/31003/debloquer');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.banner-bad');
        self::assertSelectorTextContains('[role="alert"]', 'pas publie');
    }

    private function publier(int $millesime): void
    {
        $decision = self::getContainer()->get(DecisionMillesime::class);
        self::assertInstanceOf(DecisionMillesime::class, $decision);
        $decision->publier($millesime, 'admin@example.com');
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
}
