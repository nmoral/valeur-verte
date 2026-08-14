<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Bouquet;

use App\BackOffice\Bouquet\Bouquet;
use App\BackOffice\Bouquet\BouquetEnregistre;
use PHPUnit\Framework\TestCase;

/**
 * Adaptateur de persistance du bouquet (#35) : l'entité stocke un bouquet du pro
 * et le restitue à l'identique en value object, sans que le domaine dépende de
 * Doctrine.
 */
final class BouquetEnregistreTest extends TestCase
{
    public function testIlRestitueLeBouquetDOrigine(): void
    {
        $bouquet = Bouquet::isolationSeule();

        self::assertEquals($bouquet, BouquetEnregistre::depuis($bouquet)->versBouquet());
    }

    public function testIlSeMetAJourDepuisUnNouveauBouquet(): void
    {
        $enregistre = BouquetEnregistre::depuis(Bouquet::isolationSeule());

        $enregistre->mettreAJour(Bouquet::chauffageSeul());

        self::assertEquals(Bouquet::chauffageSeul(), $enregistre->versBouquet());
    }
}
