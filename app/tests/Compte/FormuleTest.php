<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\Formule;
use PHPUnit\Framework\TestCase;

/**
 * Formules de compte (#61) : chaque type porte ses paramètres d'essai par défaut
 * — durée en jours, quota de rapports, et plafond de comptes (Fondateur limité).
 * Les valeurs restent éditables en base ([[ReglagesFormule]]), jamais figées ici.
 */
final class FormuleTest extends TestCase
{
    public function testLaFormuleClassiqueOffreDeuxMoisEtDixRapports(): void
    {
        $parametres = Formule::Classique->parametresParDefaut();

        self::assertSame(60, $parametres->dureeEssaiJours());
        self::assertSame(10, $parametres->quotaRapports());
        self::assertNull($parametres->plafondComptes());
    }

    public function testLaFormuleFondateurOffreSixMoisCentRapportsEtEstPlafonnee(): void
    {
        $parametres = Formule::Fondateur->parametresParDefaut();

        self::assertSame(180, $parametres->dureeEssaiJours());
        self::assertSame(100, $parametres->quotaRapports());
        self::assertSame(10, $parametres->plafondComptes());
    }

    public function testUnParametrageNonPositifEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new \App\Compte\ParametresFormule(0, 10, null);
    }
}
