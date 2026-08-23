<?php

declare(strict_types=1);

namespace App\Twig;

use App\Compte\EtatEssai;
use App\Compte\SuiviCompte;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Expose l'état d'essai du compte courant au shell back-office (#108), pour le
 * bandeau permanent « rapports / jours restants ». Renvoie null quand il n'y a
 * pas d'essai à afficher (aucun compte frappé) : le shell n'affiche alors rien.
 */
final class SuiviEssaiExtension extends AbstractExtension
{
    public function __construct(private readonly SuiviCompte $suivi)
    {
    }

    /**
     * @return array<int, TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('essai_courant', $this->essaiCourant(...)),
            new TwigFunction('compte_suspendu', $this->compteSuspendu(...)),
        ];
    }

    public function essaiCourant(): ?EtatEssai
    {
        return $this->suivi->essaiCourant();
    }

    /**
     * Le compte courant est-il suspendu (#322) ? Pilote le bandeau permanent qui
     * explique, sur l'espace pro, pourquoi les actions sont fermées.
     */
    public function compteSuspendu(): bool
    {
        return $this->suivi->estSuspendu();
    }
}
