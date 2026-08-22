<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\Source;
use PHPUnit\Framework\TestCase;

/**
 * Anonymisation d'un rapport à l'échéance de rétention (décision D10, #68).
 *
 * Deux gestes indissociables : le contact disparaît, et l'adresse est bornée à
 * sa commune — dans la colonne **comme** dans l'instantané figé. Une adresse qui
 * survivrait dans le JSONB rendrait l'anonymisation illusoire : le PDF, lui, se
 * régénère depuis l'instantané.
 *
 * Ce qui ne bouge pas : les **calculs**. Mêmes chiffres, même contexte, même
 * référence — c'est la lecture que `CLAUDE.md` §7 donne de la reproductibilité.
 */
final class RapportAnonymisationTest extends TestCase
{
    private const string ADRESSE = '12 Rue des Lilas 31000 Toulouse';
    private const string QUAND = '2028-09-01 03:10:00';

    public function testUnRapportNeufNEstPasAnonymise(): void
    {
        $rapport = $this->rapport(Contact::creer('Mme Cazeneuve', 'cazeneuve@example.com', null));

        self::assertFalse($rapport->estAnonymise());
        self::assertNull($rapport->anonymiseLe());
    }

    public function testLAnonymisationEffaceLeContact(): void
    {
        $rapport = $this->rapport(Contact::creer('Mme Cazeneuve', 'cazeneuve@example.com', '0612345678'));

        $rapport->anonymiser(new \DateTimeImmutable(self::QUAND));

        self::assertNull($rapport->contact());
        self::assertFalse($rapport->estUnLead());
    }

    public function testLAnonymisationBorneLAdresseDansLaColonneEtDansLInstantane(): void
    {
        $rapport = $this->rapport();

        $rapport->anonymiser(new \DateTimeImmutable(self::QUAND));

        self::assertSame('31000 Toulouse', $rapport->adresse());
        self::assertSame('31000 Toulouse', $rapport->instantane()->saisie()->adresse());
    }

    public function testUneAdresseSansCodePostalEstEffaceeDesDeuxCotes(): void
    {
        $rapport = $this->rapport(adresse: 'Chez Martin, au bout du chemin');

        $rapport->anonymiser(new \DateTimeImmutable(self::QUAND));

        self::assertSame('', $rapport->adresse());
        self::assertSame('', $rapport->instantane()->saisie()->adresse());
    }

    public function testLesCalculsSurviventAAnonymisation(): void
    {
        $rapport = $this->rapport();
        $avant = $rapport->instantane();

        $rapport->anonymiser(new \DateTimeImmutable(self::QUAND));

        $apres = $rapport->instantane();
        self::assertSame('2026-0841', $rapport->reference());
        self::assertSame(52610, $rapport->valeurVerteEstimee());
        self::assertEquals($avant->restitution(), $apres->restitution());
        self::assertEquals($avant->contexte(), $apres->contexte());
        self::assertSame($avant->saisie()->surfaceBatie(), $apres->saisie()->surfaceBatie());
        self::assertSame($avant->saisie()->dpeAvant(), $apres->saisie()->dpeAvant());
    }

    public function testUnRapportAnonymiseSeSouvientDeLaDate(): void
    {
        $rapport = $this->rapport();

        $rapport->anonymiser($quand = new \DateTimeImmutable(self::QUAND));

        self::assertTrue($rapport->estAnonymise());
        self::assertEquals($quand, $rapport->anonymiseLe());
    }

    private function rapport(?Contact $contact = null, string $adresse = self::ADRESSE): Rapport
    {
        return Rapport::creer(
            '2026-0841',
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer($adresse),
            '/rapports/2026-0841.pdf',
            $contact,
        );
    }
}
