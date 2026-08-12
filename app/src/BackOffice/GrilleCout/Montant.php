<?php

declare(strict_types=1);

namespace App\BackOffice\GrilleCout;

final readonly class Montant
{
    private function __construct(private int $euros)
    {
    }

    public static function fromEuros(int $euros): self
    {
        return new self($euros);
    }

    public function euros(): int
    {
        return $this->euros;
    }
}
