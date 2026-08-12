<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use App\Security\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final class RateLimitInscriptionTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';

    public function testLInscriptionEstBloqueeQuandLaFenetreEstEpuisee(): void
    {
        $client = static::createClient();
        $ip = '203.0.113.7';

        // Épuise la fenêtre de cette IP (le stockage disque du limiteur survit
        // aux reboots du client entre les requêtes). reset() isole des autres runs.
        $fabrique = self::getContainer()->get('limiter.inscription_ip');
        self::assertInstanceOf(RateLimiterFactory::class, $fabrique);
        $limiteur = $fabrique->create($ip);
        $limiteur->reset();
        while ($limiteur->consume(1)->isAccepted()) {
        }

        $client->setServerParameter('REMOTE_ADDR', $ip);
        $crawler = $client->request('GET', 'http://'.self::HOTE.'/inscription');
        $formulaire = $crawler->selectButton('Créer mon compte')->form();
        $formulaire['email'] = 'bloque@example.com';
        $formulaire['password'] = 'motdepasse-solide';
        $formulaire['siret'] = '';
        $client->submit($formulaire);

        self::assertResponseStatusCodeSame(429);

        $utilisateurs = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $utilisateurs);
        self::assertNull(
            $utilisateurs->findOneBy(['email' => 'bloque@example.com']),
            'Aucun compte ne doit être créé quand la limite est atteinte.',
        );
    }
}
