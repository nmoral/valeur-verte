<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Constat;
use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\RapportAuditSeo;
use App\AdminInterne\SeoAudit\ResultatPage;
use PHPUnit\Framework\TestCase;

final class RapportAuditSeoTest extends TestCase
{
    public function testCompteLesConstatsParGraviteSurPagesEtGlobaux(): void
    {
        $rapport = new RapportAuditSeo(
            [
                new ResultatPage('https://a.test/', 200, [
                    Constat::ok('titre', ''),
                    Constat::alerte('canonical', 'absente'),
                ]),
                new ResultatPage('https://b.test/', 200, [
                    Constat::erreur('titre', 'absent'),
                ]),
            ],
            [Constat::ok('robots-txt', '')],
        );

        self::assertSame(2, $rapport->compte(Gravite::Ok));
        self::assertSame(1, $rapport->compte(Gravite::Alerte));
        self::assertSame(1, $rapport->compte(Gravite::Erreur));
        self::assertSame(2, $rapport->nombreUrls());
    }

    public function testUnAuditToutVertDonneUnScoreDeCent(): void
    {
        $rapport = new RapportAuditSeo(
            [new ResultatPage('https://a.test/', 200, [Constat::ok('titre', ''), Constat::ok('canonical', '')])],
            [],
        );

        self::assertSame(100, $rapport->score());
    }

    public function testUnAuditVideDonneUnScoreDeCent(): void
    {
        self::assertSame(100, (new RapportAuditSeo([], []))->score());
    }

    public function testLesGravitesPenalisentLeScore(): void
    {
        // 1 ok (0), 1 alerte (1), 1 erreur (3) → pénalité 4 sur un max de 9 → 100*(1-4/9)=56 (arrondi).
        $rapport = new RapportAuditSeo(
            [new ResultatPage('https://a.test/', 200, [
                Constat::ok('titre', ''),
                Constat::alerte('canonical', ''),
                Constat::erreur('code-http', ''),
            ])],
            [],
        );

        self::assertSame(56, $rapport->score());
    }
}
