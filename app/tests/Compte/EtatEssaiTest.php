<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\EtatEssai;
use App\Compte\ParametresFormule;
use PHPUnit\Framework\TestCase;

/**
 * État d'essai d'un compte (#108) : combine le temps ET le quota de rapports.
 * L'essai court tant que la date de fin n'est pas atteinte ET que le quota n'est
 * pas consommé ; il se termine dès qu'une des deux limites tombe. C'est cet état
 * que la restitution artisan et l'email de relance liront.
 */
final class EtatEssaiTest extends TestCase
{
    private const CREE_LE = '2026-01-01 10:00:00';

    public function testIlCourtTantQueTempsEtQuotaRestent(): void
    {
        // Classique : 60 jours / 10 rapports. 3 rapports, 20 jours écoulés.
        $etat = $this->calculer(consommes: 3, maintenant: '2026-01-21 10:00:00');

        self::assertTrue($etat->enEssai());
        self::assertFalse($etat->termine());
        self::assertSame(7, $etat->rapportsRestants());
        self::assertSame(40, $etat->joursRestants());
    }

    public function testIlSeTermineQuandLeQuotaEstConsomme(): void
    {
        $etat = $this->calculer(consommes: 10, maintenant: '2026-01-21 10:00:00');

        self::assertFalse($etat->enEssai());
        self::assertTrue($etat->termine());
        self::assertSame(0, $etat->rapportsRestants());
    }

    public function testIlSeTermineQuandLeTempsEstEcoule(): void
    {
        // 5 rapports (quota non atteint) mais 61 jours écoulés (> 60).
        $etat = $this->calculer(consommes: 5, maintenant: '2026-03-03 10:00:00');

        self::assertFalse($etat->enEssai());
        self::assertTrue($etat->termine());
        self::assertSame(0, $etat->joursRestants());
    }

    public function testIlResteUnJourTantQueLaFinNEstPasPasseeMemeAQuelquesHeuresPres(): void
    {
        // Fin = 2026-03-02 10:00 (60 jours) ; il reste 1 heure → arrondi au jour
        // supérieur : l'artisan voit « 1 jour restant » jusqu'à la fin réelle.
        $etat = EtatEssai::calculer(
            3,
            new ParametresFormule(60, 10, null),
            new \DateTimeImmutable(self::CREE_LE),
            new \DateTimeImmutable('2026-03-02 09:00:00'),
        );

        self::assertSame(1, $etat->joursRestants());
        self::assertTrue($etat->enEssai());
    }

    public function testSansAucunRapportConsommeLeQuotaEstPlein(): void
    {
        $etat = $this->calculer(consommes: 0, maintenant: '2026-01-21 10:00:00');

        self::assertSame(0, $etat->rapportsConsommes());
        self::assertSame(10, $etat->rapportsRestants());
    }

    public function testLesRapportsRestantsNeDescendentJamaisSousZero(): void
    {
        // Quota dépassé (12 > 10) : on n'affiche jamais un reste négatif.
        $etat = $this->calculer(consommes: 12, maintenant: '2026-01-21 10:00:00');

        self::assertSame(0, $etat->rapportsRestants());
    }

    public function testLEssaiSeTermineALInstantPrecisDeLaFin(): void
    {
        // Fin = création + 60 jours, à la seconde près : l'essai est alors terminé.
        $etat = $this->calculer(consommes: 1, maintenant: '2026-03-02 10:00:00');

        self::assertTrue($etat->termine());
        self::assertSame(0, $etat->joursRestants());
    }

    public function testLaProportionConsommeeSertLaNotificationA80Pourcent(): void
    {
        // 8 rapports sur 10 = 80 %.
        $etat = $this->calculer(consommes: 8, maintenant: '2026-01-21 10:00:00');

        self::assertTrue($etat->quotaConsommeAuMoins(80));
        self::assertFalse($this->calculer(consommes: 7, maintenant: '2026-01-21 10:00:00')->quotaConsommeAuMoins(80));
    }

    private function calculer(int $consommes, string $maintenant): EtatEssai
    {
        return EtatEssai::calculer(
            $consommes,
            new ParametresFormule(60, 10, null),
            new \DateTimeImmutable(self::CREE_LE),
            new \DateTimeImmutable($maintenant),
        );
    }
}
