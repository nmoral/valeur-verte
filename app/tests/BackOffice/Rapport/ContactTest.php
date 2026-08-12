<?php

declare(strict_types=1);

namespace App\Tests\BackOffice\Rapport;

use App\BackOffice\Rapport\Contact;
use PHPUnit\Framework\TestCase;

final class ContactTest extends TestCase
{
    public function testUnContactPorteUnNomEtAuMoinsUnMoyenDeContact(): void
    {
        $contact = Contact::creer('Mme Cazeneuve', 'cazeneuve@example.com', '05 61 00 00 00');

        self::assertSame('Mme Cazeneuve', $contact->nom());
        self::assertSame('cazeneuve@example.com', $contact->email());
        self::assertSame('05 61 00 00 00', $contact->telephone());
    }

    public function testLEmailSeulSuffit(): void
    {
        $contact = Contact::creer('M. Fabre', 'fabre@example.com', null);

        self::assertSame('fabre@example.com', $contact->email());
        self::assertNull($contact->telephone());
    }

    public function testLeTelephoneSeulSuffit(): void
    {
        $contact = Contact::creer('M. Fabre', null, '05 61 00 00 00');

        self::assertNull($contact->email());
        self::assertSame('05 61 00 00 00', $contact->telephone());
    }

    public function testLeNomEstDebarrasseDesEspacesSuperflus(): void
    {
        $contact = Contact::creer('  M. Fabre  ', 'fabre@example.com', null);

        self::assertSame('M. Fabre', $contact->nom());
    }

    public function testLEmailEstDebarrasseDesEspacesSuperflus(): void
    {
        $contact = Contact::creer('M. Fabre', '  fabre@example.com  ', null);

        self::assertSame('fabre@example.com', $contact->email());
    }

    public function testUnEmailReduitADesEspacesEstIgnore(): void
    {
        // Un email « vide » (que des espaces) ne compte pas comme moyen de contact.
        $contact = Contact::creer('M. Fabre', '   ', '05 61 00 00 00');

        self::assertNull($contact->email());
        self::assertSame('05 61 00 00 00', $contact->telephone());
    }

    public function testUnNomVideEstRefuse(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Contact::creer('   ', 'fabre@example.com', null);
    }

    public function testUnContactSansAucunMoyenDeContactEstRefuse(): void
    {
        // Un lead sans email ni téléphone n'est pas joignable : il n'a pas de sens.
        $this->expectException(\InvalidArgumentException::class);

        Contact::creer('M. Fabre', null, null);
    }
}
