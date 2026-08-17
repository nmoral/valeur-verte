<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Restitution;

use App\BackOffice\Restitution\AjustementInvalide;
use App\BackOffice\Restitution\AjustementProfessionnel;
use PHPUnit\Framework\TestCase;

/**
 * Ligne d'ajustement du professionnel (#30, §4) : une latitude laissée au pro,
 * jamais une réécriture du coefficient statistique. L'ajustement est une ligne
 * séparée — un montant signé et un motif obligatoire — et l'estimation retenue
 * se déduit de la mesure sans jamais l'altérer.
 */
final class AjustementProfessionnelTest extends TestCase
{
    public function testIlPorteUnMontantSigneEtSonMotif(): void
    {
        $ajustement = AjustementProfessionnel::depuis(-5000, 'État général du bien');

        self::assertSame(-5000, $ajustement->montantEuros());
        self::assertSame('État général du bien', $ajustement->motif());
    }

    public function testUnAjustementPeutEtreEnPlus(): void
    {
        $ajustement = AjustementProfessionnel::depuis(3000, 'Prestations haut de gamme');

        self::assertSame(3000, $ajustement->montantEuros());
    }

    public function testLEstimationRetenueEstLaMesurePlusLAjustement(): void
    {
        $ajustement = AjustementProfessionnel::depuis(-5000, 'État général du bien');

        // Mesure statistique intacte : +40 700 € — l'ajustement s'y ajoute.
        self::assertSame(35700, $ajustement->estimationRetenue(40700));
    }

    public function testLeMotifEstObligatoire(): void
    {
        $this->expectException(AjustementInvalide::class);

        AjustementProfessionnel::depuis(-5000, '   ');
    }

    public function testLeMotifEstNettoyeDesEspacesSuperflus(): void
    {
        $ajustement = AjustementProfessionnel::depuis(-5000, '  État général  ');

        self::assertSame('État général', $ajustement->motif());
    }

    public function testUnAjustementNulEstRefuseAvecUnMessageActionnable(): void
    {
        // ONB-8 (#110) : le message doit dire quoi faire, pas seulement constater
        // l'échec — le pro voit comment corriger sa saisie.
        $this->expectException(AjustementInvalide::class);
        $this->expectExceptionMessage('Saisissez un montant différent de zéro');

        AjustementProfessionnel::depuis(0, 'Sans effet');
    }

    public function testIlSeSerialisePourLaPersistanceEtSeReconstruit(): void
    {
        $ajustement = AjustementProfessionnel::depuis(-5000, 'État général du bien');

        $reconstruit = AjustementProfessionnel::fromArray($ajustement->toArray());

        self::assertEquals($ajustement, $reconstruit);
    }
}
