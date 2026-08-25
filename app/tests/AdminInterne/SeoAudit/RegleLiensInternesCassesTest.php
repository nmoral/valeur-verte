<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleLiensInternesCasses;
use PHPUnit\Framework\TestCase;

final class RegleLiensInternesCassesTest extends TestCase
{
    public function testAucunLienCasseEstOk(): void
    {
        $pages = [
            new PageAuditee('https://site.test/a', 200, '<a href="/b">vers b</a>'),
            new PageAuditee('https://site.test/b', 200, '<p>ok</p>'),
        ];

        $constats = (new RegleLiensInternesCasses())->evaluer($pages);

        self::assertCount(1, $constats);
        self::assertSame('liens-internes', $constats[0]->regle);
        self::assertSame(Gravite::Ok, $constats[0]->gravite);
    }

    public function testUnLienVersUnePageEnErreurDuPerimetreEstUneErreur(): void
    {
        $pages = [
            new PageAuditee('https://site.test/a', 200, '<a href="/b">vers b</a><a href="/manquante">morte</a>'),
            new PageAuditee('https://site.test/b', 200, '<p>ok</p>'),
            new PageAuditee('https://site.test/manquante', 404, ''),
        ];

        $constats = (new RegleLiensInternesCasses())->evaluer($pages);

        self::assertSame(Gravite::Erreur, $constats[0]->gravite);
        self::assertStringContainsString('https://site.test/manquante', $constats[0]->message);
    }

    public function testUnLienExterneHorsPerimetreEstIgnore(): void
    {
        $pages = [
            new PageAuditee('https://site.test/a', 200, '<a href="https://ailleurs.test/x">externe</a>'),
        ];

        $constats = (new RegleLiensInternesCasses())->evaluer($pages);

        self::assertSame(Gravite::Ok, $constats[0]->gravite);
    }
}
