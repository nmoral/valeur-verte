<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

final class RegistrationControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLaPageInscriptionEstPubliqueEtPorteLeFormulaire(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('input[name="email"]');
        self::assertSelectorExists('input[name="password"]');
        self::assertSelectorExists('input[name="siret"]');
    }

    public function testUneInscriptionValideCreeLeCompteEtConnecte(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'nouveau@example.com', 'motdepasse-solide', '12345678901234');

        self::assertResponseRedirects('http://app.'.self::HOTE.'/');

        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        $user = $repository->findOneBy(['email' => 'nouveau@example.com']);
        self::assertNotNull($user, 'Le compte doit être créé.');
        self::assertContains('ROLE_PRO', $user->getRoles());

        $client->followRedirect();
        self::assertSelectorTextContains('h1', 'Espace pro');
    }

    public function testUnEmailDejaUtiliseEstRefuse(): void
    {
        $client = static::createClient();
        $this->soumettreInscription($client, 'doublon@example.com', 'motdepasse-solide', '12345678901234');

        $this->soumettreInscription($client, 'doublon@example.com', 'un-autre-mot-de-passe', '12345678901234');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorExists('.form-error');
    }

    public function testUnMotDePasseTropCourtEstRefuse(): void
    {
        $client = static::createClient();

        $this->soumettreInscription($client, 'court@example.com', '123', '12345678901234');

        self::assertResponseStatusCodeSame(422);
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);
        self::assertNull($repository->findOneBy(['email' => 'court@example.com']));
    }

    private function soumettreInscription(KernelBrowser $client, string $email, string $motDePasse, string $siret): void
    {
        $crawler = $client->request('GET', 'http://'.self::HOTE.'/inscription');
        self::assertInstanceOf(Crawler::class, $crawler);
        $formulaire = $crawler->selectButton('Créer mon compte')->form();
        $formulaire['email'] = $email;
        $formulaire['password'] = $motDePasse;
        $formulaire['siret'] = $siret;
        $client->submit($formulaire);
    }
}
