<?php

declare(strict_types=1);

namespace App\Support\Enums;

use App\Support\Exceptions\InvalidStateTransition;

/**
 * Máquina de estados do post (Seção 5).
 *
 *   draft → in_review → awaiting_client → approved → scheduled → publishing → published
 *                ↓             ↓              ↓          ↓
 *      changes_requested   rejected       canceled   canceled
 *                                                        failed → (retry) → publishing
 *
 * Toda transição ilegal lança InvalidStateTransition. Nenhum código de
 * aplicação deve escrever `status` diretamente: use Post::transitionTo().
 */
enum PostStatus: string
{
    case Draft = 'draft';
    case InReview = 'in_review';
    case AwaitingClient = 'awaiting_client';
    case ChangesRequested = 'changes_requested';
    case Rejected = 'rejected';
    case Approved = 'approved';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Rascunho',
            self::InReview => 'Em revisão interna',
            self::AwaitingClient => 'Aguardando cliente',
            self::ChangesRequested => 'Ajustes solicitados',
            self::Rejected => 'Reprovado',
            self::Approved => 'Aprovado',
            self::Scheduled => 'Agendado',
            self::Publishing => 'Publicando',
            self::Published => 'Publicado',
            self::Failed => 'Falhou',
            self::Canceled => 'Cancelado',
        };
    }

    /**
     * Transições permitidas a partir deste estado.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            // Um cliente sem aprovação obrigatória pula direto para approved.
            self::Draft => [self::InReview, self::AwaitingClient, self::Approved, self::Canceled],
            self::InReview => [self::AwaitingClient, self::ChangesRequested, self::Approved, self::Rejected, self::Draft, self::Canceled],
            self::AwaitingClient => [self::Approved, self::Rejected, self::ChangesRequested, self::Draft, self::Canceled],
            self::ChangesRequested => [self::Draft, self::InReview, self::AwaitingClient, self::Canceled],
            self::Rejected => [self::Draft, self::Canceled],
            // Editar um post aprovado devolve ele para o cliente (Seção 6.6).
            self::Approved => [self::Scheduled, self::Publishing, self::AwaitingClient, self::Draft, self::Canceled],
            self::Scheduled => [self::Publishing, self::AwaitingClient, self::Approved, self::Draft, self::Canceled],
            self::Publishing => [self::Published, self::Failed],
            // Retry controlado pela regra de negócio, não pelo mecanismo da fila.
            self::Failed => [self::Publishing, self::Scheduled, self::Approved, self::Draft, self::Canceled],
            self::Canceled => [self::Draft],
            self::Published => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** @throws InvalidStateTransition */
    public function assertCanTransitionTo(self $target): void
    {
        if (! $this->canTransitionTo($target)) {
            throw InvalidStateTransition::between($this, $target);
        }
    }

    /** Estado final: nada mais acontece com o post. */
    public function isTerminal(): bool
    {
        return $this->allowedTransitions() === [];
    }

    /** O post ainda pode ser editado livremente pela equipe? */
    public function isEditable(): bool
    {
        return in_array($this, [
            self::Draft,
            self::InReview,
            self::ChangesRequested,
            self::Rejected,
            self::AwaitingClient,
            self::Approved,
            self::Scheduled,
            self::Failed,
        ], true);
    }

    /** Post que já saiu (ou está saindo) não se move no calendário. */
    public function isMovableInCalendar(): bool
    {
        return ! in_array($this, [self::Publishing, self::Published], true);
    }

    /** Estados elegíveis para o motor de publicação (Seção 6.7). */
    public function isPublishable(): bool
    {
        return in_array($this, [self::Approved, self::Scheduled], true);
    }

    /** Estados que demandam ação de alguém — alimenta o painel "o que está travado". */
    public function isBlocking(): bool
    {
        return in_array($this, [
            self::InReview,
            self::AwaitingClient,
            self::ChangesRequested,
            self::Rejected,
            self::Failed,
        ], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-400/30',
            self::InReview => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-400/30',
            self::AwaitingClient => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
            self::ChangesRequested => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/30',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30',
            self::Approved => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
            self::Scheduled => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/30',
            self::Publishing => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-300 dark:ring-blue-400/30',
            self::Published => 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-400/30',
            self::Failed => 'bg-red-100 text-red-800 ring-red-600/30 dark:bg-red-500/15 dark:text-red-300 dark:ring-red-400/30',
            self::Canceled => 'bg-slate-100 text-slate-500 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-400 dark:ring-slate-400/30',
        };
    }
}
