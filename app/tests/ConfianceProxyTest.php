<?php

declare(strict_types=1);

namespace App\Tests;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Derrière le reverse proxy (TLS terminé par Caddy, dialogue app en HTTP), Symfony
 * ne doit pas générer d'URL en http:// : sans confiance au proxy, l'en-tête
 * X-Forwarded-Proto est ignoré et la connexion redirige vers http://…/login.
 */
final class ConfianceProxyTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testUneRequeteTransmiseEnHttpsEstVueCommeSecurisee(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/', [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertResponseIsSuccessful();
        self::assertTrue(
            $client->getRequest()->isSecure(),
            'Une requête transmise par le proxy en https doit être vue comme sécurisée.',
        );
    }

    public function testLesUrlsAbsoluesSontGenereesEnHttpsDerriereLeProxy(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', 'http://'.self::HOTE.'/', [], [], [
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);

        self::assertResponseIsSuccessful();
        $canonical = (string) $crawler->filter('link[rel="canonical"]')->attr('href');
        self::assertStringStartsWith(
            'https://',
            $canonical,
            'Le lien canonique (URL absolue) doit être généré en https derrière le proxy.',
        );
    }
}
