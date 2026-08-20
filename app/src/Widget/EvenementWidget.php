<?php

declare(strict_types=1);

namespace App\Widget;

use App\Tenant\TenantAwareInterface;
use App\Tenant\TenantAwareTrait;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Événement du parcours widget (#90, épic #6).
 *
 * Trace d'une étape franchie sur une page publique : quelle page (`token`),
 * quelle visite (`sessionId`, anonyme et éphémère — cf.
 * {@see SessionParcoursWidget}), quelle étape, quand. Rattaché au tenant
 * propriétaire de la page pour alimenter plus tard le funnel du pro (#5) et
 * l'analytics interne (#7).
 *
 * **Aucune donnée personnelle** : ni IP, ni email, ni adresse du bien. La charge
 * utile (`donnees`) est minimale et ne sert qu'à qualifier l'étape (par exemple
 * « estimation disponible ou non »). La rétention est courte : les événements
 * anciens sont purgés périodiquement.
 */
#[ORM\Entity(repositoryClass: EvenementWidgetRepository::class)]
#[ORM\Table(name: 'evenement_widget')]
#[ORM\Index(name: 'idx_evenement_widget_session', columns: ['session_id'])]
#[ORM\Index(name: 'idx_evenement_widget_token_survenu', columns: ['token', 'survenu_le'])]
class EvenementWidget implements TenantAwareInterface
{
    use TenantAwareTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 32)]
    private string $token;

    #[ORM\Column(length: 32)]
    private string $sessionId;

    #[ORM\Column(enumType: TypeEvenementWidget::class, length: 32)]
    private TypeEvenementWidget $type;

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
        int $tenantId,
        string $token,
        string $sessionId,
        TypeEvenementWidget $type,
        \DateTimeImmutable $survenuLe,
        array $donnees,
    ) {
        $this->setTenantId($tenantId);
        $this->token = $token;
        $this->sessionId = $sessionId;
        $this->type = $type;
        $this->survenuLe = $survenuLe;
        $this->donnees = $donnees;
    }

    /**
     * @param array<string, scalar|null> $donnees
     */
    public static function survenu(
        int $tenantId,
        string $token,
        string $sessionId,
        TypeEvenementWidget $type,
        \DateTimeImmutable $survenuLe,
        array $donnees = [],
    ): self {
        return new self($tenantId, $token, $sessionId, $type, $survenuLe, $donnees);
    }

    public function token(): string
    {
        return $this->token;
    }

    public function sessionId(): string
    {
        return $this->sessionId;
    }

    public function type(): TypeEvenementWidget
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
