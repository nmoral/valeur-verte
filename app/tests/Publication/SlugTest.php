<?php

declare(strict_types=1);

namespace App\Tests\Publication;

use App\Publication\Slug;
use PHPUnit\Framework\TestCase;

/**
 * Slugifieur déterministe des noms de lieux (communes, EPCI) pour les URLs SEO
 * de la Vague 2 (#338, #359). Contrairement aux slugs départementaux figés à la
 * main ({@see \App\Publication\RepertoireDepartements}), les ~34 746 communes
 * imposent un algorithme — qui devient dès lors un **contrat d'URL permanent** :
 * ces cas en gèlent le comportement (une URL indexée ne doit jamais bouger).
 */
final class SlugTest extends TestCase
{
    public function testMinusculeEtRemplaceLesEspacesParDesTirets(): void
    {
        self::assertSame('bordeaux', Slug::depuis('Bordeaux')->valeur());
        self::assertSame('le-havre', Slug::depuis('Le Havre')->valeur());
    }

    public function testLesAccentsSontTranslitteres(): void
    {
        self::assertSame('saint-etienne', Slug::depuis('Saint-Étienne')->valeur());
        self::assertSame('chateauneuf', Slug::depuis('Châteauneuf')->valeur());
        self::assertSame('besancon', Slug::depuis('Besançon')->valeur());
    }

    public function testLApostropheDevientUnSeparateur(): void
    {
        // Aligné sur les slugs départementaux figés : « Côte-d'Or » => « cote-d-or ».
        self::assertSame('cote-d-or', Slug::depuis("Côte-d'Or")->valeur());
        // Le tréma sur le « y » (ÿ) doit aussi tomber en ASCII.
        self::assertSame('l-hay-les-roses', Slug::depuis("L'Haÿ-les-Roses")->valeur());
    }

    public function testLesSeparateursConsecutifsSeReduisentEtSontRognes(): void
    {
        self::assertSame('sainte-marie', Slug::depuis('  Sainte  --  Marie  ')->valeur());
    }

    public function testLeSlugifieurReproduitLesSlugsDepartementauxFiges(): void
    {
        // Le slugifieur générique doit retrouver les slugs gelés à la main, sinon
        // les deux référentiels divergeraient sur les noms composés.
        self::assertSame('territoire-de-belfort', Slug::depuis('Territoire de Belfort')->valeur());
        self::assertSame('alpes-de-haute-provence', Slug::depuis('Alpes-de-Haute-Provence')->valeur());
        self::assertSame('cotes-d-armor', Slug::depuis("Côtes-d'Armor")->valeur());
    }
}
