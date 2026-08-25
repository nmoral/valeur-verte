<?php

declare(strict_types=1);

namespace App\Tests\Compte;

use App\Compte\EmpreinteEffacement;
use PHPUnit\Framework\TestCase;

/**
 * Empreinte d'effacement (#332) : signe l'adresse **canonique** (#331) d'un compte
 * effacé avec le secret applicatif, afin de reconnaître une réinscription sans
 * jamais conserver l'adresse en clair (RGPD). L'entrée étant une adresse — donc
 * devinable par dictionnaire —, l'empreinte est un HMAC (secret hors base), pas un
 * simple SHA-256 : un dump qui fuite reste inexploitable.
 */
final class EmpreinteEffacementTest extends TestCase
{
    public function testLEmpreinteEstStableEtCorrespondPourUnEmailCanonique(): void
    {
        $empreintes = new EmpreinteEffacement('un-secret');

        $empreinte = $empreintes->pour('pro@exemple.fr');

        self::assertNotSame('', $empreinte);
        self::assertSame($empreinte, $empreintes->pour('pro@exemple.fr'));
        self::assertTrue($empreintes->correspond('pro@exemple.fr', $empreinte));
    }

    public function testUneAutreAdresseNeCorrespondPas(): void
    {
        $empreintes = new EmpreinteEffacement('un-secret');

        self::assertFalse($empreintes->correspond('autre@exemple.fr', $empreintes->pour('pro@exemple.fr')));
    }

    public function testUneEmpreinteFalsifieeOuVideEstRejetee(): void
    {
        $empreintes = new EmpreinteEffacement('un-secret');
        $empreinte = $empreintes->pour('pro@exemple.fr');

        self::assertFalse($empreintes->correspond('pro@exemple.fr', $empreinte.'x'));
        self::assertFalse($empreintes->correspond('pro@exemple.fr', ''));
    }

    public function testUnAutreSecretProduitUneAutreEmpreinte(): void
    {
        self::assertNotSame(
            (new EmpreinteEffacement('secret-a'))->pour('pro@exemple.fr'),
            (new EmpreinteEffacement('secret-b'))->pour('pro@exemple.fr'),
        );
    }

    public function testLEmpreinteNeRevelePasLAdresseEnClair(): void
    {
        $empreinte = (new EmpreinteEffacement('un-secret'))->pour('pro@exemple.fr');

        self::assertStringNotContainsString('pro@exemple.fr', $empreinte);
        self::assertStringNotContainsString('exemple.fr', $empreinte);
    }
}
