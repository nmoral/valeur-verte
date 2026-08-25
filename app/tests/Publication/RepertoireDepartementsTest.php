<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\RepertoireDepartements;
use PHPUnit\Framework\TestCase;

/**
 * Le répertoire code ↔ slug des hubs départementaux (#337). Les slugs sont un
 * contrat d'URL permanent : on vérifie surtout qu'ils sont bijectifs et stables.
 */
final class RepertoireDepartementsTest extends TestCase
{
    private RepertoireDepartements $repertoire;

    protected function setUp(): void
    {
        $this->repertoire = new RepertoireDepartements();
    }

    public function testLeSlugDonneLeCodeEtInversement(): void
    {
        self::assertSame('33', $this->repertoire->code('gironde'));
        self::assertSame('gironde', $this->repertoire->slug('33'));
    }

    public function testLaCorseGardeSesCodesAlphanumeriques(): void
    {
        self::assertSame('2A', $this->repertoire->code('corse-du-sud'));
        self::assertSame('haute-corse', $this->repertoire->slug('2B'));
    }

    public function testCodeEtSlugSontBijectifsSurTousLesDepartements(): void
    {
        foreach ($this->repertoire->tous() as $code) {
            $slug = $this->repertoire->slug($code);
            self::assertNotNull($slug, \sprintf('Le département %s doit avoir un slug.', $code));
            self::assertSame($code, $this->repertoire->code($slug), \sprintf('Aller-retour rompu pour %s.', $code));
        }
    }

    public function testLesSlugsSontUniques(): void
    {
        $slugs = array_map(fn (string $code): ?string => $this->repertoire->slug($code), $this->repertoire->tous());

        self::assertCount(\count($slugs), array_unique($slugs), 'Deux départements ne peuvent pas partager un slug.');
    }

    public function testLesCentQuatreVingtSeizeDepartementsMetropolitainsSontCouverts(): void
    {
        $tous = $this->repertoire->tous();

        // 01–95 (sans le 20, éclaté en 2A/2B) : 96 départements métropolitains.
        self::assertCount(96, $tous);
        // Une liste de codes (clés 0..n), pas le tableau slug => code : la première
        // clé est l'entier 0, jamais un slug.
        self::assertSame(0, array_key_first($tous), 'tous() renvoie une liste de codes, pas le tableau slug => code.');
        self::assertContains('2A', $tous);
    }

    public function testUnSlugOuUnCodeInconnuNeResoutPas(): void
    {
        self::assertNull($this->repertoire->code('atlantide'));
        self::assertNull($this->repertoire->slug('00'));
    }

    public function testLeNomVientDuReferentiel(): void
    {
        self::assertSame('Gironde', $this->repertoire->nom('33'));
    }

    public function testLAnnuaireEstTrieParNomEtIgnoreLesCodesInconnus(): void
    {
        // Le code inconnu est placé au milieu : un « break » au lieu d'un « continue »
        // perdrait le département qui suit (l'Ariège), pas seulement le code inconnu.
        $annuaire = $this->repertoire->annuaire(['33', '00', '09']);

        self::assertSame(
            [
                ['code' => '09', 'nom' => 'Ariège', 'slug' => 'ariege'],
                ['code' => '33', 'nom' => 'Gironde', 'slug' => 'gironde'],
            ],
            $annuaire,
            'L\'annuaire trie par nom (Ariège avant Gironde) et n\'inclut jamais un code sans slug.',
        );
    }
}
