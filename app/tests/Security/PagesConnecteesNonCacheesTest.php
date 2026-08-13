<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\PagesConnecteesNonCachees;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class PagesConnecteesNonCacheesTest extends TestCase
{
    public function testUneReponseAuthentifieeInterditLeCache(): void
    {
        $reponse = new Response();
        $this->listener(connecte: true)(
            $this->evenement($reponse, HttpKernelInterface::MAIN_REQUEST),
        );

        self::assertStringContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function testUneReponseAnonymeResteInchangee(): void
    {
        $reponse = new Response();
        $avant = $reponse->headers->get('Cache-Control');

        $this->listener(connecte: false)(
            $this->evenement($reponse, HttpKernelInterface::MAIN_REQUEST),
        );

        self::assertSame($avant, $reponse->headers->get('Cache-Control'));
        self::assertStringNotContainsString('no-store', (string) $reponse->headers->get('Cache-Control'));
    }

    public function testUneSousRequeteEstIgnoree(): void
    {
        $reponse = new Response();
        $avant = $reponse->headers->get('Cache-Control');

        $this->listener(connecte: true)(
            $this->evenement($reponse, HttpKernelInterface::SUB_REQUEST),
        );

        self::assertSame($avant, $reponse->headers->get('Cache-Control'));
    }

    private function listener(bool $connecte): PagesConnecteesNonCachees
    {
        $storage = $this->createMock(TokenStorageInterface::class);
        if ($connecte) {
            $token = $this->createMock(TokenInterface::class);
            $token->method('getUser')->willReturn($this->createMock(UserInterface::class));
            $storage->method('getToken')->willReturn($token);
        } else {
            $storage->method('getToken')->willReturn(null);
        }

        return new PagesConnecteesNonCachees($storage);
    }

    private function evenement(Response $reponse, int $type): ResponseEvent
    {
        return new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            new Request(),
            $type,
            $reponse,
        );
    }
}
