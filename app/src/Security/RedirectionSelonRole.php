<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationSuccessHandlerInterface;

/**
 * Login unique, redirection selon le rôle (#53) : l'admin interne prime sur le
 * pro. La cible est une URL absolue car chaque zone vit sur son sous-domaine.
 */
final class RedirectionSelonRole implements AuthenticationSuccessHandlerInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token): Response
    {
        $route = \in_array('ROLE_ADMIN', $token->getRoleNames(), true) ? 'admin' : 'espace_pro';

        return new RedirectResponse(
            $this->urlGenerator->generate($route, [], UrlGeneratorInterface::ABSOLUTE_URL),
        );
    }
}
