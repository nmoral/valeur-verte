<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\RedirectionSelonRole;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class RedirectionSelonRoleTest extends TestCase
{
    public function testUnAdminEstRedirigeVersLeSousDomaineAdmin(): void
    {
        $handler = new RedirectionSelonRole($this->urlGenerator());
        $token = new UsernamePasswordToken(
            new InMemoryUser('admin@example.com', null, ['ROLE_ADMIN']),
            'main',
            ['ROLE_ADMIN'],
        );

        $reponse = $handler->onAuthenticationSuccess(new Request(), $token);

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('http://admin.valeurverte.localhost/', $reponse->getTargetUrl());
    }

    public function testUnProEstRedirigeVersSonSousDomaine(): void
    {
        $handler = new RedirectionSelonRole($this->urlGenerator());
        $token = new UsernamePasswordToken(
            new InMemoryUser('pro@example.com', null, ['ROLE_PRO']),
            'main',
            ['ROLE_PRO'],
        );

        $reponse = $handler->onAuthenticationSuccess(new Request(), $token);

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('http://app.valeurverte.localhost/', $reponse->getTargetUrl());
    }

    public function testLAdminPrimeSurLeProQuandLesDeuxRolesSontPresents(): void
    {
        $handler = new RedirectionSelonRole($this->urlGenerator());
        $token = new UsernamePasswordToken(
            new InMemoryUser('staff@example.com', null, ['ROLE_ADMIN', 'ROLE_PRO']),
            'main',
            ['ROLE_ADMIN', 'ROLE_PRO'],
        );

        $reponse = $handler->onAuthenticationSuccess(new Request(), $token);

        self::assertInstanceOf(RedirectResponse::class, $reponse);
        self::assertSame('http://admin.valeurverte.localhost/', $reponse->getTargetUrl());
    }

    private function urlGenerator(): UrlGeneratorInterface
    {
        $urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnMap([
            ['admin', [], UrlGeneratorInterface::ABSOLUTE_URL, 'http://admin.valeurverte.localhost/'],
            ['espace_pro', [], UrlGeneratorInterface::ABSOLUTE_URL, 'http://app.valeurverte.localhost/'],
        ]);

        return $urlGenerator;
    }
}
