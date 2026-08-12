<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class HomeControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLaHomePubliqueRepondEtPorteLeDiscours(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Montrez-leur la valeur');
        self::assertSelectorExists('.proof');
    }

    public function testLaHomeOffreConnexionEtCreationDeCompte(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseIsSuccessful();

        $connexion = $crawler->filter('nav a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Connexion'),
        );
        self::assertGreaterThan(0, $connexion->count(), 'Un lien « Connexion » est attendu dans la nav.');
        self::assertSame('/login', $connexion->attr('href'));

        $creation = $crawler->filter('a')->reduce(
            static fn ($node): bool => str_contains($node->text(), 'Créer un compte'),
        );
        self::assertGreaterThan(0, $creation->count(), 'Un bouton « Créer un compte » est attendu.');
    }

    public function testLaHomeEstPubliqueSansAuthentification(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/');

        self::assertResponseStatusCodeSame(200);
    }
}
