<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\AdresseCommune;
use PHPUnit\Framework\TestCase;

/**
 * Bornage d'une adresse à sa commune (décision D10, #68).
 *
 * À l'échéance de rétention, un rapport ne doit plus désigner un logement : le
 * numéro et la voie tombent, la commune reste. L'adresse stockée est le libellé
 * renvoyé par la BAN — « 12 Rue des Lilas 31000 Toulouse » — dans les deux
 * parcours (audit pro et page publique) : borner, c'est ne garder que la fin du
 * libellé à partir du code postal.
 *
 * Quand aucun code postal n'apparaît (saisie libre), on ne devine pas : l'adresse
 * est effacée. Mieux vaut perdre une commune que laisser filer une voie.
 */
final class AdresseCommuneTest extends TestCase
{
    public function testUnLibelleBanEstBorneAuCodePostalEtALaCommune(): void
    {
        self::assertSame('31000 Toulouse', AdresseCommune::borner('12 Rue des Lilas 31000 Toulouse'));
    }

    public function testUneCommuneComposeeResteEntiere(): void
    {
        self::assertSame('44800 Saint-Herblain', AdresseCommune::borner('3 Avenue des Thébaudières 44800 Saint-Herblain'));
    }

    public function testUnNombreDansLeNomDeVoieNeFaitPasOfficeDeCodePostal(): void
    {
        // « 1945 » n'a que quatre chiffres, et le vrai code postal vient après :
        // c'est le dernier groupe de cinq chiffres qui fait foi.
        self::assertSame('69100 Villeurbanne', AdresseCommune::borner('5 Rue du 8 Mai 1945 69100 Villeurbanne'));
    }

    public function testUneAdresseDejaBorneeResteInchangee(): void
    {
        self::assertSame('31000 Toulouse', AdresseCommune::borner('31000 Toulouse'));
    }

    public function testUnLibelleSansCodePostalEstEfface(): void
    {
        self::assertSame('', AdresseCommune::borner('Chez Martin, au bout du chemin'));
    }

    public function testUneAdresseVideResteVide(): void
    {
        self::assertSame('', AdresseCommune::borner(''));
    }

    public function testLesEspacesSuperflusSontRetires(): void
    {
        self::assertSame('12000 Rodez', AdresseCommune::borner('Lieu-dit Les Quatre Vents  12000   Rodez '));
    }
}
