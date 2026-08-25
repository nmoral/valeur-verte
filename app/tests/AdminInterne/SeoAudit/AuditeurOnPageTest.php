<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\AuditeurOnPage;
use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleCodeHttp;
use App\AdminInterne\SeoAudit\Regle\RegleTitre;
use PHPUnit\Framework\TestCase;

final class AuditeurOnPageTest extends TestCase
{
    public function testAppliqueChaqueRegleEtRendUnConstatParRegle(): void
    {
        $auditeur = new AuditeurOnPage([new RegleTitre(), new RegleCodeHttp()]);
        $page = new PageAuditee('https://exemple.test/', 200, '<title>Une page tout à fait correcte ici</title>');

        $constats = $auditeur->auditer($page);

        self::assertCount(2, $constats);
        self::assertSame('titre', $constats[0]->regle);
        self::assertSame('code-http', $constats[1]->regle);
        self::assertSame(Gravite::Ok, $constats[0]->gravite);
        self::assertSame(Gravite::Ok, $constats[1]->gravite);
    }

    public function testRemonteLesGravitesDeChaqueRegle(): void
    {
        $auditeur = new AuditeurOnPage([new RegleTitre(), new RegleCodeHttp()]);
        $page = new PageAuditee('https://exemple.test/', 500, '<title>Court</title>');

        $constats = $auditeur->auditer($page);

        self::assertSame(Gravite::Alerte, $constats[0]->gravite);
        self::assertSame(Gravite::Erreur, $constats[1]->gravite);
    }
}
