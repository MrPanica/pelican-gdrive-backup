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
        return 'Бэкапы всей системы';
    }

    public static function getModelLabel(): string
    {
        return 'Бэкап системы';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Бэкапы всей системы';
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
                    ->label('Имя архива')
                    ->searchable()
                    ->icon(TablerIcon::FileZip),
                TextColumn::make('type')
                    ->label('Тип')
                    ->default('Полный снимок VDS')
                    ->badge()
                    ->color('info'),
                TextColumn::make('size_formatted')
                    ->label('Размер')
                    ->badge(fn ($record) => $record->status === 'InProgress')
                    ->color(fn ($record) => $record->status === 'InProgress' ? 'warning' : 'gray'),
                TextColumn::make('date')
                    ->label('Дата создания')
                    ->sortable(),
                TextColumn::make('status')
                    ->label('Статус')
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
                        'InProgress' => 'В процессе...',
                        'Completed' => 'Готов к восстановлению',
                        'Failed' => 'Ошибка',
                        default => $state,
                    }),
            ])
            ->recordActions([
                Action::make('restore_system')
                    ->label('Восстановить')
                    ->icon(TablerIcon::CloudDownload)
                    ->color('danger')
                    ->visible(fn (SystemBackup $record) => $record->status === 'Completed')
                    ->modalHeading(fn (SystemBackup $record) => "Восстановление системы из архива {$record->name}")
                    ->modalDescription('ВНИМАНИЕ! Будет выполнено восстановление системных файлов и конфигураций игрового VDS. Для защиты от случайного восстановления введите RESTORE.')
                    ->modalSubmitActionLabel('Начать восстановление')
                    ->schema([
                        TextInput::make('confirmation')
                            ->label('Подтверждение операции')
                            ->placeholder('Введите RESTORE')
                            ->required()
                            ->rules(['in:RESTORE']),
                    ])
                    ->action(function (SystemBackup $record) {
                        $service = app(GDriveBackupService::class);
                        $service->triggerSystemRestore($record->name);

                        Notification::make()
                            ->title('Восстановление системы запущено')
                            ->body("Процесс восстановления из {$record->name} выполняется на игровой ноде.")
                            ->success()
                            ->send();
                    }),

                Action::make('delete_system')
                    ->label('Удалить')
                    ->icon(TablerIcon::Trash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading(fn (SystemBackup $record) => "Удаление архива {$record->name}")
                    ->modalDescription('Вы действительно хотите удалить этот архив с Google Диска?')
                    ->modalSubmitActionLabel('Удалить с Google Диска')
                    ->action(function (SystemBackup $record) {
                        $service = app(GDriveBackupService::class);
                        $service->deleteSystemBackup($record->name);

                        Notification::make()
                            ->title('Архив удален')
                            ->body("Архив {$record->name} успешно удален с Google Диска.")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                GDriveBackupPlugin::getDiagnosticTestAction(),

                Action::make('create_system_backup')
                    ->label('Создать полный бэкап системы')
                    ->icon(TablerIcon::BrandGoogleDrive)
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading('Создание полного бэкапа VDS на Google Диск')
                    ->modalDescription('Запустить потоковое резервное копирование всей системы на Google Диск прямо сейчас?')
                    ->modalSubmitActionLabel('Создать бэкап')
                    ->action(function () {
                        $service = app(GDriveBackupService::class);
                        $filename = $service->triggerSystemBackup(true);

                        Notification::make()
                            ->title('Бэкап системы запущен')
                            ->body("Создание архива {$filename} выполняется в фоновом режиме. Статус отображается в списке.")
                            ->success()
                            ->send();
                    }),

                Action::make('refresh_system_list')
                    ->label('Обновить список')
                    ->icon(TablerIcon::Refresh)
                    ->color('gray')
                    ->action(function () {
                        \Illuminate\Support\Facades\Cache::forget('gdrive_system_backups_list');
                        \Illuminate\Support\Facades\Cache::forget('gdrive_active_backup_status');

                        Notification::make()
                            ->title('Список обновлен')
                            ->body('Данные успешно синхронизированы с Google Диском.')
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading('Архивы системы не найдены')
            ->emptyStateDescription('На Google Диске в папке TF2_Backups/System пока нет созданных резервных копий.')
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
