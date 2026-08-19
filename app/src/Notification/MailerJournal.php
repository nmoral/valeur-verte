<?php

declare(strict_types=1);

namespace App\Notification;

use Psr\Log\LoggerInterface;

/**
 * Envoi d'email de développement : journalise l'email (sujet, destinataire et
 * corps) au lieu de l'expédier, pour inspecter les liens (opt-in, confirmation)
 * sans passer par Brevo. Câblé uniquement en environnement `dev` — jamais en prod
 * (où {@see MailerBrevo} envoie réellement) ni en test (double en mémoire).
 */
final readonly class MailerJournal implements Mailer
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function envoyer(Email $email): void
    {
        $this->logger->info(\sprintf('[email dev] « %s » → %s', $email->sujet(), $email->destinataire()));
        $this->logger->info(\sprintf('[email dev corps] %s', $email->corpsHtml()));
    }
}
