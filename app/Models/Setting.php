<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = ['key', 'value', 'client_id'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
