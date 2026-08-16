<?php

declare(strict_types=1);

namespace App\Tests\Notification\Double;

use App\Notification\Email;
use App\Notification\Mailer;

/**
 * Double d'envoi qui échoue systématiquement : vérifie que les envois best-effort
 * (relance conversationnelle #109) n'interrompent jamais le flux appelant.
 */
final class MailerQuiEchoue implements Mailer
{
    public function envoyer(Email $email): void
    {
        throw new \RuntimeException('Envoi indisponible (test).');
    }
}
