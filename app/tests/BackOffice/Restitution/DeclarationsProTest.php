<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Aide\CategorieRevenu;
use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Restitution\DeclarationsPro;
use PHPUnit\Framework\TestCase;

/**
 * Déclarations du professionnel sur la restitution (#26) : la situation de revenus
 * du ménage et les retouches de chiffrage.
 *
 * Elles vivent **hors de l'instantané figé**, comme l'ajustement (#30) : ce sont des
 * annotations du pro, posées après coup, qui n'altèrent ni la mesure statistique ni
 * le chiffrage dérivé de sa grille. Le reste à charge se recalcule à l'affichage.
 */
final class DeclarationsProTest extends TestCase
{
    public function testParDefautRienNestDeclare(): void
    {
        $declarations = DeclarationsPro::aucune();

        self::assertSame(CategorieRevenu::NonPrecisee, $declarations->categorieRevenu());
        self::assertFalse($declarations->aDesRetouches());
    }

    public function testElleConserveLaCategorieDeclaree(): void
    {
        $declarations = DeclarationsPro::depuis(CategorieRevenu::Modeste, []);

        self::assertSame(CategorieRevenu::Modeste, $declarations->categorieRevenu());
    }

    public function testElleSeSerialiseEtSeRelitALIdentique(): void
    {
        $declarations = DeclarationsPro::depuis(
            CategorieRevenu::Intermediaire,
            [Poste::Menuiseries->name => 8400],
        );

        $relues = DeclarationsPro::fromArray($declarations->toArray());

        self::assertSame(CategorieRevenu::Intermediaire, $relues->categorieRevenu());
        self::assertTrue($relues->aDesRetouches());
        self::assertSame(8400, $relues->appliquerA($this->chiffrage())->montant(Poste::Menuiseries)->euros());
    }

    public function testUneRetoucheNegativeEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        DeclarationsPro::depuis(CategorieRevenu::Modeste, [Poste::Menuiseries->name => -1]);
    }

    public function testAppliqueeAUnChiffrageElleEnRemplaceLesMontantsSansEffacerLeDerive(): void
    {
        $declarations = DeclarationsPro::depuis(
            CategorieRevenu::Modeste,
            [Poste::Menuiseries->name => 8400],
        );

        $retouche = $declarations->appliquerA($this->chiffrage());

        self::assertSame(8400, $retouche->montant(Poste::Menuiseries)->euros());
        self::assertSame(5760, $retouche->montantDerive(Poste::Menuiseries)->euros());
        self::assertTrue($retouche->estRetouche(Poste::Menuiseries));
    }

    public function testSansRetoucheLeChiffrageResteCeluiDeLaGrille(): void
    {
        $declarations = DeclarationsPro::depuis(CategorieRevenu::Modeste, []);

        $applique = $declarations->appliquerA($this->chiffrage());

        self::assertSame(5760, $applique->montant(Poste::Menuiseries)->euros());
        self::assertFalse($applique->estRetouche(Poste::Menuiseries));
    }

    private function chiffrage(): Chiffrage
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);

        return Chiffrage::derive(
            Bouquet::creer('Bouquet de test', Poste::Murs, Poste::Menuiseries),
            GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'))),
            QuantitesTravaux::depuis($saisie),
        );
    }
}
