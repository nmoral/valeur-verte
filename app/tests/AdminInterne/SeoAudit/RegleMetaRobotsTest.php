<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleMetaRobots;
use PHPUnit\Framework\TestCase;

final class RegleMetaRobotsTest extends TestCase
{
    private function auditer(string $head): Gravite
    {
        $html = '<html><head>'.$head.'</head><body></body></html>';

        return (new RegleMetaRobots())->evaluer(new PageAuditee('https://exemple.test/', 200, $html))->gravite;
    }

    public function testAbsenceDeMetaRobotsEstOk(): void
    {
        // Pas de directive = indexable par défaut : rien à signaler sur une page publique.
        self::assertSame(Gravite::Ok, $this->auditer(''));
    }

    public function testDirectiveIndexEstOk(): void
    {
        self::assertSame(Gravite::Ok, $this->auditer('<meta name="robots" content="index, follow">'));
    }

    public function testNoindexSurUnePagePubliqueEstUneErreur(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<meta name="robots" content="noindex, follow">'));
    }

    public function testNoindexInsensibleALaCasse(): void
    {
        self::assertSame(Gravite::Erreur, $this->auditer('<meta name="robots" content="NOINDEX">'));
    }

    public function testLeConstatPorteLeCode(): void
    {
        self::assertSame('meta-robots', (new RegleMetaRobots())->code());
    }
}
