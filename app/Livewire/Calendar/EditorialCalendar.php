<?php

declare(strict_types=1);

namespace App\Livewire\Calendar;

use App\Actions\Posts\ReschedulePost;
use App\Models\Client;
use App\Models\Post;
use App\Support\Display;
use App\Support\Enums\PostStatus;
use App\Support\Enums\PostType;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Calendário editorial (Seção 6.3).
 *
 * Quatro visões: mês, semana, lista e grade de feed. As datas navegam no fuso
 * do CLIENTE — o mês de março do cliente não é o mês de março do UTC.
 */
class EditorialCalendar extends Component
{
    public ?Client $client = null;

    #[Url]
    public string $view = 'mes';

    /** Âncora da navegação, no fuso do cliente, em Y-m-d. */
    #[Url]
    public string $anchor = '';

    #[Url]
    public string $status = 'todos';

    #[Url]
    public string $type = 'todos';

    #[Url]
    public ?int $accountId = null;

    #[Url]
    public ?int $campaignId = null;

    public ?string $feedback = null;

    public function mount(?Client $client = null): void
    {
        // Livewire injeta um model vazio quando o parâmetro não é passado.
        $this->client = $client?->exists ? $client : null;

        if ($this->anchor === '') {
            $this->anchor = $this->hoje()->format('Y-m-d');
        }
    }

    private function timezone(): string
    {
        return $this->client?->displayTimezone() ?? config('agency.default_timezone');
    }

    private function hoje(): Carbon
    {
        return Carbon::now($this->timezone());
    }

    private function ancora(): Carbon
    {
        return Carbon::parse($this->anchor, $this->timezone())->startOfDay();
    }

    // ------------------------------------------------------------ navegação

    public function move(int $passo): void
    {
        $ancora = $this->ancora();

        $this->anchor = match ($this->view) {
            'semana' => $ancora->addWeeks($passo)->format('Y-m-d'),
            'lista' => $ancora->addMonths($passo)->format('Y-m-d'),
            default => $ancora->addMonthsNoOverflow($passo)->format('Y-m-d'),
        };
    }

    public function today(): void
    {
        $this->anchor = $this->hoje()->format('Y-m-d');
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['mes', 'semana', 'lista', 'grade'], true) ? $view : 'mes';
    }

    // -------------------------------------------------------------- períodos

    /** @return array{0: Carbon, 1: Carbon} Intervalo em UTC para a consulta. */
    private function intervalo(): array
    {
        $ancora = $this->ancora();

        [$inicio, $fim] = match ($this->view) {
            'semana' => [$ancora->copy()->startOfWeek(), $ancora->copy()->endOfWeek()],
            // A grade de feed mostra o que já saiu, do mais recente ao mais antigo.
            'grade' => [$ancora->copy()->subMonths(6)->startOfDay(), $ancora->copy()->endOfMonth()],
            default => [
                $ancora->copy()->startOfMonth()->startOfWeek(),
                $ancora->copy()->endOfMonth()->endOfWeek(),
            ],
        };

        return [$inicio->utc(), $fim->utc()];
    }

    #[Computed]
    public function periodLabel(): string
    {
        $ancora = $this->ancora();

        return match ($this->view) {
            'semana' => $ancora->copy()->startOfWeek()->translatedFormat('d M').' – '
                .$ancora->copy()->endOfWeek()->translatedFormat('d M Y'),
            'grade' => 'Últimos 6 meses',
            default => ucfirst($ancora->translatedFormat('F \d\e Y')),
        };
    }

    #[Computed]
    public function posts(): Collection
    {
        [$inicio, $fim] = $this->intervalo();

        return Post::query()
            ->with(['client', 'socialAccount', 'campaign', 'postMedia.mediaAsset'])
            ->when($this->client !== null, fn ($q) => $q->where('client_id', $this->client->getKey()))
            ->when($this->status !== 'todos', fn ($q) => $q->where('status', $this->status))
            ->when($this->type !== 'todos', fn ($q) => $q->where('type', $this->type))
            ->when($this->accountId !== null, fn ($q) => $q->where('social_account_id', $this->accountId))
            ->when($this->campaignId !== null, fn ($q) => $q->where('campaign_id', $this->campaignId))
            ->when($this->view === 'grade',
                fn ($q) => $q->where('status', PostStatus::Published->value)->orderByDesc('published_at'),
                fn ($q) => $q->whereBetween('scheduled_at', [$inicio, $fim])->orderBy('scheduled_at'),
            )
            ->get();
    }

    /**
     * Posts agrupados por dia NO FUSO DO CLIENTE. Agrupar em UTC jogaria um
     * post das 21h de Brasília para o dia seguinte.
     *
     * @return array<string, Collection>
     */
    #[Computed]
    public function postsByDay(): array
    {
        return $this->posts()
            ->filter(fn (Post $p) => $p->scheduled_at !== null)
            ->groupBy(fn (Post $p) => Display::carbon($p->scheduled_at, $this->timezone())->format('Y-m-d'))
            ->all();
    }

    /** @return array<int, Carbon> */
    #[Computed]
    public function days(): array
    {
        $ancora = $this->ancora();

        [$inicio, $fim] = match ($this->view) {
            'semana' => [$ancora->copy()->startOfWeek(), $ancora->copy()->endOfWeek()],
            default => [$ancora->copy()->startOfMonth()->startOfWeek(), $ancora->copy()->endOfMonth()->endOfWeek()],
        };

        $dias = [];

        for ($dia = $inicio->copy(); $dia->lte($fim); $dia->addDay()) {
            $dias[] = $dia->copy();
        }

        return $dias;
    }

    #[Computed]
    public function accounts()
    {
        return $this->client?->socialAccounts()->orderBy('username')->get() ?? collect();
    }

    #[Computed]
    public function campaigns()
    {
        return $this->client?->campaigns()->orderByDesc('starts_at')->get() ?? collect();
    }

    // ----------------------------------------------------------- reagendar

    /** Arrastar e soltar; no mobile vira menu de ação (Seção 6.3). */
    public function reschedule(int $postId, string $dia): void
    {
        $post = Post::findOrFail($postId);
        $this->authorize('reschedule', $post);

        $atual = $post->scheduled_at !== null
            ? Display::carbon($post->scheduled_at, $post->client)
            : Carbon::now($post->client->displayTimezone());

        $novo = Carbon::parse($dia, $post->client->displayTimezone())
            ->setTime((int) $atual->format('H'), (int) $atual->format('i'));

        try {
            app(ReschedulePost::class)($post, $novo->utc());
            $this->feedback = sprintf('“%s” foi para %s.', str($post->caption ?: 'Sem legenda')->limit(30), display_datetime($post->scheduled_at, $post->client));
        } catch (DomainException $e) {
            $this->feedback = $e->getMessage();
        }

        unset($this->posts, $this->postsByDay);
    }

    /** @return array<int, PostStatus> */
    public function statusOptions(): array
    {
        return PostStatus::cases();
    }

    /** @return array<int, PostType> */
    public function typeOptions(): array
    {
        return PostType::cases();
    }

    public function render(): View
    {
        return view('livewire.calendar.editorial-calendar');
    }
}
