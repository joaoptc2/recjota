<?php

declare(strict_types=1);

namespace App\Actions\Settings;

use App\Support\DataObjects\AgencyBrandingData;
use App\Support\Settings;

/** Dados e branding da agência (Seção 11.2): nome, e-mail de suporte e cor. */
class SaveAgencyBranding
{
    public function __construct(private readonly Settings $settings) {}

    public function __invoke(AgencyBrandingData $dados): void
    {
        $this->settings->set('agency.name', $dados->name);
        $this->settings->set('agency.support_email', $dados->supportEmail);
        $this->settings->set('agency.primary_color', strtoupper($dados->primaryColor));
    }
}
