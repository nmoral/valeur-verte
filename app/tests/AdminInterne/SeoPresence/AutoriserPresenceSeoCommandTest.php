<?php

declare(strict_types=1);

namespace App\Tests\AdminInterne\SeoPresence;

use App\AdminInterne\SeoPresence\Command\AutoriserPresenceSeoCommand;
use App\AdminInterne\SeoPresence\Google\AutorisationOAuthGoogle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AutoriserPresenceSeoCommandTest extends TestCase
{
    private function tester(MockHttpClient $client, string $id = 'client-123', string $secret = 'secret'): CommandTester
    {
        return new CommandTester(new AutoriserPresenceSeoCommand(new AutorisationOAuthGoogle($client, $id, $secret)));
    }

    public function testAfficheLUrlPuisRendLeRefreshToken(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"refresh_token":"1//new-refresh","access_token":"ya29.x"}'));
        $tester = $this->tester($client);
        $tester->setInputs(['4/0Acode']);

        $code = $tester->execute([]);
        $sortie = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $code);
        self::assertStringContainsString('https://accounts.google.com/o/oauth2/v2/auth', $sortie);
        self::assertStringContainsString('http://localhost/?code', $sortie);
        self::assertStringContainsString('.env.local', $sortie);
        self::assertStringContainsString('GSC_OAUTH_REFRESH_TOKEN=1//new-refresh', $sortie);
    }

    public function testSansIdentifiantsEchoue(): void
    {
        $tester = $this->tester(new MockHttpClient(), '');

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('GSC_OAUTH_CLIENT_ID', $tester->getDisplay());
    }

    public function testUnCodeRefuseEchoue(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('{"error":"invalid_grant"}', ['http_code' => 400]));
        $tester = $this->tester($client);
        $tester->setInputs(['mauvais-code']);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsStringIgnoringCase('échec', $tester->getDisplay());
        self::assertStringContainsString('http://localhost', $tester->getDisplay());
    }
}
