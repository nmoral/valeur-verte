<?php

declare(strict_types=1);

namespace App\Tests\Onboarding\Parcours;

use App\Onboarding\Parcours\EvenementInscriptionRepository;
use App\Onboarding\Parcours\JournalParcoursInscription;
use App\Onboarding\Parcours\TypeEvenementInscription;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Le journal du parcours d'inscription (#111) trace, de façon anonyme, les étapes
 * d'une même visite pré-compte (affichage → SIRET renseigné → compte créé) pour
 * mesurer l'abandon sur l'écran SIRET. Aucune donnée personnelle : ni IP, ni email
 * ; l'identifiant de session est tiré au hasard et éphémère. Socle calqué sur le
 * parcours widget (#90) mais isolé — pas de token de page, pas de tenant.
 */
final class JournalParcoursInscriptionTest extends KernelTestCase
{
    private const SESSION = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4';

    public function testEnregistreUneEtapeHorodateeEtLisiblePourLaSession(): void
    {
        self::bootKernel();
        $journal = new JournalParcoursInscription(
            $this->evenements(),
            new MockClock(new \DateTimeImmutable('2026-08-25 09:00:00')),
        );

        $journal->enregistrer(self::SESSION, TypeEvenementInscription::InscriptionAffichee);
        $journal->enregistrer(self::SESSION, TypeEvenementInscription::CompteCree);

        $etapes = $this->evenements()->pourSession(self::SESSION);

        self::assertCount(2, $etapes);
        self::assertSame(TypeEvenementInscription::InscriptionAffichee, $etapes[0]->type());
        self::assertSame(TypeEvenementInscription::CompteCree, $etapes[1]->type());
        self::assertEquals(new \DateTimeImmutable('2026-08-25 09:00:00'), $etapes[0]->survenuLe());
    }

    public function testNeMelangePasLesSessions(): void
    {
        self::bootKernel();
        $journal = new JournalParcoursInscription(
            $this->evenements(),
            new MockClock(new \DateTimeImmutable('2026-08-25 09:00:00')),
        );

        $journal->enregistrer(self::SESSION, TypeEvenementInscription::InscriptionAffichee);
        $journal->enregistrer('ffffffffffffffffffffffffffffffff', TypeEvenementInscription::InscriptionAffichee);

        self::assertCount(1, $this->evenements()->pourSession(self::SESSION));
    }

    private function evenements(): EvenementInscriptionRepository
    {
        $repository = self::getContainer()->get(EvenementInscriptionRepository::class);
        self::assertInstanceOf(EvenementInscriptionRepository::class, $repository);

        return $repository;
    }
}
