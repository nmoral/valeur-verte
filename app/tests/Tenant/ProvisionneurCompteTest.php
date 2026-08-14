<?php

declare(strict_types=1);

namespace App\Tests\Tenant;

use App\Tenant\ConfigurationParDefaut;
use App\Tenant\ProvisionneurCompte;
use PHPUnit\Framework\TestCase;

/**
 * Protocole de provisionnement à la création de compte : chaque configuration
 * par défaut (bouquets, et demain marque, coûts…) est appliquée au nouveau tenant
 * sans action du pro. Le protocole est ouvert à l'extension : ajouter une
 * configuration = implémenter l'interface, sans toucher au provisionneur.
 */
final class ProvisionneurCompteTest extends TestCase
{
    public function testIlAppliqueChaqueConfigurationAuTenant(): void
    {
        $premiere = new class implements ConfigurationParDefaut {
            public ?int $tenantProvisionne = null;

            public function provisionner(int $tenantId): void
            {
                $this->tenantProvisionne = $tenantId;
            }
        };
        $seconde = new class implements ConfigurationParDefaut {
            public ?int $tenantProvisionne = null;

            public function provisionner(int $tenantId): void
            {
                $this->tenantProvisionne = $tenantId;
            }
        };

        (new ProvisionneurCompte([$premiere, $seconde]))->provisionner(42);

        self::assertSame(42, $premiere->tenantProvisionne);
        self::assertSame(42, $seconde->tenantProvisionne);
    }
}
