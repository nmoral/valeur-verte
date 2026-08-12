<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\RechercheEntrepriseViaApiGouv;
use App\BackOffice\Entreprise\Siret;
use PHPUnit\Framework\Attributes\DataProvider;
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
        self::assertStringContainsString('page=1', (string) $urlDemandee);
        self::assertStringContainsString('per_page=1', (string) $urlDemandee);
        self::assertSame('Renov Habitat', $identite->raisonSociale());
        self::assertSame('12 RUE DES LILAS 31000 TOULOUSE', $identite->adresse());
        self::assertSame('43.29A', $identite->codeNaf());
        self::assertSame('6 à 9 salariés', $identite->effectif());
    }

    #[DataProvider('tranchesEffectif')]
    public function testLaTrancheEffectifEstTraduiteEnLibelle(string $code, ?string $attendu): void
    {
        $resultat = $this->resultatGouv();
        $resultat['results'][0]['tranche_effectif_salarie'] = $code;
        $client = new MockHttpClient(new MockResponse((string) json_encode($resultat)));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNotNull($identite);
        self::assertSame($attendu, $identite->effectif());
    }

    /**
     * @return iterable<string, array{string, string|null}>
     */
    public static function tranchesEffectif(): iterable
    {
        yield '00' => ['00', '0 salarié'];
        yield '01' => ['01', '1 ou 2 salariés'];
        yield '02' => ['02', '3 à 5 salariés'];
        yield '03' => ['03', '6 à 9 salariés'];
        yield '11' => ['11', '10 à 19 salariés'];
        yield '12' => ['12', '20 à 49 salariés'];
        yield '21' => ['21', '50 à 99 salariés'];
        yield '22' => ['22', '100 à 199 salariés'];
        yield '31' => ['31', '200 à 249 salariés'];
        yield '32' => ['32', '250 à 499 salariés'];
        yield '41' => ['41', '500 à 999 salariés'];
        yield '42' => ['42', '1 000 à 1 999 salariés'];
        yield '51' => ['51', '2 000 à 4 999 salariés'];
        yield '52' => ['52', '5 000 à 9 999 salariés'];
        yield '53' => ['53', '10 000 salariés et plus'];
        yield 'code inconnu' => ['NN', null];
    }

    public function testUnStatutHttpNon200DonneNull(): void
    {
        $client = new MockHttpClient(new MockResponse((string) json_encode($this->resultatGouv()), ['http_code' => 500]));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNull($identite, 'Un statut non 200 dégrade vers la saisie manuelle.');
    }

    public function testLIdentitePeutVenirDuSiegeQuandAucunEtablissementNeMatche(): void
    {
        $reponse = [
            'results' => [
                [
                    'nom_raison_sociale' => 'Renov Habitat',
                    'activite_principale' => '43.29A',
                    'matching_etablissements' => [],
                    'siege' => ['siret' => self::SIRET, 'adresse' => 'Siège 31000 Toulouse', 'etat_administratif' => 'A'],
                ],
            ],
            'total_results' => 1,
        ];
        $client = new MockHttpClient(new MockResponse((string) json_encode($reponse)));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNotNull($identite);
        self::assertSame('Siège 31000 Toulouse', $identite->adresse());
    }

    public function testSeulLEtablissementDuSiretDemandeEstRetenu(): void
    {
        $resultat = $this->resultatGouv();
        array_unshift($resultat['results'][0]['matching_etablissements'], [
            'siret' => '99999999999999',
            'adresse' => 'Mauvaise adresse',
            'etat_administratif' => 'A',
        ]);
        $client = new MockHttpClient(new MockResponse((string) json_encode($resultat)));

        $identite = (new RechercheEntrepriseViaApiGouv($client))->parSiret(Siret::depuis(self::SIRET));

        self::assertNotNull($identite);
        self::assertSame('12 RUE DES LILAS 31000 TOULOUSE', $identite->adresse());
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
     * @return array{results: list<array{
     *     siren: string,
     *     nom_complet: string,
     *     nom_raison_sociale: string,
     *     activite_principale: string,
     *     tranche_effectif_salarie?: string,
     *     matching_etablissements: list<array{siret: string, adresse: string, etat_administratif: string}>,
     * }>, total_results: int}
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
