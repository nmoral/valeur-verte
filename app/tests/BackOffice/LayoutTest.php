<?php

declare(strict_types=1);

namespace App\Tests\BackOffice;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Twig\Environment;

final class LayoutTest extends KernelTestCase
{
    public function testBackOfficeBaseTemplateRendersTheApplicationShell(): void
    {
        self::bootKernel();
        $twig = self::getContainer()->get(Environment::class);
        self::assertInstanceOf(Environment::class, $twig);

        $html = $twig->render('back_office/base.html.twig', [
            'page_title' => 'Tableau de bord',
        ]);

        // Coquille applicative (mockup back-office) : marque, navigation, zone principale.
        self::assertStringContainsString('Valeur Verte', $html);
        self::assertStringContainsString('<aside', $html);
        self::assertStringContainsString('<main', $html);
        self::assertStringContainsString('Configuration', $html);
        self::assertStringContainsString('Compte', $html);
        // Titre de page injecté par le bloc.
        self::assertStringContainsString('Tableau de bord', $html);
        // Feuille de style du design system reliée.
        self::assertStringContainsString('back-office.css', $html);

        // Le menu pointe vers de vraies routes, pas des liens morts.
        self::assertStringContainsString('href="/rapports"', $html);
        self::assertStringContainsString('href="/couts"', $html);
        self::assertStringContainsString('href="/bouquets"', $html);
        self::assertStringContainsString('href="/marque"', $html);
        self::assertStringNotContainsString('href="#"', $html);
    }
}
