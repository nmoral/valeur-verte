<?php

declare(strict_types=1);

namespace App\Tests\Notification;

use App\Notification\Email;
use App\Notification\MailerBrevo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Adaptateur Brevo (#108) : poste l'email à l'API transactionnelle, avec la clé
 * en en-tête. Testé sur un client HTTP simulé — aucun appel réseau réel.
 */
final class MailerBrevoTest extends TestCase
{
    public function testIlPosteLEmailALApiBrevoAvecLaCle(): void
    {
        $requetes = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$requetes): MockResponse {
            $requetes[] = ['methode' => $methode, 'url' => $url, 'options' => $options];

            return new MockResponse('{"messageId":"<abc>"}', ['http_code' => 201]);
        });

        (new MailerBrevo($client, 'clef-test', 'exp@vv.test'))
            ->envoyer(new Email('client@example.com', 'Votre essai', '<p>Bonjour</p>'));

        self::assertCount(1, $requetes);
        self::assertSame('POST', $requetes[0]['methode']);
        self::assertSame('https://api.brevo.com/v3/smtp/email', $requetes[0]['url']);

        $options = $requetes[0]['options'];
        $entetes = $options['headers'];
        self::assertIsArray($entetes);
        $entetesTexte = implode("\n", array_filter($entetes, 'is_string'));
        self::assertStringContainsStringIgnoringCase('api-key: clef-test', $entetesTexte);

        $body = $options['body'];
        self::assertIsString($body);
        $corps = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($corps);
        self::assertIsArray($corps['sender']);
        self::assertSame('exp@vv.test', $corps['sender']['email']);
        self::assertIsArray($corps['to']);
        self::assertIsArray($corps['to'][0]);
        self::assertSame('client@example.com', $corps['to'][0]['email']);
        self::assertSame('Votre essai', $corps['subject']);
        self::assertSame('<p>Bonjour</p>', $corps['htmlContent']);
    }

    public function testIlTransmetLAdresseDeReponseQuandElleEstPrecisee(): void
    {
        $requetes = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$requetes): MockResponse {
            $requetes[] = $options;

            return new MockResponse('{"messageId":"<abc>"}', ['http_code' => 201]);
        });

        (new MailerBrevo($client, 'clef', 'exp@vv.test'))
            ->envoyer(new Email('a@b.c', 'x', '<p>y</p>', 'repondre@vv.test'));

        $body = $requetes[0]['body'];
        self::assertIsString($body);
        $corps = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($corps);
        self::assertIsArray($corps['replyTo']);
        self::assertSame('repondre@vv.test', $corps['replyTo']['email']);
    }

    public function testSansAdresseDeReponseLePayloadNEnContientPas(): void
    {
        $requetes = [];
        $client = new MockHttpClient(static function (string $methode, string $url, array $options) use (&$requetes): MockResponse {
            $requetes[] = $options;

            return new MockResponse('{"messageId":"<abc>"}', ['http_code' => 201]);
        });

        (new MailerBrevo($client, 'clef', 'exp@vv.test'))
            ->envoyer(new Email('a@b.c', 'x', '<p>y</p>'));

        $body = $requetes[0]['body'];
        self::assertIsString($body);
        $corps = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($corps);
        self::assertArrayNotHasKey('replyTo', $corps);
    }

    public function testUnRefusDeBrevoLeveUneException(): void
    {
        $client = new MockHttpClient(new MockResponse('erreur', ['http_code' => 400]));

        $this->expectException(\RuntimeException::class);

        (new MailerBrevo($client, 'clef', 'exp@vv.test'))
            ->envoyer(new Email('a@b.c', 'x', '<p>y</p>'));
    }
}
