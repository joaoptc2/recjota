<?php

declare(strict_types=1);

namespace App\Support\Enums;

/**
 * Catálogo de permissões (spatie/laravel-permission). Ficam aqui, e não no
 * seeder, para que Policies e testes referenciem um valor tipado.
 */
enum Permission: string
{
    case ClientsView = 'clients.view';
    case ClientsCreate = 'clients.create';
    case ClientsUpdate = 'clients.update';
    case ClientsDelete = 'clients.delete';
    case ClientsManageTeam = 'clients.manage_team';

    case PostsView = 'posts.view';
    case PostsCreate = 'posts.create';
    case PostsUpdate = 'posts.update';
    case PostsDelete = 'posts.delete';
    case PostsSchedule = 'posts.schedule';
    case PostsPublish = 'posts.publish';
    case PostsReviewInternal = 'posts.review_internal';

    case ApprovalsDecide = 'approvals.decide';
    case ApprovalsRequest = 'approvals.request';

    case MediaView = 'media.view';
    case MediaUpload = 'media.upload';
    case MediaDelete = 'media.delete';

    case CampaignsManage = 'campaigns.manage';

    case TasksView = 'tasks.view';
    case TasksManage = 'tasks.manage';

    case CalendarManage = 'calendar.manage';

    case BriefsView = 'briefs.view';
    case BriefsCreate = 'briefs.create';
    case BriefsManage = 'briefs.manage';

    case CommentsCreate = 'comments.create';
    case CommentsInternal = 'comments.internal';

    case ReportsView = 'reports.view';

    case IntegrationsManage = 'integrations.manage';
    case SettingsManage = 'settings.manage';
    case BillingManage = 'billing.manage';

    case UsersInvite = 'users.invite';
    case UsersManage = 'users.manage';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $p) => $p->value, self::cases());
    }

    /**
     * Matriz papel → permissões (Seção 4.2).
     *
     * @return array<int, self>
     */
    public static function forRole(RoleName $role): array
    {
        return match ($role) {
            RoleName::Owner => self::cases(),

            RoleName::Admin => array_values(array_filter(
                self::cases(),
                fn (self $p) => ! in_array($p, [self::ClientsDelete, self::BillingManage], true),
            )),

            RoleName::Gestor => [
                self::ClientsView, self::ClientsUpdate, self::ClientsManageTeam,
                self::PostsView, self::PostsCreate, self::PostsUpdate, self::PostsDelete,
                self::PostsSchedule, self::PostsPublish, self::PostsReviewInternal,
                self::ApprovalsRequest,
                self::MediaView, self::MediaUpload, self::MediaDelete,
                self::CampaignsManage,
                self::TasksView, self::TasksManage,
                self::CalendarManage,
                self::BriefsView, self::BriefsCreate, self::BriefsManage,
                self::CommentsCreate, self::CommentsInternal,
                self::ReportsView,
                self::IntegrationsManage,
                self::UsersInvite,
            ],

            // Criador produz rascunho; não publica e não aprova.
            RoleName::Criador => [
                self::ClientsView,
                self::PostsView, self::PostsCreate, self::PostsUpdate,
                self::MediaView, self::MediaUpload,
                self::TasksView, self::TasksManage,
                self::BriefsView,
                self::CommentsCreate, self::CommentsInternal,
                self::ReportsView,
            ],

            RoleName::ClientAdmin => [
                self::ClientsView,
                self::PostsView,
                self::ApprovalsDecide,
                self::MediaView, self::MediaUpload,
                self::BriefsView, self::BriefsCreate,
                self::CommentsCreate,
                self::ReportsView,
                self::UsersInvite, self::UsersManage,
            ],

            RoleName::ClientViewer => [
                self::ClientsView,
                self::PostsView,
                self::MediaView,
                self::BriefsView,
                self::CommentsCreate,
                self::ReportsView,
            ],
        };
    }
}
