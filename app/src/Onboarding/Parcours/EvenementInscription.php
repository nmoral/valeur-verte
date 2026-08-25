<?php

declare(strict_types=1);

namespace App\Onboarding\Parcours;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement du parcours d'inscription self-service (#111).
 *
 * Trace d'une étape franchie pendant l'inscription : quelle visite (`sessionId`,
 * anonyme et éphémère — cf. {@see SessionParcoursInscription}), quelle étape,
 * quand. **Aucune donnée personnelle** : ni IP, ni email, ni SIRET. La charge
 * utile (`donnees`) est minimale et ne sert qu'à qualifier l'étape. La rétention
 * est courte : les événements anciens sont purgés périodiquement.
 *
 * Contrairement à l'événement widget (#90), il ne porte **ni token de page ni
 * tenant** : l'inscription est pré-compte, rien ne la rattache encore à un pro.
 */
#[ORM\Entity(repositoryClass: EvenementInscriptionRepository::class)]
#[ORM\Table(name: 'evenement_inscription')]
#[ORM\Index(name: 'idx_evenement_inscription_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_evenement_inscription_type_survenu', columns: ['type', 'survenu_le'])]
class EvenementInscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $sessionId;

    #[ORM\Column(enumType: TypeEvenementInscription::class, length: 32)]
    private TypeEvenementInscription $type;

    #[ORM\Column]
    private \DateTimeImmutable $survenuLe;

    /**
     * Charge utile minimale et non personnelle, en JSONB.
     *
     * @var array<string, scalar|null>
     */
    #[ORM\Column(type: Types::JSON, options: ['jsonb' => true])]
    private array $donnees;

    /**
     * @param array<string, scalar|null> $donnees
     */
    private function __construct(
        string $sessionId,
        TypeEvenementInscription $type,
        \DateTimeImmutable $survenuLe,
        array $donnees,
    ) {
        $this->sessionId = $sessionId;
        $this->type = $type;
        $this->survenuLe = $survenuLe;
        $this->donnees = $donnees;
    }

    /**
     * @param array<string, scalar|null> $donnees
     */
    public static function survenu(
        string $sessionId,
        TypeEvenementInscription $type,
        \DateTimeImmutable $survenuLe,
        array $donnees = [],
    ): self {
        return new self($sessionId, $type, $survenuLe, $donnees);
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function type(): TypeEvenementInscription
    {
        return $this->type;
    }

    public function survenuLe(): \DateTimeImmutable
    {
        return $this->survenuLe;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function donnees(): array
    {
        return $this->donnees;
    }
}
