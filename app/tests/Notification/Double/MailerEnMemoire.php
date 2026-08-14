<?php

declare(strict_types=1);

namespace App\Tests\Notification\Double;

use App\Notification\Email;
use App\Notification\Mailer;

/**
 * Double d'envoi d'email pour les tests : capture les emails en mémoire, sans
 * jamais rien envoyer. Public dans le conteneur de test pour l'inspection.
 */
final class MailerEnMemoire implements Mailer
{
    /**
     * @var list<Email>
     */
    private array $envoyes = [];

    public function envoyer(Email $email): void
    {
        $this->envoyes[] = $email;
    }

    /**
     * @return list<Email>
     */
    public function envoyes(): array
    {
        return $this->envoyes;
    }
}
