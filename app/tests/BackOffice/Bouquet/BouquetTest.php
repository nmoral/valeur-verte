<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\GrilleCout\Poste;
use PHPUnit\Framework\TestCase;

final class BouquetTest extends TestCase
{
    public function testUnBouquetPorteUnNomEtSesPostes(): void
    {
        $bouquet = Bouquet::creer('Isolation seule', Poste::Murs, Poste::Combles);

        self::assertSame('Isolation seule', $bouquet->nom());
        self::assertEqualsCanonicalizing([Poste::Murs, Poste::Combles], $bouquet->postes());
    }

    public function testLeNomEstDebarrasseDesEspacesSuperflus(): void
    {
        $bouquet = Bouquet::creer('  Chauffage seul  ', Poste::Chauffage);

        self::assertSame('Chauffage seul', $bouquet->nom());
    }

    public function testUnNomVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Bouquet::creer('   ', Poste::Murs);
    }

    public function testUnBouquetSansPosteEstRefuse(): void
    {
        // Un bouquet est l'unité de sélection du mode audit : vide, il n'a pas de sens.
        $this->expectException(\InvalidArgumentException::class);

        Bouquet::creer('Vide');
    }

    public function testLesPostesEnDoubleSontDedupliques(): void
    {
        $bouquet = Bouquet::creer('Doublons', Poste::Murs, Poste::Murs, Poste::Combles);

        self::assertEqualsCanonicalizing([Poste::Murs, Poste::Combles], $bouquet->postes());
    }

    public function testContientRenseigneLAppartenanceDUnPoste(): void
    {
        $bouquet = Bouquet::creer('Isolation seule', Poste::Murs, Poste::Combles);

        self::assertTrue($bouquet->contient(Poste::Murs));
        self::assertFalse($bouquet->contient(Poste::Vmc));
    }

    public function testAvecPosteAjouteSansMuterLOriginal(): void
    {
        $base = Bouquet::creer('Isolation seule', Poste::Murs);

        $enrichi = $base->avecPoste(Poste::Vmc);

        self::assertFalse($base->contient(Poste::Vmc), 'l\'original ne doit pas changer');
        self::assertTrue($enrichi->contient(Poste::Vmc));
        self::assertNotSame($base, $enrichi);
    }

    public function testAvecPosteEstIdempotent(): void
    {
        $base = Bouquet::creer('Isolation seule', Poste::Murs);

        $resultat = $base->avecPoste(Poste::Murs);

        self::assertEqualsCanonicalizing([Poste::Murs], $resultat->postes());
    }

    public function testSansPosteRetireSansMuterLOriginal(): void
    {
        $base = Bouquet::creer('Isolation', Poste::Murs, Poste::Combles);

        $reduit = $base->sansPoste(Poste::Combles);

        self::assertTrue($base->contient(Poste::Combles), 'l\'original ne doit pas changer');
        self::assertFalse($reduit->contient(Poste::Combles));
        self::assertNotSame($base, $reduit);
    }

    public function testOnNePeutPasRetirerLeDernierPoste(): void
    {
        $bouquet = Bouquet::creer('Chauffage seul', Poste::Chauffage);

        $this->expectException(\InvalidArgumentException::class);

        $bouquet->sansPoste(Poste::Chauffage);
    }

    public function testRenommeChangeLeNomSansMuterLOriginal(): void
    {
        $base = Bouquet::creer('Ancien nom', Poste::Murs);

        $renomme = $base->renomme('Nouveau nom');

        self::assertSame('Ancien nom', $base->nom());
        self::assertSame('Nouveau nom', $renomme->nom());
        self::assertEqualsCanonicalizing($base->postes(), $renomme->postes());
    }
}
