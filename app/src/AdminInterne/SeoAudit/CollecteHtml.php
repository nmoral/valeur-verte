<?php

declare(strict_types=1);

namespace App\AdminInterne\SeoAudit;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Récupère une URL et en fait une {@see PageAuditee} (#345). Volontairement **sans
 * suivre les redirections** (`max_redirects: 0`) : une URL publique censée être
 * canonique doit répondre `200`, pas rediriger — l'audit doit la voir telle quelle
 * (règle `code-http`). Un statut d'erreur ne lève pas d'exception (`getContent(false)`),
 * et une panne réseau (DNS, connexion refusée) est ramenée à un code `0`, que la règle
 * `code-http` traitera comme une erreur — l'audit ne s'interrompt jamais sur une page.
 */
final readonly class CollecteHtml
{
    public function __construct(private HttpClientInterface $client)
    {
    }

    public function recuperer(string $url): PageAuditee
    {
        try {
            $reponse = $this->client->request('GET', $url, ['max_redirects' => 0]);
            $code = $reponse->getStatusCode();
            $html = $reponse->getContent(false);
        } catch (TransportExceptionInterface) {
            return new PageAuditee($url, 0, '');
        }

        return new PageAuditee($url, $code, $html);
    }
}
