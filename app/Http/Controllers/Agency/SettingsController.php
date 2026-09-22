<?php

declare(strict_types=1);

namespace App\Http\Controllers\Agency;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\View\View;

/** Configurações da agência (Seção 11.2): owner/admin, nunca o portal. */
class SettingsController extends Controller
{
    public function __invoke(): View
    {
        $this->authorize('viewAny', Setting::class);

        return view('agency.settings');
    }
}
