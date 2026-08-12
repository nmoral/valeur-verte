<?php

declare(strict_types=1);

namespace App\Tenant;

use Doctrine\ORM\Mapping as ORM;

trait TenantAwareTrait
{
    #[ORM\Column(nullable: true)]
    private ?int $tenantId = null;

    public function getTenantId(): ?int
    {
        return $this->tenantId;
    }

    public function setTenantId(int $tenantId): void
    {
        $this->tenantId = $tenantId;
    }
}
