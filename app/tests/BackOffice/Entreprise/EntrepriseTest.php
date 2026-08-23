<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\Entreprise;
use App\BackOffice\Entreprise\FicheEntreprise;
use App\BackOffice\Entreprise\Siret;
use App\BackOffice\Entreprise\StatutRge;
use PHPUnit\Framework\TestCase;

/**
 * Fiche entreprise corrigeable par le pro (#311). Jusqu'ici elle était figée à
 * l'inscription : une raison sociale mal orthographiée restait fausse et un
 * déménagement ne se répercutait jamais.
 *
 * Deux gestes distincts, et c'est le cœur du sujet : **corriger** ce que le pro
 * connaît mieux que la source (sa raison sociale d'usage, son adresse), et
 * **remplacer** la fiche par une identité re-vérifiée quand le SIRET change.
 * Les faits de source — code NAF, effectif, qualifications RGE — ne se saisissent
 * pas à la main : une qualification RGE auto-déclarée n'aurait aucune valeur.
 */
final class EntrepriseTest extends TestCase
{
    public function testCorrigerChangeLIdentiteLibreEtLaisseLesFaitsDeSource(): void
    {
        $entreprise = self::fiche();

        $entreprise->corriger('Rénov Habitat SARL', '8 avenue des Cèdres, 31200 Toulouse');

        self::assertSame('Rénov Habitat SARL', $entreprise->raisonSociale());
        self::assertSame('8 avenue des Cèdres, 31200 Toulouse', $entreprise->adresse());
        // Inchangés : ils ne viennent pas du pro.
        self::assertSame('12345678901234', $entreprise->siret());
        self::assertSame('43.29A', $entreprise->codeNaf());
        self::assertSame('6 à 9 salariés', $entreprise->effectif());
        self::assertSame(StatutRge::Rge, $entreprise->statutRge());
        self::assertSame(['Isolation des murs'], $entreprise->domainesRge());
    }

    public function testCorrigerRogneLesBlancsDeSaisie(): void
    {
        $entreprise = self::fiche();

        $entreprise->corriger('  Rénov Habitat  ', "  3 rue Neuve\n");

        self::assertSame('Rénov Habitat', $entreprise->raisonSociale());
        self::assertSame('3 rue Neuve', $entreprise->adresse());
    }

    public function testUneRaisonSocialeVideEstRefusee(): void
    {
        $entreprise = self::fiche();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/raison sociale/i');

        $entreprise->corriger('   ', '3 rue Neuve');
    }

    public function testUneAdresseVideEstRefusee(): void
    {
        $entreprise = self::fiche();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/adresse/i');

        $entreprise->corriger('Rénov Habitat', '');
    }

    public function testUneSaisieTropLongueEstRefuseeAvantLaBase(): void
    {
        // Sans borne ici, le dépassement partirait en erreur SQL (colonne de 255) :
        // une panne à la place d'un message que le pro peut corriger.
        $entreprise = self::fiche();

        $this->expectException(\InvalidArgumentException::class);

        $entreprise->corriger(str_repeat('a', Entreprise::LONGUEUR_MAX_RAISON_SOCIALE + 1), '3 rue Neuve');
    }

    public function testUneAdresseTropLongueEstRefuseeAvantLaBase(): void
    {
        $entreprise = self::fiche();

        $this->expectException(\InvalidArgumentException::class);

        $entreprise->corriger('Rénov Habitat', str_repeat('a', Entreprise::LONGUEUR_MAX_ADRESSE + 1));
    }

    public function testRemplacerParUneFicheVerifieeRepriseToutLIdentite(): void
    {
        // Changement de SIRET (passage en société, reprise) : l'établissement n'est
        // plus le même, tout ce qui vient de la source le suit — y compris le RGE,
        // qui est attaché à l'entreprise vérifiée, pas au compte.
        $entreprise = self::fiche();

        $entreprise->remplacerPar(FicheEntreprise::creer(
            Siret::depuis('99988877700011'),
            'Rénov Habitat SAS',
            '5 place du Capitole, 31000 Toulouse',
            '41.20A',
            '10 à 19 salariés',
            StatutRge::NonRge,
            [],
        ));

        self::assertSame('99988877700011', $entreprise->siret());
        self::assertSame('Rénov Habitat SAS', $entreprise->raisonSociale());
        self::assertSame('5 place du Capitole, 31000 Toulouse', $entreprise->adresse());
        self::assertSame('41.20A', $entreprise->codeNaf());
        self::assertSame('10 à 19 salariés', $entreprise->effectif());
        self::assertSame(StatutRge::NonRge, $entreprise->statutRge());
        self::assertSame([], $entreprise->domainesRge());
    }

    public function testRemplacerParUneFicheSansCodeNafNeLaissePasDeChaineVide(): void
    {
        // Cohérence avec la création (depuisFiche) : « pas de code NAF » se note
        // null en base, jamais une chaîne vide qui s'afficherait comme une valeur.
        $entreprise = self::fiche();

        $entreprise->remplacerPar(FicheEntreprise::creer(
            Siret::depuis('99988877700011'),
            'Rénov Habitat SAS',
            '5 place du Capitole, 31000 Toulouse',
            '',
            null,
            StatutRge::NonRge,
            [],
        ));

        self::assertNull($entreprise->codeNaf());
        self::assertNull($entreprise->effectif());
    }

    private static function fiche(): Entreprise
    {
        return Entreprise::depuisFiche(FicheEntreprise::creer(
            Siret::depuis('12345678901234'),
            'Renov Habitat',
            '12 rue des Lilas, 31000 Toulouse',
            '43.29A',
            '6 à 9 salariés',
            StatutRge::Rge,
            ['Isolation des murs'],
        ));
    }
}
