<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Email transactionnel à envoyer (#108) : destinataire, sujet, corps HTML. Value
 * object minimal, indépendant du fournisseur d'envoi (port {@see Mailer}).
 */
final readonly class Email
{
    public function __construct(
        private string $destinataire,
        private string $sujet,
        private string $corpsHtml,
    ) {
    }

    public function destinataire(): string
    {
        return $this->destinataire;
    }

    public function sujet(): string
    {
        return $this->sujet;
    }

    public function corpsHtml(): string
    {
        return $this->corpsHtml;
    }
}
