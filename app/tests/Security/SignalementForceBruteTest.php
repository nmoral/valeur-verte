<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SignalementForceBrute;
use App\Tests\Notification\Double\NotificationAdminEspionne;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AuthenticatorInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * Visibilité du verrouillage pour force brute (#307). Le plafond est tenu par
 * `login_throttling` ; ce listener ne fait que le **rendre visible** — sans quoi
 * rien ne distingue un utilisateur tête-en-l'air d'un bourrage d'identifiants.
 */
final class SignalementForceBruteTest extends TestCase
{
    public function testUnVerrouillageEstSignaleAvecLIpEtLeCompteVise(): void
    {
        $espion = new NotificationAdminEspionne();
        $signalement = new SignalementForceBrute($espion);

        $signalement(self::echec(
            new TooManyLoginAttemptsAuthenticationException(15),
            'cible@example.com',
            '203.0.113.10',
        ));

        self::assertCount(1, $espion->alertes);
        self::assertSame('Connexions trop nombreuses', $espion->alertes[0]['sujet']);
        // Sans l'IP ni le compte visé, l'alerte ne dit pas s'il faut s'inquiéter.
        self::assertStringContainsString('203.0.113.10', $espion->alertes[0]['contexte']);
        self::assertStringContainsString('cible@example.com', $espion->alertes[0]['contexte']);
    }

    public function testUnSimpleMotDePasseFauxNAlertePersonne(): void
    {
        $espion = new NotificationAdminEspionne();
        $signalement = new SignalementForceBrute($espion);

        $signalement(self::echec(new BadCredentialsException(), 'cible@example.com', '203.0.113.10'));

        // Se tromper de mot de passe est le quotidien : alerter à chaque fois
        // reviendrait à n'alerter sur rien.
        self::assertSame([], $espion->alertes);
    }

    public function testUnEchecSansPasseportNommeQuandMemeLIp(): void
    {
        $espion = new NotificationAdminEspionne();
        $signalement = new SignalementForceBrute($espion);

        $signalement(self::echec(new TooManyLoginAttemptsAuthenticationException(15), null, '203.0.113.10'));

        self::assertCount(1, $espion->alertes);
        self::assertStringContainsString('203.0.113.10', $espion->alertes[0]['contexte']);
        self::assertStringContainsString('inconnu', $espion->alertes[0]['contexte']);
    }

    private static function echec(AuthenticationException $exception, ?string $identifiant, string $ip): LoginFailureEvent
    {
        $requete = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => $ip]);
        $passeport = null === $identifiant ? null : self::passeport($identifiant);

        return new LoginFailureEvent(
            $exception,
            self::authentificateur(),
            $requete,
            null,
            'main',
            $passeport,
        );
    }

    private static function passeport(string $identifiant): Passport
    {
        return new SelfValidatingPassport(new UserBadge($identifiant, static fn (): ?object => null));
    }

    private static function authentificateur(): AuthenticatorInterface
    {
        return new class implements AuthenticatorInterface {
            public function supports(Request $request): ?bool
            {
                return true;
            }

            public function authenticate(Request $request): Passport
            {
                return new SelfValidatingPassport(new UserBadge('x', static fn (): ?object => null));
            }

            public function createToken(Passport $passport, string $firewallName): \Symfony\Component\Security\Core\Authentication\Token\TokenInterface
            {
                throw new \LogicException('Inutile pour ce test.');
            }

            public function onAuthenticationSuccess(Request $request, \Symfony\Component\Security\Core\Authentication\Token\TokenInterface $token, string $firewallName): ?\Symfony\Component\HttpFoundation\Response
            {
                return null;
            }

            public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?\Symfony\Component\HttpFoundation\Response
            {
                return null;
            }
        };
    }
}
