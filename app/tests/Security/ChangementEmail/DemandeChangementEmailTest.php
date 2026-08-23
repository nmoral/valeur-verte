<?php

declare(strict_types=1);

namespace App\Tests\Security\ChangementEmail;

use App\Security\ChangementEmail\DemandeChangementEmail;
use PHPUnit\Framework\TestCase;

/**
 * Jeton de changement d'adresse email (#310). Même exigence que la
 * réinitialisation de mot de passe (#194) — usage unique et expirant — plus une
 * différence qui compte : le jeton porte **l'adresse visée**. C'est ce qui rend
 * la confirmation probante : cliquer le lien prouve qu'on relève bien la boîte
 * vers laquelle on demande à basculer, et pas seulement qu'on a reçu un email.
 */
final class DemandeChangementEmailTest extends TestCase
{
    public function testUneDemandeEstConfirmableAvantExpirationEtTantQueNonConsommee(): void
    {
        $demande = $this->demande();

        self::assertTrue($demande->estConfirmable(new \DateTimeImmutable('2026-01-01 10:30:00')));
    }

    public function testUneDemandeExpireeNestPlusConfirmable(): void
    {
        $demande = $this->demande();

        self::assertFalse($demande->estConfirmable(new \DateTimeImmutable('2026-01-01 11:00:01')));
    }

    public function testUneDemandeConsommeeNestPlusConfirmableMemeAvantExpiration(): void
    {
        $demande = $this->demande();

        $demande->marquerConfirmee(new \DateTimeImmutable('2026-01-01 10:15:00'));

        self::assertFalse($demande->estConfirmable(new \DateTimeImmutable('2026-01-01 10:30:00')));
    }

    public function testLaDemandePorteLAdresseViseeEtLeComptePourLequelElleAEteEmise(): void
    {
        $demande = $this->demande();

        self::assertSame(42, $demande->userId());
        self::assertSame('nouvelle@example.com', $demande->nouvelEmail());
        self::assertSame('empreinte', $demande->empreinte());
    }

    private function demande(): DemandeChangementEmail
    {
        return new DemandeChangementEmail(
            42,
            'nouvelle@example.com',
            'empreinte',
            new \DateTimeImmutable('2026-01-01 11:00:00'),
        );
    }
}
