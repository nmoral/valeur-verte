<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use App\BackOffice\Rapport\Contact;
use App\BackOffice\Rapport\Dpe;
use App\BackOffice\Rapport\Rapport;
use App\BackOffice\Rapport\RapportRepository;
use App\BackOffice\Rapport\Source;
use App\Tests\BackOffice\Rapport\InstantaneRapportMother;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RapportsControllerTest extends WebTestCase
{
    public function testLaListeUniqueAfficheRapportsEtLeadsAvecContactEventuel(): void
    {
        $client = static::createClient();
        $repository = self::getContainer()->get(RapportRepository::class);
        self::assertInstanceOf(RapportRepository::class, $repository);

        $repository->enregistrer(Rapport::creer(
            '2026-0841',
            new \DateTimeImmutable('2026-08-01 09:00:00'),
            Source::Audit,
            InstantaneRapportMother::creer(),
            '/rapports/2026-0841.pdf',
        ));
        $repository->enregistrer(Rapport::creer(
            '2026-0900',
            new \DateTimeImmutable('2026-08-10 09:00:00'),
            Source::Widget,
            InstantaneRapportMother::creer(
                adresse: '4 chemin de la Garrigue, Montgiscard (31)',
                dpeAvant: Dpe::F,
                dpeApres: Dpe::C,
                montantEuros: 40700,
            ),
            '/rapports/2026-0900.pdf',
            Contact::creer('Mme Cazeneuve', 'cazeneuve@example.com', null),
        ));

        $client->request('GET', '/rapports');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Rapports & leads');
        self::assertSelectorTextContains('nav.side a.on', 'Rapports & leads');

        $html = (string) $client->getResponse()->getContent();
        // Les deux entrées, dans une seule liste.
        self::assertStringContainsString('2026-0841', $html);
        self::assertStringContainsString('12 rue des Écoles', $html);
        self::assertStringContainsString('2026-0900', $html);
        // Le lead affiche son contact ; le rapport sans contact ne l'invente pas.
        self::assertStringContainsString('Mme Cazeneuve', $html);
        // Valeur verte et lien PDF stable.
        self::assertStringContainsString('/rapports/2026-0900.pdf', $html);
    }
    public function testLaPageNePrometPlusUneConservationIndefinie(): void
    {
        // La rétention D10 (#68) rend « consultable indéfiniment » faux : le
        // contact et l'adresse précise s'effacent à 24 mois, le rapport part à 3 ans.
        $client = static::createClient();
        $client->request('GET', '/rapports');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('indéfiniment', $html);
        self::assertStringContainsString('24 mois', $html);
    }
}
