<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodesDeSecours;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Codes de secours du second facteur (#312). Ils existent pour un seul scénario,
 * mais celui-là est fatal : l'admin qui perd son téléphone perd l'administration
 * du produit, et il n'y a personne au-dessus pour le débloquer.
 *
 * Ils sont **hachés** — ils ne servent qu'à être comparés, comme un mot de passe.
 * Conséquence directe : ils s'affichent une fois, à la génération, et personne ne
 * peut les réafficher ensuite. La seule opération possible est d'en regénérer un
 * jeu, ce qui révoque le précédent.
 */
final class CodesDeSecoursTest extends KernelTestCase
{
    public function testUnJeuCompteDixCodesTousDifferents(): void
    {
        self::bootKernel();

        $codes = $this->service()->regenerer(4242);

        self::assertCount(10, $codes);
        self::assertSame($codes, array_values(array_unique($codes)));
    }

    public function testLesCodesSontLisiblesEtRecopiablesSansAmbiguite(): void
    {
        self::bootKernel();

        foreach ($this->service()->regenerer(4242) as $code) {
            // Groupés par quatre : ils seront lus sur une feuille imprimée et
            // retapés à la main, souvent des mois plus tard.
            self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);
            // Ni 0/O ni 1/I : sur du papier, ces couples ne se distinguent pas.
            self::assertDoesNotMatchRegularExpression('/[O01I]/', $code);
        }
    }

    public function testUnCodeValideOuvreEtNeResertPas(): void
    {
        self::bootKernel();
        $codes = $this->service()->regenerer(4242);

        self::assertTrue($this->service()->consommer(4242, $codes[0]));
        self::assertFalse($this->service()->consommer(4242, $codes[0]));
    }

    public function testLaCasseEtLesTiretsSontIndifferents(): void
    {
        self::bootKernel();
        $codes = $this->service()->regenerer(4242);

        // Recopié depuis une feuille, un code arrive en minuscules, sans tirets,
        // avec une espace de trop. Refuser pour ça serait absurde au moment précis
        // où l'admin n'a plus que ce papier.
        $recopie = ' '.strtolower(str_replace('-', '', $codes[1])).' ';

        self::assertTrue($this->service()->consommer(4242, $recopie));
    }

    public function testUnCodeInconnuEstRefuse(): void
    {
        self::bootKernel();
        $this->service()->regenerer(4242);

        self::assertFalse($this->service()->consommer(4242, 'ZZZZ-ZZZZ-ZZZZ'));
    }

    public function testUnCodeDUnAutreCompteNouvrePas(): void
    {
        self::bootKernel();
        $codes = $this->service()->regenerer(4242);

        self::assertFalse($this->service()->consommer(9999, $codes[0]));
    }

    public function testRegenererRevoqueLeJeuPrecedent(): void
    {
        self::bootKernel();
        $anciens = $this->service()->regenerer(4242);

        $this->service()->regenerer(4242);

        self::assertFalse($this->service()->consommer(4242, $anciens[0]));
    }

    public function testLeNombreDeCodesRestantsEstLisible(): void
    {
        self::bootKernel();
        $codes = $this->service()->regenerer(4242);

        $this->service()->consommer(4242, $codes[0]);

        // C'est ce compteur qui alimentera le bandeau d'alerte quand la réserve
        // s'épuise — on prévient, on ne regénère pas d'office : la feuille
        // imprimée deviendrait fausse sans que l'admin le sache.
        self::assertSame(9, $this->service()->restants(4242));
    }

    public function testAucunCodeNestStockeEnClair(): void
    {
        self::bootKernel();
        $codes = $this->service()->regenerer(4242);

        $connexion = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $connexion);
        $stockes = $connexion->fetchFirstColumn('SELECT empreinte FROM code_de_secours WHERE user_id = 4242');

        self::assertNotContains($codes[0], $stockes);
        self::assertNotContains(str_replace('-', '', $codes[0]), $stockes);
    }

    private function service(): CodesDeSecours
    {
        $service = self::getContainer()->get(CodesDeSecours::class);
        self::assertInstanceOf(CodesDeSecours::class, $service);

        return $service;
    }
}
