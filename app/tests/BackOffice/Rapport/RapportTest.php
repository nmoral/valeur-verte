<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\Source;
use PHPUnit\Framework\TestCase;

final class RapportTest extends TestCase
{
    public function testUnRapportExposeSesCaracteristiques(): void
    {
        $rapport = $this->rapport();

        self::assertSame('2026-0841', $rapport->reference());
        self::assertEquals(new \DateTimeImmutable('2026-08-01 09:00:00'), $rapport->creeLe());
        self::assertSame(Source::Audit, $rapport->source());
        self::assertSame('12 rue des Écoles, Baziège (31)', $rapport->adresse());
        self::assertSame(Dpe::E, $rapport->dpeAvant());
        self::assertSame(Dpe::C, $rapport->dpeApres());
        self::assertSame(52610, $rapport->valeurVerteEstimee());
        self::assertSame('/rapports/2026-0841.pdf', $rapport->pdfUrl());
    }

    public function testUnRapportSansContactNEstPasUnLead(): void
    {
        $rapport = $this->rapport();

        self::assertNull($rapport->contact());
        self::assertFalse($rapport->estUnLead());
    }

    public function testUnRapportAvecContactEstUnLead(): void
    {
        $contact = Contact::creer('Mme Cazeneuve', 'cazeneuve@example.com', null);

        $rapport = $this->rapport($contact);

        self::assertSame($contact, $rapport->contact());
        self::assertTrue($rapport->estUnLead());
    }

    public function testUneReferenceVideEstRefusee(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->rapport(reference: '  ');
    }

    private function rapport(?Contact $contact = null, string $reference = '2026-0841'): Rapport
    {
        return Rapport::creer(
            $reference,
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            '12 rue des Écoles, Baziège (31)',
            Dpe::E,
            Dpe::C,
            52610,
            '/rapports/2026-0841.pdf',
            $contact,
        );
    }
}
