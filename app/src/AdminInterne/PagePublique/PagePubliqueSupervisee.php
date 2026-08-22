<?php

declare(strict_types=1);

namespace App\AdminInterne\PagePublique;

/**
 * Une page publique vue depuis l'admin interne (#268, épic #6) : de quoi décider
 * s'il faut couper. Deux interrupteurs distincts s'y lisent — celui du **pro**
 * (`activeParLePro`, #38) et la **suspension** admin/automatique — plus les traces
 * de l'anti-abus réactif : dernière alerte, durcissement du défi en cours.
 *
 * Modèle de lecture, sans comportement métier : les décisions vivent dans
 * {@see AdministrationPagesPubliques}.
 */
final readonly class PagePubliqueSupervisee
{
    public function __construct(
        private string $token,
        private string $slug,
        private int $tenantId,
        private bool $activeParLePro,
        private ?\DateTimeImmutable $suspendueLe,
        private ?\DateTimeImmutable $alerteeLe,
        private ?\DateTimeImmutable $challengeRenforceJusqua,
    ) {
    }

    public function token(): string
    {
        return $this->token;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function tenantId(): int
    {
        return $this->tenantId;
    }

    public function estSuspendue(): bool
    {
        return null !== $this->suspendueLe;
    }

    public function suspendueLe(): ?\DateTimeImmutable
    {
        return $this->suspendueLe;
    }

    public function alerteeLe(): ?\DateTimeImmutable
    {
        return $this->alerteeLe;
    }

    /**
     * Vrai tant que le durcissement du défi anti-bot décidé après un pic court encore.
     */
    public function defiDurci(\DateTimeImmutable $maintenant): bool
    {
        return null !== $this->challengeRenforceJusqua && $this->challengeRenforceJusqua > $maintenant;
    }

    /**
     * L'état de service tel qu'il se lit d'un coup d'œil. La suspension prime :
     * c'est elle que l'admin a la main pour lever.
     */
    public function etatDeService(): string
    {
        if ($this->estSuspendue()) {
            return 'Suspendue';
        }

        return $this->activeParLePro ? 'En service' : 'Retirée par le pro';
    }
}
