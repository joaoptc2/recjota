<?php

declare(strict_types=1);

namespace App\Support\DataObjects;

final readonly class AgencyBrandingData
{
    public function __construct(
        public string $name,
        public string $supportEmail,
        /** Hexadecimal com #, ex.: #4F46E5. */
        public string $primaryColor,
    ) {}
}
