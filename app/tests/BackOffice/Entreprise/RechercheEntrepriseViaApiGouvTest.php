<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\RechercheEntrepriseViaApiGouv;
use App\BackOffice\Entreprise\Siret;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class RechercheEntrepriseViaApiGouvTest extends TestCase
{
    private const SIRET = '12345678901234';

    public function testUneReponseValideProduitLIdentite(): void
    {
        $urlDemandee = null;
        $client = new MockHttpClient(function (string $methode, string $url) use (&$urlDemandee): MockResponse {
            $urlDemandee = $url;

            return new MockResponse((string) json_encode($this->resultatGouv()));
        });

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNotNull($identite);
        self::assertStringContainsString(self::SIRET, (string) $urlDemandee);
        self::assertSame('Renov Habitat', $identite->raisonSociale());
        self::assertSame('12 RUE DES LILAS 31000 TOULOUSE', $identite->adresse());
        self::assertSame('43.29A', $identite->codeNaf());
        self::assertSame('6 à 9 salariés', $identite->effectif());
    }

    public function testUnSiretInconnuDonneNull(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode(['results' => [], 'total_results' => 0])));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNull($identite);
    }

    public function testUnEtablissementFermeDonneNull(): void
    {
        $resultat = $this->resultatGouv();
        $resultat['results'][0]['matching_etablissements'][0]['etat_administratif'] = 'F';
        $client = new MockHttpClient(new MockResponse((string) json_encode($resultat)));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNull($identite, 'Un établissement fermé bascule en saisie manuelle.');
    }

    public function testUneApiIndisponibleDonneNullSansBloquer(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => throw new TransportException('timeout'));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNull($identite, 'Une API muette ne doit jamais bloquer : repli manuel.');
    }

    public function testUnEffectifNonRenseigneResteNull(): void
    {
        $resultat = $this->resultatGouv();
        unset($resultat['results'][0]['tranche_effectif_salarie']);
        $client = new MockHttpClient(new MockResponse((string) json_encode($resultat)));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNotNull($identite);
        self::assertNull($identite->effectif());
    }

    /**
     * @return array<string, mixed>
     */
    private function resultatGouv(): array
    {
        return [
            'results' => [
                [
                    'siren' => '123456789',
                    'nom_complet' => 'RENOV HABITAT SARL',
                    'nom_raison_sociale' => 'Renov Habitat',
                    'activite_principale' => '43.29A',
                    'tranche_effectif_salarie' => '03',
                    'matching_etablissements' => [
                        [
                            'siret' => self::SIRET,
                            'adresse' => '12 RUE DES LILAS 31000 TOULOUSE',
                            'etat_administratif' => 'A',
                        ],
                    ],
                ],
            ],
            'total_results' => 1,
        ];
    }
}
