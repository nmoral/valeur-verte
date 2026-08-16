<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Port d'envoi d'email transactionnel (#108). L'implémentation de production
 * s'appuie sur Brevo ({@see MailerBrevo}) ; les tests utilisent un double en
 * mémoire — aucun envoi réel n'est jamais déclenché sous test.
 */
interface Mailer
{
    public function envoyer(Email $email): void;
}
