<?php

declare(strict_types=1);

namespace App\Security;

final class EmailDejaUtilise extends \RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct(\sprintf('Un compte existe déjà avec l\'email « %s ».', $email));
    }
}
