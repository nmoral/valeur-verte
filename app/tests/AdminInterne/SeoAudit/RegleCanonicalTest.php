<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleCanonical;
use PHPUnit\Framework\TestCase;

final class RegleCanonicalTest extends TestCase
{
    private function auditer(string $head): Gravite
    {
        $html = '<html><head>'.$head.'</head><body></body></html>';

        return (new RegleCanonical())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testUneCanonicalUniqueEtRenseigneeEstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer('<link rel="canonical" href="https://exemple.test/">'));
    }

    public function testCanonicalAbsenteEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer(''));
    }

    public function testCanonicalVideEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<link rel="canonical" href="">'));
    }

    public function testPlusieursCanonicalEstUneAlerte(): void
    {
        self::assertSame(Gravite::Alerte, $this->auditer('<link rel="canonical" href="https://a.test/"><link rel="canonical" href="https://b.test/">'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('canonical', (new RegleCanonical())->code());
    }
}
