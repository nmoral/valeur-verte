<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\Gravite;
use App\AdminInterne\SeoAudit\PageAuditee;
use App\AdminInterne\SeoAudit\Regle\RegleDescriptionsUniques;
use PHPUnit\Framework\TestCase;

final class RegleDescriptionsUniquesTest extends TestCase
{
    private function page(string $url, string $description): PageAuditee
    {
        $head = '' === $description ? '' : '<meta name="description" content="'.$description.'">';

        return new PageAuditee($url, 200, '<html><head>'.$head.'</head><body></body></html>');
    }

    public function testDesDescriptionsDistinctesSontOk(): void
    {
        $constats = (new RegleDescriptionsUniques())->evaluer([
            $this->page('https://a.test/', 'Description A'),
            $this->page('https://b.test/', 'Description B'),
        ]);

        self::assertCount(1, $constats);
        self::assertSame('descriptions-uniques', $constats[0]->regle);
        self::assertSame(Gravite::Ok, $constats[0]->gravite);
    }

    public function testUneDescriptionDupliqueeEstUneAlerte(): void
    {
        $constats = (new RegleDescriptionsUniques())->evaluer([
            $this->page('https://a.test/', 'Même description'),
            $this->page('https://b.test/', 'Même description'),
        ]);

        self::assertSame(Gravite::Alerte, $constats[0]->gravite);
    }
}
