<?php

declare(strict_types=1);

namespace App\Notification;

/**
 * Email à envoyer (#108/#109) : destinataire, sujet, corps HTML et adresse de
 * réponse optionnelle. Value object minimal, indépendant du fournisseur d'envoi
 * (port {@see Mailer}). Le `repondreA` permet aux emails non transactionnels
 * (relance conversationnelle #109) de recevoir les réponses dans une vraie boîte.
 */
final readonly class Email
{
    public function __construct(
        private string $destinataire,
        private string $sujet,
        private string $corpsHtml,
        private ?string $repondreA = null,
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

    public function repondreA(): ?string
    {
        return $this->repondreA;
    }
}
