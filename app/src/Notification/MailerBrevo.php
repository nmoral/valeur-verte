<?php

declare(strict_types=1);

namespace App\Notification;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Envoi d'email transactionnel via l'API HTTP Brevo (#108). La clé et l'adresse
 * expéditrice viennent de l'environnement (jamais versionnées). Un refus de Brevo
 * lève une exception : la relance n'est pas marquée envoyée et sera retentée.
 */
final readonly class MailerBrevo implements Mailer
{
    private const URL = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private string $expediteur,
    ) {
    }

    public function envoyer(Email $email): void
    {
        $charge = [
            'sender' => ['email' => $this->expediteur, 'name' => 'Valeur Verte'],
            'to' => [['email' => $email->destinataire()]],
            'subject' => $email->sujet(),
            'htmlContent' => $email->corpsHtml(),
        ];
        if (null !== $email->repondreA()) {
            $charge['replyTo'] = ['email' => $email->repondreA()];
        }

        $reponse = $this->client->request('POST', self::URL, [
            'headers' => [
                'api-key' => $this->apiKey,
                'accept' => 'application/json',
                'content-type' => 'application/json',
            ],
            'json' => $charge,
        ]);

        // Déclenche réellement la requête (HttpClient est paresseux) et vérifie
        // l'acceptation par Brevo.
        if ($reponse->getStatusCode() >= 400) {
            throw new \RuntimeException(\sprintf('Brevo a refusé l\'envoi (HTTP %d).', $reponse->getStatusCode()));
        }
    }
}
