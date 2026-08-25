<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoAudit;

use App\AdminInterne\SeoAudit\AuditeurOnPage;
use App\AdminInterne\SeoAudit\AuditeurSeo;
use App\AdminInterne\SeoAudit\CollecteHtml;
use App\AdminInterne\SeoAudit\Command\AuditSeoCommand;
use App\AdminInterne\SeoAudit\Regle\RegleTitre;
use App\AdminInterne\SeoAudit\RapportSeoRepository;
use App\AdminInterne\SeoAudit\SourceUrls;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AuditSeoCommandTest extends KernelTestCase
{
    public function testAuditePersisteUnRapportEtAfficheLeScore(): void
    {
        self::bootKernel();
        $repository = static::getContainer()->get(RapportSeoRepository::class);
        self::assertInstanceOf(RapportSeoRepository::class, $repository);

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('<title>Une page tout à fait correcte ici</title>', ['http_code' => 200]));
        $source = new class implements SourceUrls {
            public function urls(): array
            {
                return ['https://valeurverte.localhost/'];
            }
        };
        $auditeur = new AuditeurSeo(
            $source,
            new CollecteHtml($client),
            new AuditeurOnPage([new RegleTitre()]),
            [],
            'valeurverte.localhost',
        );

        $command = new AuditSeoCommand($auditeur, $repository, new MockClock('2026-08-25 04:00:00'));
        $tester = new CommandTester($command);
        $code = $tester->execute([]);

        self::assertSame(0, $code);
        self::assertStringContainsString('score', mb_strtolower($tester->getDisplay()));

        $dernier = $repository->dernier();
        self::assertNotNull($dernier);
        self::assertEquals(new \DateTimeImmutable('2026-08-25 04:00:00'), $dernier->executeLe());
        self::assertSame(1, $dernier->nombreUrls());
    }
}
