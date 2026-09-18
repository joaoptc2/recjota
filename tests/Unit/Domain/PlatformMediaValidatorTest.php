<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Models\MediaAsset;
use App\Services\Media\PlatformMediaValidator;
use App\Support\Enums\PostType;
use Tests\TestCase;

/**
 * As regras de mídia da plataforma reprovam no composer, com a pessoa olhando
 * a tela — não às 21h de sábado dentro de um job (Seção 7.4).
 */
class PlatformMediaValidatorTest extends TestCase
{
    private PlatformMediaValidator $validador;

    // client_id fixo: este teste é sobre as regras da plataforma, não sobre
    // persistência — sem isso a factory criaria um Client no banco.

    protected function setUp(): void
    {
        parent::setUp();

        $this->validador = new PlatformMediaValidator;
    }

    public function test_imagem_4_por_5_passa_no_feed(): void
    {
        $asset = MediaAsset::factory()->make(['client_id' => 1]);

        $this->assertTrue($this->validador->forAsset($asset, PostType::FeedImage)->passes());
    }

    public function test_imagem_panoramica_demais_e_reprovada_com_a_proporcao_em_texto(): void
    {
        $asset = MediaAsset::factory()->panoramicaDemais()->make(['client_id' => 1, 'filename' => 'banner.jpg']);

        $resultado = $this->validador->forAsset($asset, PostType::FeedImage);

        $this->assertTrue($resultado->fails());
        $this->assertStringContainsString('banner.jpg', $resultado->firstError());
        $this->assertStringContainsString('entre 4:5 e 1.91:1', $resultado->firstError());
    }

    public function test_imagem_acima_de_8_mb_e_reprovada(): void
    {
        $asset = MediaAsset::factory()->pesadaDemais()->make(['client_id' => 1]);

        $resultado = $this->validador->forAsset($asset, PostType::FeedImage);

        $this->assertTrue($resultado->fails());
        $this->assertStringContainsString('limite para imagem é 8 MB', $resultado->firstError());
    }

    public function test_imagem_estreita_demais_e_reprovada(): void
    {
        $asset = MediaAsset::factory()->estreita()->make(['client_id' => 1]);

        $this->assertStringContainsString('mínimo é 320px', $this->validador->forAsset($asset, PostType::FeedImage)->firstError());
    }

    public function test_gif_nao_e_aceito_pelo_instagram(): void
    {
        $asset = MediaAsset::factory()->make(['client_id' => 1, 'mime_type' => 'image/gif', 'filename' => 'meme.gif']);

        $this->assertStringContainsString('apenas JPEG e PNG', $this->validador->forAsset($asset, PostType::FeedImage)->firstError());
    }

    public function test_reel_acima_de_90_segundos_e_reprovado(): void
    {
        $asset = MediaAsset::factory()->video(120)->make(['client_id' => 1]);

        $resultado = $this->validador->forAsset($asset, PostType::Reel);

        $this->assertTrue($resultado->fails());
        $this->assertStringContainsString('limite para Reel é 90s', $resultado->firstError());
    }

    public function test_story_acima_de_60_segundos_e_reprovado(): void
    {
        $asset = MediaAsset::factory()->video(75)->make(['client_id' => 1]);

        $this->assertStringContainsString('limite para Story é 60s', $this->validador->forAsset($asset, PostType::Story)->firstError());
    }

    public function test_video_de_feed_curto_demais_e_reprovado(): void
    {
        $asset = MediaAsset::factory()->video(2)->make(['client_id' => 1, 'width' => 1080, 'height' => 1080]);

        $this->assertStringContainsString('ao menos 3s', $this->validador->forAsset($asset, PostType::FeedVideo)->firstError());
    }

    public function test_reel_fora_de_9_por_16_gera_aviso_e_nao_erro(): void
    {
        $asset = MediaAsset::factory()->video(30)->make(['client_id' => 1, 'width' => 1080, 'height' => 1080]);

        $resultado = $this->validador->forAsset($asset, PostType::Reel);

        $this->assertTrue($resultado->passes());
        $this->assertNotEmpty($resultado->warnings);
        $this->assertStringContainsString('9:16', $resultado->warnings[0]);
    }
}
