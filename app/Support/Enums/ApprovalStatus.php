<?php

declare(strict_types=1);

namespace App\Support\Enums;

enum ApprovalStatus: string
{
    /** Cliente dispensou aprovação (client_settings.approval_required = false). */
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case ChangesRequested = 'changes_requested';

    public function label(): string
    {
        return match ($this) {
            self::NotRequired => 'Sem aprovação',
            self::Pending => 'Aguardando decisão',
            self::Approved => 'Aprovado',
            self::Rejected => 'Reprovado',
            self::ChangesRequested => 'Ajustes solicitados',
        };
    }

    /** Estados que uma decisão registrada em `approvals` pode assumir. */
    public function isDecision(): bool
    {
        return in_array($this, [self::Approved, self::Rejected, self::ChangesRequested], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::NotRequired => 'bg-slate-100 text-slate-600 ring-slate-500/20 dark:bg-slate-500/10 dark:text-slate-300 dark:ring-slate-400/30',
            self::Pending => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/30',
            self::Approved => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/30',
            self::Rejected => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/30',
            self::ChangesRequested => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-400/30',
        };
    }
}
