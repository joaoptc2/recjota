<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToClient;
use App\Models\Concerns\HasUlidKey;
use Database\Factories\HashtagSetFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Conjunto de hashtags reaproveitável no composer (Seção 6.5). */
class HashtagSet extends Model
{
    use BelongsToClient;

    /** @use HasFactory<HashtagSetFactory> */
    use HasFactory;

    use HasUlidKey;

    protected $fillable = ['client_id', 'name', 'hashtags', 'created_by'];

    protected function casts(): array
    {
        return ['hashtags' => 'array'];
    }

    /** Normaliza para "#tag", aceitando o que a pessoa colar. */
    public static function normalize(string $bruto): array
    {
        return collect(preg_split('/[\s,]+/u', $bruto) ?: [])
            ->map(fn (string $t) => Str::of($t)->trim()->ltrim('#')->lower()->toString())
            ->filter(fn (string $t) => $t !== '' && preg_match('/^[\p{L}\p{N}_]+$/u', $t) === 1)
            ->unique()
            ->map(fn (string $t) => '#'.$t)
            ->values()
            ->all();
    }

    public function asText(): string
    {
        return implode(' ', $this->hashtags ?? []);
    }
}
