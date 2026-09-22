<?php

declare(strict_types=1);

namespace App\Services\Integrations\Cloud;

use App\Support\Enums\CloudProvider;
use Illuminate\Contracts\Container\Container;

/** Resolve o cliente certo para cada provedor. */
class CloudStorageRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(CloudProvider $provider): CloudStorageInterface
    {
        return match ($provider) {
            CloudProvider::GoogleDrive => $this->container->make(GoogleDriveClient::class),
            CloudProvider::OneDrive => $this->container->make(OneDriveClient::class),
        };
    }
}
