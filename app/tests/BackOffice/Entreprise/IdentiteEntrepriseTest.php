<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Entreprise;

use App\BackOffice\Entreprise\IdentiteEntreprise;
use PHPUnit\Framework\TestCase;

final class IdentiteEntrepriseTest extends TestCase
{
    public function testLesChampsTexteSontDetoures(): void
    {
        $identite = IdentiteEntreprise::creer('  Renov Habitat  ', '  12 rue des Lilas  ', '  43.29A  ', '6 à 9 salariés');

        self::assertSame('Renov Habitat', $identite->raisonSociale());
        self::assertSame('12 rue des Lilas', $identite->adresse());
        self::assertSame('43.29A', $identite->codeNaf());
        self::assertSame('6 à 9 salariés', $identite->effectif());
    }
}
