<?php

declare(strict_types=1);

namespace App\Tests\Widget;

use App\Widget\RechercheAdresseViaBan;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Résolution d'une adresse en code INSEE via la Base Adresse Nationale (#287).
 *
 * L'appel part de **notre serveur**, jamais du navigateur du visiteur : la page
 * publique annonce une collecte anonyme et sans tiers, et la CSP de la zone
 * `widget.` ({@see \App\Widget\CspWidget}) garde `connect-src 'self'`.
 *
 * La BAN est une source publique et faillible : toute défaillance (statut non 200,
 * JSON illisible, réseau coupé) dégrade vers une liste vide. Une suggestion sans
 * code INSEE est écartée — elle ne mènerait à aucun chiffre, la maille du moteur
 * étant la commune.
 */
final class RechercheAdresseViaBanTest extends TestCase
{
    public function testLesSuggestionsDeLaBanPortentLeurLibelleEtLeurCodeInsee(): void
    {
        $recherche = new RechercheAdresseViaBan($this->ban([
            $this->feature('12 Rue des Lilas 31000 Toulouse', '31555'),
            $this->feature('12 Rue des Lilas 31200 Toulouse', '31555'),
        ]));

        $suggestions = $recherche->suggerer('12 rue des lilas toulouse');

        self::assertCount(2, $suggestions);
        self::assertSame('12 Rue des Lilas 31000 Toulouse', $suggestions[0]->libelle());
        self::assertSame('31555', $suggestions[0]->codeInsee());
    }

    public function testUneSuggestionSansCodeInseeEstEcartee(): void
    {
        // Sans commune, aucun coefficient : proposer cette adresse mènerait le
        // visiteur à un « indisponible » qu'on peut lui épargner.
        $recherche = new RechercheAdresseViaBan($this->ban([
            $this->feature('Lieu-dit sans commune', ''),
            $this->feature('12 Rue des Lilas 31000 Toulouse', '31555'),
        ]));

        $suggestions = $recherche->suggerer('lilas');

        self::assertCount(1, $suggestions);
        self::assertSame('31555', $suggestions[0]->codeInsee());
    }

    public function testUneSuggestionSansLibelleEstEcartee(): void
    {
        $recherche = new RechercheAdresseViaBan($this->ban([
            $this->feature('', '31555'),
        ]));

        self::assertSame([], $recherche->suggerer('lilas'));
    }

    public function testUnStatutNon200DonneUneListeVideMemeAvecUnCorpsExploitable(): void
    {
        // Le corps d'une réponse en erreur peut très bien ressembler à un résultat
        // (page d'erreur JSON, réponse mise en cache par un intermédiaire) : c'est
        // le statut qui décide, pas la forme du contenu.
        $recherche = new RechercheAdresseViaBan(new MockHttpClient([
            new MockResponse(
                (string) json_encode(['features' => [$this->feature('12 Rue des Lilas 31000 Toulouse', '31555')]]),
                ['http_code' => 503],
            ),
        ]));

        self::assertSame([], $recherche->suggerer('lilas'));
    }

    public function testUnJsonIllisibleDonneUneListeVide(): void
    {
        $recherche = new RechercheAdresseViaBan(new MockHttpClient([
            new MockResponse('pas du json', ['response_headers' => ['content-type' => 'application/json']]),
        ]));

        self::assertSame([], $recherche->suggerer('lilas'));
    }

    public function testUneDefaillanceReseauDonneUneListeVide(): void
    {
        $recherche = new RechercheAdresseViaBan(new MockHttpClient(static function (): MockResponse {
            return new MockResponse('', ['error' => 'connexion refusée']);
        }));

        self::assertSame([], $recherche->suggerer('lilas'));
    }

    public function testLAppelInterrogeLaRechercheBanAvecLaSaisieEtUnePoigneeDeResultats(): void
    {
        $url = null;
        $http = new MockHttpClient(static function (string $methode, string $appelee) use (&$url): MockResponse {
            self::assertSame('GET', $methode);
            $url = $appelee;

            return new MockResponse((string) json_encode(['features' => []]));
        });

        (new RechercheAdresseViaBan($http))->suggerer('12 rue des lilas');

        self::assertIsString($url);
        self::assertStringContainsString('/search/', $url);
        self::assertStringContainsString('q=12%20rue%20des%20lilas', $url);
        self::assertStringContainsString('limit=5', $url);
        // Mode « autocomplétion » de la BAN : classement adapté à une saisie en
        // cours, pas à une adresse complète.
        self::assertStringContainsString('autocomplete=1', $url);
    }

    /**
     * @param list<array<string, mixed>> $features
     */
    private function ban(array $features): MockHttpClient
    {
        return new MockHttpClient([new MockResponse((string) json_encode(['features' => $features]))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function feature(string $libelle, string $codeInsee): array
    {
        return ['properties' => ['label' => $libelle, 'citycode' => $codeInsee]];
    }
}
