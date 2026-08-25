<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleTitre;
use PHPUnit\Framework\TestCase;

final class RegleTitreTest extends TestCase
{
    private function auditer(string $html): Gravite
    {
        return (new RegleTitre())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testUnTitreDeLongueurCorrecteEstOk(): void
    {
        $html = '<html><head><title>Estimation de la valeur verte d\'un bien</title></head><body></body></html>';

        self::assertSame(Gravite::Ok, $this->auditer($html));
    }

    public function testTitreAbsentEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<html><head></head><body></body></html>'));
    }

    public function testTitreVideEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<html><head><title>   </title></head><body></body></html>'));
    }

    public function testTitreTropCourtEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<html><head><title>Court</title></head><body></body></html>'));
    }

    public function testTitreTropLongEstUneAlerte(): void
    {
        $long = str_repeat('a', RegleTitre::LONGUEUR_MAX + 1);

        self::assertSame(Gravite::Alerte, $this->auditer('<html><head><title>'.$long.'</title></head><body></body></html>'));
    }

    public function testLeConstatPorteLeCodeDeLaRegle(): void
    {
        $constat = (new RegleTitre())->evaluer(new PageAuditee('https://exemple.test/', 200, '<title>Une page tout à fait correcte ici</title>'));

        self::assertSame('titre', $constat->regle);
        self::assertSame('titre', (new RegleTitre())->code());
    }
}
