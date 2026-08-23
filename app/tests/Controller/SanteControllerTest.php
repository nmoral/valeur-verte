<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Controller\SanteController;
use App\Exploitation\Sante\Controle;
use App\Exploitation\Sante\Sonde;
use App\Exploitation\Sante\VerificateurSante;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Endpoint de santé applicative (#313). Il sert deux publics à la fois : la sonde
 * du conteneur et la surveillance externe, qui ne lisent que le code HTTP, et
 * l'exploitant, qui veut savoir *lequel* des contrôles est tombé.
 */
final class SanteControllerTest extends WebTestCase
{
    private const PUBLIC = 'http://valeurverte.localhost';
    private const APP = 'http://app.valeurverte.localhost';
    private const ADMIN = 'http://admin.valeurverte.localhost';

    public function testToutVaBienRend200EtLeDetailDesControles(): void
    {
        $client = static::createClient();

        $client->request('GET', self::PUBLIC.'/sante');

        self::assertResponseStatusCodeSame(Response::HTTP_OK);
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(
            ['statut' => 'ok', 'controles' => ['base' => 'ok', 'ecriture' => 'ok', 'migrations' => 'ok']],
            json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    public function testLEndpointRepondSansAuthentificationSurLesZonesProtegees(): void
    {
        // La sonde du conteneur et la surveillance externe n'ont pas de session :
        // sur app. et admin., l'endpoint doit répondre, pas rediriger vers /login.
        $client = static::createClient();

        foreach ([self::APP, self::ADMIN] as $zone) {
            $client->request('GET', $zone.'/sante');

            self::assertResponseStatusCodeSame(Response::HTTP_OK, $zone.' doit répondre sans session.');
        }
    }

    public function testLaReponseNeLivreAucunDetailExploitable(): void
    {
        $client = static::createClient();

        $client->request('GET', self::PUBLIC.'/sante');

        // Ouvert sans authentification : la réponse dit « ça va » ou « ça ne va
        // pas », jamais ce qui tourne derrière (version, DSN, trace, chemins).
        $corps = (string) $client->getResponse()->getContent();
        foreach (['Symfony', 'PHP', 'postgres', '/app', 'Exception', 'version'] as $indiscretion) {
            self::assertStringNotContainsStringIgnoringCase($indiscretion, $corps);
        }
    }

    public function testLaReponseNEstPasMiseEnCache(): void
    {
        $client = static::createClient();

        $client->request('GET', self::PUBLIC.'/sante');

        // Un état de santé servi depuis un cache est un mensonge en puissance.
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
    }

    public function testUnControleRougeRend503EtNommeLeControleEnCause(): void
    {
        $reponse = new SanteController()(new VerificateurSante([
            self::sonde(Controle::Base, false),
            self::sonde(Controle::Ecriture, true),
        ]));

        self::assertSame(Response::HTTP_SERVICE_UNAVAILABLE, $reponse->getStatusCode());
        self::assertSame(
            ['statut' => 'hors-service', 'controles' => ['base' => 'ko', 'ecriture' => 'ok']],
            json_decode((string) $reponse->getContent(), true, flags: \JSON_THROW_ON_ERROR),
        );
    }

    private static function sonde(Controle $controle, bool $verdict): Sonde
    {
        return new class($controle, $verdict) implements Sonde {
            public function __construct(
                private readonly Controle $controle,
                private readonly bool $verdict,
            ) {
            }

            public function controle(): Controle
            {
                return $this->controle;
            }

            public function verifier(): bool
            {
                return $this->verdict;
            }
        };
    }
}
