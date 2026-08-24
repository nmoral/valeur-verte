<?php

declare(strict_types=1);

namespace App\Tests\Security\DeuxFacteurs;

use App\Security\DeuxFacteurs\CodeTotp;
use App\Security\DeuxFacteurs\SecondFacteur;
use App\Security\DeuxFacteurs\VerificateurSecondFacteur;
use PHPUnit\Framework\TestCase;

/**
 * Vérification d'un code à six chiffres (#312). Trois exigences se contredisent
 * et il faut les tenir ensemble :
 *
 * - **tolérer la dérive d'horloge** — le téléphone n'est pas à la seconde près, et
 *   un refus incompréhensible pousse à désactiver le second facteur ;
 * - **ne pas allonger la vie d'un code volé** — chaque fenêtre acceptée en plus
 *   est du temps offert à qui a lu le code par-dessus l'épaule ;
 * - **refuser le rejeu** — dans sa fenêtre, un code servi ne doit pas resservir.
 */
final class VerificateurSecondFacteurTest extends TestCase
{
    private const SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testLeCodeDeLaFenetreCouranteEstAccepte(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');

        self::assertTrue($this->verificateur()->verifier($facteur, $this->code($instant), $instant));
    }

    public function testUnTelephoneEnRetardDUneFenetreEstAccepte(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');

        // Le code affiché 30 s plus tôt : l'admin l'a lu puis tapé, la fenêtre a
        // tourné entre-temps. Le refuser rendrait le second facteur odieux.
        $code = $this->code($instant->modify('-30 seconds'));

        self::assertTrue($this->verificateur()->verifier($facteur, $code, $instant));
    }

    public function testUnTelephoneEnAvanceDUneFenetreEstAccepte(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');

        $code = $this->code($instant->modify('+30 seconds'));

        self::assertTrue($this->verificateur()->verifier($facteur, $code, $instant));
    }

    public function testAuDelaDUneFenetreLeCodeEstRefuse(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');

        // Deux fenêtres, c'est déjà une minute et demie de vie pour un code volé.
        self::assertFalse($this->verificateur()->verifier($facteur, $this->code($instant->modify('-60 seconds')), $instant));
        self::assertFalse($this->verificateur()->verifier($facteur, $this->code($instant->modify('+60 seconds')), $instant));
    }

    public function testUnCodeAccepteNeResertPas(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');
        $code = $this->code($instant);

        self::assertTrue($this->verificateur()->verifier($facteur, $code, $instant));
        // Même code, même fenêtre : c'est le rejeu d'un code lu par-dessus l'épaule.
        self::assertFalse($this->verificateur()->verifier($facteur, $code, $instant));
    }

    public function testAccepterUnCodeEnRetardBrulzAussiLesFenetresPrecedentes(): void
    {
        $facteur = $this->facteurActif();
        $instant = new \DateTimeImmutable('@1755990000');

        // On accepte le code de la fenêtre précédente…
        self::assertTrue($this->verificateur()->verifier($facteur, $this->code($instant->modify('-30 seconds')), $instant));
        // …et celui de la fenêtre courante devient inutilisable : accepter en
        // arrière puis en avant permettrait deux connexions avec deux codes captés
        // dans la même minute.
        self::assertFalse($this->verificateur()->verifier($facteur, $this->code($instant), $instant));
    }

    public function testUnCodeFauxEstRefuse(): void
    {
        $facteur = $this->facteurActif();

        self::assertFalse($this->verificateur()->verifier($facteur, '000000', new \DateTimeImmutable('@1755990000')));
    }

    public function testUnSecondFacteurNonActiveNaccepteAucunCode(): void
    {
        $facteur = new SecondFacteur(42, self::SECRET);
        $instant = new \DateTimeImmutable('@1755990000');

        // Un secret scanné mais jamais confirmé ne protège pas le compte : le
        // laisser ouvrir une session ferait passer un enrôlement abandonné pour
        // une protection active.
        self::assertFalse($this->verificateur()->verifier($facteur, $this->code($instant), $instant));
    }

    private function verificateur(): VerificateurSecondFacteur
    {
        return new VerificateurSecondFacteur();
    }

    private function facteurActif(): SecondFacteur
    {
        $facteur = new SecondFacteur(42, self::SECRET);
        $facteur->activer(new \DateTimeImmutable('@1755000000'));

        return $facteur;
    }

    private function code(\DateTimeImmutable $instant): string
    {
        return (new CodeTotp(self::SECRET))->pour($instant);
    }
}
