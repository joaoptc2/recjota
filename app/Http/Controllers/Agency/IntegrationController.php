<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\View\View;

/** Saúde das integrações (Seção 6.12): tela técnica, só da agência. */
class IntegrationController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', SocialAccount::class);

        return view('agency.integrations');
    }
}
