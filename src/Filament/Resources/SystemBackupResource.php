<?php

namespace ProGamesZet\GDriveBackup\Filament\Resources;

use App\Enums\TablerIcon;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use ProGamesZet\GDriveBackup\Filament\Resources\SystemBackupResource\Pages\ListSystemBackups;
use ProGamesZet\GDriveBackup\GDriveBackupPlugin;
use ProGamesZet\GDriveBackup\Models\SystemBackup;
use ProGamesZet\GDriveBackup\Services\GDriveBackupService;

class SystemBackupResource extends Resource
{
    protected static ?string $model = SystemBackup::class;
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Server;
    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return trans('gdrive-backup::messages.nav_label');
    }

    public static function getModelLabel(): string
    {
        return trans('gdrive-backup::messages.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return trans('gdrive-backup::messages.plural_label');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('admin/dashboard.advanced');
    }

    public static function getNavigationBadge(): ?string
    {
        try {
            return (string) static::getEloquentQuery()->count() ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function table(Table $table): Table
    {
        return $table
            ->poll('5s')
            ->columns([
                TextColumn::make('name')
                    ->label(trans('gdrive-backup::messages.archive_name'))
                    ->searchable()
                    ->icon(TablerIcon::FileZip),
                TextColumn::make('type')
                    ->label(trans('gdrive-backup::messages.type'))
                    ->default(trans('gdrive-backup::messages.full_snapshot'))
                    ->badge()
                    ->color('info'),
                TextColumn::make('size_formatted')
                    ->label(trans('gdrive-backup::messages.size'))
                    ->badge(fn ($record) => $record->status === 'InProgress')
                    ->color(fn ($record) => $record->status === 'InProgress' ? 'warning' : 'gray'),
                TextColumn::make('date')
                    ->label(trans('gdrive-backup::messages.created_date'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(trans('gdrive-backup::messages.status'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'InProgress' => 'warning',
                        'Completed' => 'success',
                        'Failed' => 'danger',
                        default => 'gray',
                    })
                    ->icon(fn (string $state) => match ($state) {
                        'InProgress' => TablerIcon::CircleDashed,
                        'Completed' => TablerIcon::CircleCheck,
                        'Failed' => TablerIcon::CircleX,
                        default => null,
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'InProgress' => trans('gdrive-backup::messages.status_in_progress'),
                        'Completed' => trans('gdrive-backup::messages.status_completed'),
                        'Failed' => trans('gdrive-backup::messages.status_failed'),
                        default => $state,
                    }),
            ])
            ->recordActions([
                Action::make('restore_system')
                    ->label(trans('gdrive-backup::messages.restore'))
                    ->icon(TablerIcon::CloudDownload)
                    ->color('danger')
                    ->visible(fn (SystemBackup $record) => $record->status === 'Completed')
                    ->modalHeading(fn (SystemBackup $record) => trans('gdrive-backup::messages.restore_confirm_title', ['name' => $record->name]))
                    ->modalDescription(trans('gdrive-backup::messages.restore_confirm_desc'))
                    ->modalSubmitActionLabel(trans('gdrive-backup::messages.restore_submit'))
                    ->schema([
                        TextInput::make('confirmation')
                            ->label(trans('gdrive-backup::messages.restore_confirm_input'))
                            ->placeholder(trans('gdrive-backup::messages.restore_confirm_placeholder'))
                            ->required()
                            ->rules(['in:RESTORE']),
                    ])
                    ->action(function (SystemBackup $record) {
                        $service = app(GDriveBackupService::class);
                        $service->triggerSystemRestore($record->name);

                        Notification::make()
                            ->title(trans('gdrive-backup::messages.system_restore_started_title'))
                            ->body(trans('gdrive-backup::messages.system_restore_started_body', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),

                Action::make('delete_system')
                    ->label(trans('gdrive-backup::messages.delete'))
                    ->icon(TablerIcon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (SystemBackup $record) => trans('gdrive-backup::messages.delete_confirm_title', ['name' => $record->name]))
                    ->modalDescription(trans('gdrive-backup::messages.delete_confirm_desc'))
                    ->modalSubmitActionLabel(trans('gdrive-backup::messages.delete_submit'))
                    ->action(function (SystemBackup $record) {
                        $service = app(GDriveBackupService::class);
                        $service->deleteSystemBackup($record->name);

                        Notification::make()
                            ->title(trans('gdrive-backup::messages.archive_deleted_title'))
                            ->body(trans('gdrive-backup::messages.archive_deleted_body', ['name' => $record->name]))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                GDriveBackupPlugin::getDiagnosticTestAction(),

                Action::make('create_system_backup')
                    ->label(trans('gdrive-backup::messages.create_system_backup'))
                    ->icon(TablerIcon::BrandGoogleDrive)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(trans('gdrive-backup::messages.create_backup_modal_title'))
                    ->modalDescription(trans('gdrive-backup::messages.create_backup_modal_desc'))
                    ->modalSubmitActionLabel(trans('gdrive-backup::messages.create_backup_submit'))
                    ->action(function () {
                        $service = app(GDriveBackupService::class);
                        $filename = $service->triggerSystemBackup(true);

                        Notification::make()
                            ->title(trans('gdrive-backup::messages.system_backup_started_title'))
                            ->body(trans('gdrive-backup::messages.system_backup_started_body', ['name' => $filename]))
                            ->success()
                            ->send();
                    }),

                Action::make('refresh_system_list')
                    ->label(trans('gdrive-backup::messages.refresh_list'))
                    ->icon(TablerIcon::Refresh)
                    ->color('gray')
                    ->action(function () {
                        \Illuminate\Support\Facades\Cache::forget('gdrive_system_backups_list');
                        \Illuminate\Support\Facades\Cache::forget('gdrive_active_backup_status');

                        Notification::make()
                            ->title(trans('gdrive-backup::messages.list_refreshed_title'))
                            ->body(trans('gdrive-backup::messages.list_refreshed_body'))
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading(trans('gdrive-backup::messages.empty_heading'))
            ->emptyStateDescription(trans('gdrive-backup::messages.empty_description'))
            ->emptyStateIcon(TablerIcon::Server);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => ListSystemBackups::route('/'),
        ];
    }
}
