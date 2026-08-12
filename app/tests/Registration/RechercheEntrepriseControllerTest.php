<?php

declare(strict_types=1);

namespace App\Tests\Registration;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RechercheEntrepriseControllerTest extends WebTestCase
{
    private const HOTE = 'valeurverte.localhost';
    private const SIRET_CONNU = '12345678901234';
    private const SIRET_INCONNU = '00000000000000';

    public function testUnSiretConnuRenvoieLaFicheEnJson(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription/entreprise/'.self::SIRET_CONNU);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('content-type', 'application/json');

        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertTrue($charge['trouvee']);
        self::assertSame(self::SIRET_CONNU, $charge['siret']);
        self::assertSame('Renov Habitat', $charge['raisonSociale']);
        self::assertSame('43.29A', $charge['codeNaf']);
        self::assertSame('rge', $charge['statutRge']);
        self::assertContains('Isolation des murs', $charge['domainesRge']);
    }

    public function testUnSiretInconnuRenvoieUn404DeRepli(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription/entreprise/'.self::SIRET_INCONNU);

        self::assertResponseStatusCodeSame(404);
        $charge = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertIsArray($charge);
        self::assertFalse($charge['trouvee']);
    }

    public function testUnSiretMalFormeNeCorrespondPasALaRoute(): void
    {
        $client = static::createClient();

        $client->request('GET', 'http://'.self::HOTE.'/inscription/entreprise/123');

        self::assertResponseStatusCodeSame(404);
    }
}
