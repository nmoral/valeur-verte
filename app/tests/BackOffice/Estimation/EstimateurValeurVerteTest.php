<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Estimation;

use App\BackOffice\Audit\SaisieAudit;
use App\BackOffice\Estimation\CoefficientMaille;
use App\BackOffice\Estimation\EstimateurValeurVerte;
use App\BackOffice\Estimation\EstimationIndisponible;
use App\BackOffice\Estimation\FournisseurCoefficientMaille;
use App\BackOffice\Estimation\FournisseurPrixMaille;
use App\BackOffice\Rapport\Dpe;
use PHPUnit\Framework\TestCase;

final class EstimateurValeurVerteTest extends TestCase
{
    private const CODE_INSEE = '32013';

    public function testLePrixDeBaseParDefautEstLaMedianeM2FoisLaSurfaceBatie(): void
    {
        $estimateur = new EstimateurValeurVerte(
            $this->coefficients(self::maille()),
            $this->prix(1635.0),
        );

        $restitution = $estimateur->estimer(self::CODE_INSEE, self::saisie());

        // P = 1635 × 90 = 147 150 ; D→C ; erreur 17,22 %.
        self::assertSame(16865, $restitution->montantEuros());
        self::assertSame(13961, $restitution->borneBasseEuros());
        self::assertSame(19770, $restitution->borneHauteEuros());
        self::assertSame(134, $restitution->n());
        self::assertSame(10, $restitution->rayonKm());
        self::assertSame(2024, $restitution->millesime());
    }

    public function testLeProPeutRemplacerLePrixDeBase(): void
    {
        $estimateur = new EstimateurValeurVerte(
            $this->coefficients(self::maille()),
            $this->prix(1635.0),
        );

        $restitution = $estimateur->estimer(self::CODE_INSEE, self::saisie(), prixBaseOverride: 200000);

        self::assertSame(22923, $restitution->montantEuros());
    }

    public function testUneMailleIntrouvableRendLEstimationIndisponible(): void
    {
        $estimateur = new EstimateurValeurVerte(
            $this->coefficients(null),
            $this->prix(1635.0),
        );

        $this->expectException(EstimationIndisponible::class);

        $estimateur->estimer(self::CODE_INSEE, self::saisie());
    }

    public function testSansMedianeNiOverrideLEstimationEstIndisponible(): void
    {
        $estimateur = new EstimateurValeurVerte(
            $this->coefficients(self::maille()),
            $this->prix(null),
        );

        $this->expectException(EstimationIndisponible::class);

        $estimateur->estimer(self::CODE_INSEE, self::saisie());
    }

    public function testSansMedianeLOverrideProDebloqueLEstimation(): void
    {
        $estimateur = new EstimateurValeurVerte(
            $this->coefficients(self::maille()),
            $this->prix(null),
        );

        $restitution = $estimateur->estimer(self::CODE_INSEE, self::saisie(), prixBaseOverride: 200000);

        self::assertSame(22923, $restitution->montantEuros());
    }

    private static function maille(): CoefficientMaille
    {
        return CoefficientMaille::depuis(
            millesime: 2024,
            rayonKm: 10,
            n: 134,
            erreurMediane: 17.22,
            coefDpe: ['D' => 0.0, 'C' => 0.10850747],
        );
    }

    private static function saisie(): SaisieAudit
    {
        return SaisieAudit::depuis('12 rue des Lilas, Auch', 90, 500, Dpe::D, Dpe::C);
    }

    private function coefficients(?CoefficientMaille $maille): FournisseurCoefficientMaille
    {
        return new class($maille) implements FournisseurCoefficientMaille {
            public function __construct(private ?CoefficientMaille $maille)
            {
            }

            public function pour(string $codeInsee): ?CoefficientMaille
            {
                return $this->maille;
            }
        };
    }

    private function prix(?float $prixM2Median): FournisseurPrixMaille
    {
        return new class($prixM2Median) implements FournisseurPrixMaille {
            public function __construct(private ?float $prixM2Median)
            {
            }

            public function prixM2Median(string $codeInsee): ?float
            {
                return $this->prixM2Median;
            }
        };
    }
}
