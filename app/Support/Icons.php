<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Ícones inline. Sem fonte de ícones, sem pacote npm, sem requisição extra —
 * o que importa em hospedagem compartilhada e 4G (Seção 9.3).
 */
final class Icons
{
    private const PATHS = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/>',
        'clients' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'approvals' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'media' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
        'tasks' => '<path d="M9 6h11M9 12h11M9 18h11"/><path d="m3 6 1.5 1.5L7 5M3 12l1.5 1.5L7 11M3 18l1.5 1.5L7 17"/>',
        'reports' => '<path d="M3 3v18h18"/><path d="m7 15 3-4 3 3 5-7"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M4 12a8 8 0 0 1 .4-2.5l-1.6-1.2 2-3.4 1.9.8A8 8 0 0 1 9 3.9L9.5 2h5l.5 1.9a8 8 0 0 1 2.3 1.3l1.9-.8 2 3.4-1.6 1.2a8 8 0 0 1 0 5l1.6 1.2-2 3.4-1.9-.8a8 8 0 0 1-2.3 1.3L14.5 22h-5l-.5-1.9a8 8 0 0 1-2.3-1.3l-1.9.8-2-3.4 1.6-1.2A8 8 0 0 1 4 12z"/>',
        'files' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
        'briefs' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4z"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'theme' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'logout' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    ];

    public static function get(string $name, string $class = 'size-5'): HtmlString
    {
        $path = self::PATHS[$name] ?? '';

        return new HtmlString(sprintf(
            '<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            e($class),
            $path,
        ));
    }

    /** @return array<string, HtmlString> */
    public static function all(): array
    {
        $icons = [];

        foreach (array_keys(self::PATHS) as $name) {
            $icons[$name] = self::get($name);
        }

        return $icons;
    }
}
