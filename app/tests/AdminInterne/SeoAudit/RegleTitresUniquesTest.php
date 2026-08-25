<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleTitresUniques;
use PHPUnit\Framework\TestCase;

final class RegleTitresUniquesTest extends TestCase
{
    private function page(string $url, string $titre): PageAuditee
    {
        return new PageAuditee($url, 200, '<html><head><title>'.$titre.'</title></head><body></body></html>');
    }

    public function testDesTitresTousDistinctsSontOk(): void
    {
        $constats = (new RegleTitresUniques())->evaluer([
            $this->page('https://a.test/', 'Page A'),
            $this->page('https://b.test/', 'Page B'),
        ]);

        self::assertCount(1, $constats);
        self::assertSame('titres-uniques', $constats[0]->regle);
        self::assertSame(Gravite::Ok, $constats[0]->gravite);
    }

    public function testUnTitreDupliqueEstUneAlerte(): void
    {
        $constats = (new RegleTitresUniques())->evaluer([
            $this->page('https://a.test/', 'Même titre'),
            $this->page('https://b.test/', 'Même titre'),
            $this->page('https://c.test/', 'Autre'),
        ]);

        self::assertCount(1, $constats);
        self::assertSame(Gravite::Alerte, $constats[0]->gravite);
        self::assertStringContainsString('Même titre', $constats[0]->message);
    }

    public function testLesTitresVidesNeSontPasComptesCommeDoublons(): void
    {
        $constats = (new RegleTitresUniques())->evaluer([
            $this->page('https://a.test/', ''),
            $this->page('https://b.test/', ''),
        ]);

        self::assertSame(Gravite::Ok, $constats[0]->gravite);
    }
}
