<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToClient;
use Database\Factories\ClientSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ClientSetting extends Model
{
    use Auditable;
    use BelongsToClient;

    /** @use HasFactory<ClientSettingFactory> */
    use HasFactory;

    /** Colunas auditadas — tokens e senhas jamais entram aqui. */
    protected array $auditable = [
        'approval_required',
        'internal_review_required',
        'approval_deadline_hours',
        'auto_publish_on_approval',
        'min_approvals',
    ];

    protected $fillable = [
        'client_id',
        'approval_required',
        'internal_review_required',
        'approval_deadline_hours',
        'auto_publish_on_approval',
        'min_approvals',
        'notify_channels',
        'brand_guidelines',
    ];

    protected function casts(): array
    {
        return [
            'approval_required' => 'boolean',
            'internal_review_required' => 'boolean',
            'auto_publish_on_approval' => 'boolean',
            'approval_deadline_hours' => 'integer',
            'min_approvals' => 'integer',
            'notify_channels' => 'array',
        ];
    }
}
