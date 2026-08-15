<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Chiffrage;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Chiffrage\Chiffrage;
use App\BackOffice\Chiffrage\QuantitesTravaux;
use App\BackOffice\GrilleCout\GrilleCout;
use App\BackOffice\GrilleCout\Montant;
use App\BackOffice\GrilleCout\PeriodeValidite;
use App\BackOffice\GrilleCout\Poste;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\TestCase;

/**
 * Chiffrage des travaux retenus (#26) : bouquet (#29) × grille du pro (#34) × quantités.
 *
 * Le chiffrage dérivé est figé dans l'instantané du rapport (§9.1) ; les retouches du
 * pro vivent à côté, mutables, et n'effacent jamais le dérivé — comme l'ajustement (#30)
 * et le prix saisi par le pro (`Restitution::prixSaisiParLePro`). On doit toujours
 * pouvoir répondre « voici ce que votre grille disait, voici ce que vous avez saisi ».
 */
final class ChiffrageTest extends TestCase
{
    public function testChaquePosteEstChiffreSelonSonUnite(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs, Poste::Menuiseries, Poste::Chauffage);

        // Murs : 120 €/m² × 100 m². Menuiseries : 720 €/unité × 8 ouvrants. Chauffage : forfait.
        self::assertSame(12000, $chiffrage->montant(Poste::Murs)->euros());
        self::assertSame(5760, $chiffrage->montant(Poste::Menuiseries)->euros());
        self::assertSame(14000, $chiffrage->montant(Poste::Chauffage)->euros());
    }

    public function testLeTotalEstLaSommeDesPostesDuBouquet(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs, Poste::Menuiseries, Poste::Chauffage);

        self::assertSame(31760, $chiffrage->total()->euros());
    }

    public function testUnPosteHorsDuBouquetNestPasChiffre(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs);

        self::assertSame([Poste::Murs], $chiffrage->postes());
        self::assertSame(12000, $chiffrage->total()->euros());
    }

    public function testUneRetoucheRemplaceLeMontantSansEffacerLeDerive(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs, Poste::Menuiseries);

        $retouche = $chiffrage->avecRetouches([Poste::Menuiseries->name => Montant::fromEuros(8400)]);

        self::assertSame(8400, $retouche->montant(Poste::Menuiseries)->euros());
        self::assertSame(5760, $retouche->montantDerive(Poste::Menuiseries)->euros());
        self::assertTrue($retouche->estRetouche(Poste::Menuiseries));
        self::assertFalse($retouche->estRetouche(Poste::Murs));
    }

    public function testLeTotalTientCompteDesRetouches(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs, Poste::Menuiseries);

        $retouche = $chiffrage->avecRetouches([Poste::Menuiseries->name => Montant::fromEuros(8400)]);

        self::assertSame(20400, $retouche->total()->euros());
    }

    public function testRetoucherNeMutePasLeChiffrageDOrigine(): void
    {
        // Le dérivé part dans l'instantané figé : une retouche ne doit pas le corrompre.
        $chiffrage = $this->chiffrageDe(Poste::Menuiseries);

        $chiffrage->avecRetouches([Poste::Menuiseries->name => Montant::fromEuros(8400)]);

        self::assertSame(5760, $chiffrage->montant(Poste::Menuiseries)->euros());
        self::assertFalse($chiffrage->estRetouche(Poste::Menuiseries));
    }

    public function testOnNePeutPasRetoucherUnPosteHorsDuBouquet(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs);

        $this->expectException(\InvalidArgumentException::class);

        $chiffrage->avecRetouches([Poste::Vmc->name => Montant::fromEuros(1000)]);
    }

    public function testUneRetoucheNegativeEstRefusee(): void
    {
        $chiffrage = $this->chiffrageDe(Poste::Murs);

        $this->expectException(\InvalidArgumentException::class);

        $chiffrage->avecRetouches([Poste::Murs->name => Montant::fromEuros(-1)]);
    }

    private function chiffrageDe(Poste ...$postes): Chiffrage
    {
        $saisie = SaisieAudit::depuis('12 rue des Lilas, Toulouse', 100, 0, Dpe::E, Dpe::C, 5);

        return Chiffrage::derive(
            Bouquet::creer('Bouquet de test', ...$postes),
            GrilleCout::parDefautNationale(PeriodeValidite::depuis(new \DateTimeImmutable('2026-01-01'))),
            QuantitesTravaux::depuis($saisie),
        );
    }
}
