<?php

declare(strict_types=1);

namespace App\Security\DeuxFacteurs;

use Doctrine\ORM\Mapping as ORM;

/**
 * Code de secours du second facteur (#312) : la sortie de secours de l'admin qui
 * a perdu son téléphone. À usage unique, et stocké **haché** — contrairement au
 * secret TOTP, il n'est que comparé, jamais rejoué.
 */
#[ORM\Entity(repositoryClass: CodeDeSecoursRepository::class)]
#[ORM\Table(name: 'code_de_secours')]
#[ORM\UniqueConstraint(name: 'uniq_code_de_secours_empreinte', columns: ['empreinte'])]
#[ORM\Index(name: 'idx_code_de_secours_user', columns: ['user_id'])]
class CodeDeSecours
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column]
    private int $userId;

    #[ORM\Column(length: 64)]
    private string $empreinte;

    #[ORM\Column(name: 'utilise_le', nullable: true)]
    private ?\DateTimeImmutable $utiliseLe = null;

    public function __construct(int $userId, string $empreinte)
    {
        $this->userId = $userId;
        $this->empreinte = $empreinte;
    }

    public function estUtilisable(): bool
    {
        return null === $this->utiliseLe;
    }

    public function marquerUtilise(\DateTimeImmutable $le): void
    {
        $this->utiliseLe = $le;
    }
}
