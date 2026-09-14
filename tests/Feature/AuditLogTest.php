<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\SocialAccount;
use App\Support\Enums\ClientStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/** Log de atividade com valores antes/depois (Seção 6.12). */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_alteracao_de_cliente_registra_valores_antes_e_depois(): void
    {
        $client = Client::factory()->create(['status' => ClientStatus::Active]);

        $client->update(['status' => ClientStatus::Paused]);

        $activity = Activity::where('subject_type', Client::class)
            ->where('subject_id', $client->getKey())
            ->latest('id')
            ->firstOrFail();

        $this->assertSame('updated', $activity->event);
        $this->assertSame('paused', data_get($activity->properties, 'attributes.status'));
        $this->assertSame('active', data_get($activity->properties, 'old.status'));
    }

    public function test_token_de_conta_social_nunca_aparece_no_log(): void
    {
        $account = SocialAccount::factory()->create();

        $account->update([
            'access_token' => 'IGQsegredoabsoluto',
            'username' => 'novo_usuario',
        ]);

        $activities = Activity::where('subject_type', SocialAccount::class)->get();

        foreach ($activities as $activity) {
            $json = json_encode($activity->properties);

            $this->assertStringNotContainsString('segredoabsoluto', (string) $json);
            $this->assertStringNotContainsString('access_token', (string) $json);
        }
    }
}
