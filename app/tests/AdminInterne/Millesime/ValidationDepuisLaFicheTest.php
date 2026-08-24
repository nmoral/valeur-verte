<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\Millesime;

use App\Security\User;
use App\Security\UserRepository;
use App\Tests\Security\DeuxFacteurs\Double\PasseLeSecondFacteur;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Valider une maille depuis sa fiche de diagnostic (#130).
 *
 * La fiche est l'endroit où l'on constate *pourquoi* une maille a été écartée : c'est
 * donc là que la décision de la publier quand même doit se prendre, sans repasser par
 * la liste. L'action reste soumise à la même garde — le millésime doit être publié.
 */
final class ValidationDepuisLaFicheTest extends WebTestCase
{
    use PasseLeSecondFacteur;

    use ProvisionneSchemaData;

    private const string HOTE_ADMIN = 'admin.valeurverte.localhost';

    public function testLaFicheDUneMailleEcarteeOffreLaValidationQuandLeMillesimeEstPublie(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);
        $this->publier(2024);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31003');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('form[action="/millesimes/2024/mailles/31003/valider"]');
    }

    public function testSansPublicationDuMillesimeLaFicheExpliqueAuLieuDOffrirLeBouton(): void
    {
        // Publier une maille seule sur un millésime non publié créerait un parc
        // mixte : plutôt que de laisser cliquer pour se prendre une erreur, on dit
        // ce qu'il faut faire avant.
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31003');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/millesimes/2024/mailles/31003/valider"]');
        self::assertSelectorTextContains('body', 'publier le millésime');
    }

    public function testUneMailleNonEcarteeNOffrePasDeValidation(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);
        $this->publier(2024);

        $client->request('GET', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31001');

        self::assertResponseIsSuccessful();
        self::assertSelectorNotExists('form[action="/millesimes/2024/mailles/31001/valider"]');
    }

    public function testValiderDepuisLaFicheSertLaMailleEtRevientSurLaFiche(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);
        $this->publier(2024);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31003/valider');

        self::assertResponseRedirects('http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31003');
        $servies = $this->app()->fetchFirstColumn('SELECT code_insee FROM data.coefficient_maille');
        self::assertContains('31003', array_map(static fn (mixed $c): string => \is_string($c) ? trim($c) : '', $servies));
    }

    public function testValiderAvantPublicationEstRefuseAvecUnMessageExploitable(): void
    {
        $client = static::createClient();
        $this->provisionner();
        $this->connecterAdmin($client);

        $client->request('POST', 'http://'.self::HOTE_ADMIN.'/millesimes/2024/mailles/31003/valider');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('[role="alert"]', 'pas publie');
    }

    private function publier(int $millesime): void
    {
        $decision = self::getContainer()->get(\App\AdminInterne\Millesime\DecisionMillesime::class);
        self::assertInstanceOf(\App\AdminInterne\Millesime\DecisionMillesime::class, $decision);
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
