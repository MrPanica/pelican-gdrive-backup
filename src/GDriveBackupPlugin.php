<?php

namespace ProGamesZet\GDriveBackup;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\BackupStatus;
use App\Enums\TablerIcon;
use App\Filament\Admin\Resources\BackupHosts\BackupHostResource;
use App\Filament\Server\Resources\Backups\BackupResource;
use App\Models\Backup;
use App\Models\Server;
use Filament\Actions\Action;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\HtmlString;
use ProGamesZet\GDriveBackup\Filament\Resources\SystemBackupResource;
use ProGamesZet\GDriveBackup\Services\GDriveBackupService;

class GDriveBackupPlugin implements Plugin, HasPluginSettings
{
    public function getId(): string
    {
        return 'pelican-gdrive-backup';
    }

    /**
     * Create reusable diagnostic test action for tables and forms.
     */
    public static function getDiagnosticTestAction(): Action
    {
        return Action::make('gdrive_diagnostic_test')
            ->label('Тест Google Диска')
            ->icon(TablerIcon::CheckupList)
            ->color('info')
            ->modalHeading('Тестирование и диагностика Google Диска')
            ->modalDescription('Сквозная проверка всех этапов: связь по SSH, утилиты ноды, авторизация Google Диска, создание архива, сжатие zstd, загрузка на Google Диск, скачивание обратно, разархивация и сверка целостности SHA-256.')
            ->modalSubmitActionLabel('Повторить тест')
            ->modalCancelActionLabel('Закрыть')
            ->schema(function () {
                $service = app(GDriveBackupService::class);
                // Force fresh run on opening modal and refresh cache
                $result = $service->runFullDiagnosticTest();
                Cache::put('gdrive_last_diagnostic_result', $result, now()->addMinutes(15));
                $html = $service->renderDiagnosticHtml($result);

                return [
                    Placeholder::make('diagnostic_results')
                        ->hiddenLabel()
                        ->content(new HtmlString($html)),
                ];
            })
            ->action(function () {
                $service = app(GDriveBackupService::class);
                $result = $service->runFullDiagnosticTest();
                Cache::put('gdrive_last_diagnostic_result', $result, now()->addMinutes(15));
                if (!empty($result['overall_success'])) {
                    Notification::make()
                        ->title('Тест Google Диска успешно пройден!')
                        ->body('Все этапы, включая выгрузку, скачивание, разархивацию и сверку хэша SHA-256, завершены успешно.')
                        ->success()
                        ->send();
                } else {
                    Notification::make()
                        ->title('Ошибка при проверке Google Диска')
                        ->body($result['error'] ?? 'Обнаружена ошибка. Откройте окно теста для просмотра рекомендаций.')
                        ->danger()
                        ->send();
                }
            });
    }

    public function register(Panel $panel): void
    {
        if ($panel->getId() === 'admin') {
            // Register System Backups resource in Admin sidebar under Advanced
            $panel->resources([
                SystemBackupResource::class,
            ]);

            // Add actions to Backup Hosts table
            BackupHostResource::modifyTable(function (Table $table) {
                return $table->pushToolbarActions([
                    static::getDiagnosticTestAction(),

                    Action::make('gdrive_system_backups_link')
                        ->label('Все бэкапы системы')
                        ->icon(TablerIcon::Server)
                        ->color('info')
                        ->url(fn () => SystemBackupResource::getUrl()),

                    Action::make('gdrive_system_restore')
                        ->label('Восстановление всей системы')
                        ->icon(TablerIcon::CloudDownload)
                        ->color('danger')
                        ->modalHeading('Восстановление всей системы (VDS) с Google Диска')
                        ->modalDescription('ВНИМАНИЕ! Будет выполнено восстановление системных файлов и конфигураций игрового VDS из выбранного полного снимка на Google Диске.')
                        ->modalSubmitActionLabel('Начать восстановление системы')
                        ->schema([
                            Select::make('backup_file')
                                ->label('Архив системы на Google Диске')
                                ->required()
                                ->searchable()
                                ->options(function () {
                                    $service = app(GDriveBackupService::class);
                                    $backups = $service->listSystemBackups();
                                    $opts = [];
                                    foreach ($backups as $b) {
                                        if (($b['status'] ?? '') === 'Completed') {
                                            $opts[$b['name']] = "{$b['name']} ({$b['size_formatted']}, {$b['date']})";
                                        }
                                    }
                                    return $opts;
                                })
                                ->helperText('Выберите точку восстановления системы из папки TF2_Backups/System'),
                            TextInput::make('confirmation')
                                ->label('Подтверждение операции')
                                ->placeholder('Введите RESTORE')
                                ->required()
                                ->rules(['in:RESTORE'])
                                ->helperText('Для защиты от случайного восстановления введите слово RESTORE большими буквами'),
                        ])
                        ->action(function (array $data) {
                            $service = app(GDriveBackupService::class);
                            $service->triggerSystemRestore($data['backup_file']);

                            Notification::make()
                                ->title('Восстановление системы запущено')
                                ->body("Запущен процесс восстановления из архива {$data['backup_file']}. Процесс выполняется на игровой ноде.")
                                ->success()
                                ->send();
                        }),

                    Action::make('gdrive_system_backup')
                        ->label('Бэкап всей системы (GDrive)')
                        ->icon(TablerIcon::BrandGoogleDrive)
                        ->color('primary')
                        ->requiresConfirmation()
                        ->modalHeading('Создание полного бэкапа VDS')
                        ->modalDescription('Запустить немедленное потоковое резервное копирование всей системы на Google Диск? Серверы продолжат работать в штатном режиме.')
                        ->modalSubmitActionLabel('Запустить бэкап')
                        ->action(function () {
                            $service = app(GDriveBackupService::class);
                            $filename = $service->triggerSystemBackup(true);

                            Notification::make()
                                ->title('Бэкап системы запущен')
                                ->body("Создание полного образа {$filename} выполняется в фоновом режиме.")
                                ->success()
                                ->send();
                        }),
                ]);
            });
        }

        if ($panel->getId() === 'server') {
            BackupResource::modifyTable(function (Table $table) {
                // Auto-sync Google Drive backups for this server
                try {
                    $server = Filament::getTenant();
                    if ($server instanceof Server) {
                        app(GDriveBackupService::class)->syncServerBackups($server);
                    }
                } catch (\Throwable) {
                }

                return $table
                    ->poll('5s')
                    ->pushColumns([
                        TextColumn::make('backupHost.name')
                            ->label('Хранилище')
                            ->badge()
                            ->color(fn ($state) => $state === 'Google Drive' ? 'primary' : 'gray'),
                        TextColumn::make('created_at_formatted')
                            ->label('Точная дата')
                            ->state(fn (Backup $record) => ($record->completed_at ?: $record->created_at)?->format('d.m.Y H:i:s'))
                            ->sortable(query: fn ($query, $direction) => $query->orderBy('created_at', $direction)),
                    ])
                    ->pushRecordActions([
                        Action::make('restore_gdrive')
                            ->label('Восстановить с GDrive')
                            ->icon(TablerIcon::CloudDownload)
                            ->color('warning')
                            ->visible(fn (Backup $record) => $record->backupHost?->schema === 'gdrive' && $record->status === BackupStatus::Successful)
                            ->requiresConfirmation()
                            ->modalHeading(fn (Backup $record) => "Восстановление сервера из {$record->name}")
                            ->modalDescription('Восстановить файлы сервера из этого архива на Google Диске? Сервер будет обновлен.')
                            ->modalSubmitActionLabel('Восстановить')
                            ->action(function (Backup $record) {
                                $service = app(GDriveBackupService::class);
                                $container = $service->detectContainerName($record->server);
                                $service->triggerServerRestore($container, $record->name);

                                Notification::make()
                                    ->title('Восстановление запущено')
                                    ->body("Сервер {$container} восстанавливается из архива {$record->name}.")
                                    ->success()
                                    ->send();
                            }),

                        Action::make('delete_gdrive')
                            ->label('Удалить с GDrive')
                            ->icon(TablerIcon::Trash)
                            ->color('danger')
                            ->visible(fn (Backup $record) => $record->backupHost?->schema === 'gdrive')
                            ->requiresConfirmation()
                            ->modalHeading(fn (Backup $record) => "Удаление архива {$record->name}")
                            ->modalDescription('Вы действительно хотите удалить этот архив с Google Диска?')
                            ->modalSubmitActionLabel('Удалить')
                            ->action(function (Backup $record) {
                                $name = $record->name;
                                $service = app(GDriveBackupService::class);
                                $service->deleteServerBackup($record);

                                Notification::make()
                                    ->title('Бэкап удален')
                                    ->body("Архив {$name} успешно удален с Google Диска.")
                                    ->success()
                                    ->send();
                            }),
                    ])
                    ->pushToolbarActions([
                        static::getDiagnosticTestAction(),

                        Action::make('gdrive_server_backup')
                            ->label('Бэкап на Google Диск')
                            ->icon(TablerIcon::BrandGoogleDrive)
                            ->color('primary')
                            ->requiresConfirmation()
                            ->modalHeading('Бэкап сервера на Google Диск')
                            ->modalDescription('Создать резервную копию данного игрового сервера и отправить в потоке на Google Диск? Запись сразу появится в списке.')
                            ->modalSubmitActionLabel('Создать бэкап')
                            ->action(function () {
                                /** @var Server $server */
                                $server = Filament::getTenant();
                                $service = app(GDriveBackupService::class);
                                $backup = $service->createServerBackup($server);

                                Notification::make()
                                    ->title('Создание бэкапа запущено')
                                    ->body("Архив {$backup->name} создается и отправляется на Google Диск. Статус отображается в списке.")
                                    ->success()
                                    ->send();
                            }),

                        Action::make('gdrive_sync')
                            ->label('Синхронизировать Google Диск')
                            ->icon(TablerIcon::Refresh)
                            ->color('gray')
                            ->action(function () {
                                /** @var Server $server */
                                $server = Filament::getTenant();
                                if ($server instanceof Server) {
                                    app(GDriveBackupService::class)->syncServerBackups($server);
                                }

                                Notification::make()
                                    ->title('Синхронизация завершена')
                                    ->body('Список резервных копий с Google Диска успешно обновлен.')
                                    ->success()
                                    ->send();
                            }),
                    ]);
            });
        }
    }

    public function boot(Panel $panel): void
    {
    }

    public function getSettingsFilePath(): string
    {
        return storage_path('app/gdrive_backup_settings.json');
    }

    public function getSettingsFormData(): array
    {
        $path = $this->getSettingsFilePath();
        $defaults = config('gdrive-backup');

        // Default auto_backup_servers to all servers if not set yet
        if (!isset($defaults['auto_backup_servers']) || empty($defaults['auto_backup_servers'])) {
            try {
                $defaults['auto_backup_servers'] = Server::pluck('id')->toArray();
            } catch (\Throwable) {
                $defaults['auto_backup_servers'] = [];
            }
        }

        if (File::exists($path)) {
            $data = json_decode(File::get($path), true) ?: [];
            return array_merge($defaults, $data);
        }

        return $defaults;
    }

    public function getSettingsForm(): array
    {
        return [
            Section::make('Проверка и тестирование Google Диска')
                ->description('Сквозное тестирование цепочки бэкапа: выгрузка архива, скачивание обратно, разархивация и побитовая сверка целостности SHA-256')
                ->headerActions([
                    static::getDiagnosticTestAction()
                        ->label('Запустить тест Google Диска')
                        ->icon(TablerIcon::CheckupList)
                        ->color('info'),
                ])
                ->schema([
                    Actions::make([
                        static::getDiagnosticTestAction()
                            ->label('Запустить тест Google Диска')
                            ->icon(TablerIcon::CheckupList)
                            ->color('info')
                            ->size('lg'),
                    ]),
                    Placeholder::make('diag_status_view')
                        ->hiddenLabel()
                        ->content(function () {
                            $service = app(GDriveBackupService::class);
                            $result = Cache::remember('gdrive_last_diagnostic_result', 300, function () use ($service) {
                                return $service->runFullDiagnosticTest();
                            });
                            return new HtmlString($service->renderDiagnosticHtml($result));
                        }),
                ]),

            Section::make('Параметры хранилища Google Диск')
                ->schema([
                    TextInput::make('remote')
                        ->label('Имя пульта rclone (Google Drive remote)')
                        ->default('gdrive')
                        ->required()
                        ->helperText(new HtmlString(
                            'Имя настроенного в rclone подключения к Google Диску (по умолчанию <code>gdrive</code>).<br>' .
                            '• Справка по настройке: <a href="https://rclone.org/drive/" target="_blank" style="color:#2563eb;text-decoration:underline;">Документация rclone Google Drive</a><br>' .
                            '• Создать Client ID/Secret: <a href="https://console.cloud.google.com/apis/credentials" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Cloud Console (Credentials)</a>'
                        )),
                    TextInput::make('folder')
                        ->label('Корневая папка на Google Диске')
                        ->default('TF2_Backups')
                        ->required()
                        ->helperText(new HtmlString(
                            'Папка на вашем Google Диске, в которой хранятся резервные копии.<br>' .
                            '• Открыть или создать папку: <a href="https://drive.google.com/drive/my-drive" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Диск (Мой диск)</a>'
                        )),
                    TextInput::make('retention_days')
                        ->label('Срок хранения бэкапов (в днях)')
                        ->numeric()
                        ->default(14)
                        ->helperText(new HtmlString(
                            'Количество дней хранения архивов. Старые архивы автоматически очищаются при создании нового бэкапа.<br>' .
                            '• Управление корзиной Google Диска: <a href="https://drive.google.com/drive/trash" target="_blank" style="color:#2563eb;text-decoration:underline;">Корзина Google Drive</a>'
                        )),
                ])->columns(3),

            Section::make('Подключение к игровой ноде (Node Connection)')
                ->description('Параметры SSH подключения панели управления к игровой ноде для выполнения бэкапов')
                ->schema([
                    TextInput::make('node_host')
                        ->label('IP адрес / хост ноды')
                        ->default('87.228.56.213')
                        ->required()
                        ->helperText('IP адрес сервера ноды (для Node 2: <code>87.228.56.213</code>)'),
                    TextInput::make('node_port')
                        ->label('SSH порт ноды')
                        ->numeric()
                        ->default(228)
                        ->required()
                        ->helperText('Пользовательский порт SSH ноды (для Node 2: <code>228</code>)'),
                    TextInput::make('node_user')
                        ->label('SSH пользователь')
                        ->default('root')
                        ->required()
                        ->helperText('Пользователь SSH с правами root'),
                    TextInput::make('node_key_path')
                        ->label('Путь к SSH ключу на веб-сервере')
                        ->default('/var/www/.ssh/id_ed25519')
                        ->required()
                        ->helperText('Путь к приватному ключу (по умолчанию <code>/var/www/.ssh/id_ed25519</code>)'),
                ])->columns(4),

            Section::make('Авторизация Google Drive (Обновление OAuth токена)')
                ->description('Быстрое обновление токена Google Drive без необходимости ручной правки rclone.conf на сервере ноды')
                ->schema([
                    Textarea::make('update_token')
                        ->label('Новый OAuth токен Google Drive (JSON)')
                        ->rows(3)
                        ->placeholder('{"access_token":"...","token_type":"Bearer","refresh_token":"...","expiry":"..."}')
                        ->helperText(new HtmlString(
                            '<strong>Актуальная инструкция по настройке Google Auth Platform (2026):</strong><br>' .
                            '1. В <a href="https://console.cloud.google.com/auth" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Cloud Console (Google Auth Platform)</a> перейдите в <b>Branding</b>.<br>' .
                            '2. Заполните обязательные поля: <code>App name</code> (например, <i>PGZ Storage</i> — слово <i>Rclone</i> использовать нельзя!), <code>User support email</code>, <code>Application home page</code> (<code>https://progameszet.ru</code>), <code>Application privacy policy link</code> (<code>https://progameszet.ru/help/privacy-policy/</code>), в <code>Authorized domains</code> добавьте <code>progameszet.ru</code>, и внизу в <code>Developer contact information</code> укажите ваш email.<br>' .
                            '3. Нажмите <b>Save</b> внизу страницы Branding.<br>' .
                            '4. Перейдите во вкладку <b>Audience</b> и нажмите <b>«Publish app»</b> (Опубликовать приложение) ➔ подтвердите (Confirm). Статус станет <b>In production</b>, и токен не будет сгорать каждые 7 дней.<br>' .
                            '5. На локальном ПК запустите команду: <code>rclone authorize "drive" "&lt;Client_ID&gt;" "&lt;Client_Secret&gt;"</code>, подтвердите доступ в браузере и вставьте полученную JSON-строку в это поле.<br>' .
                            '6. Нажмите <b>Сохранить</b> внизу формы — токен автоматически обновится на сервере ноды.'
                        )),
                ]),

            Section::make('Автоматическое резервное копирование по расписанию')
                ->description('Настройка ежедневного резервного копирования выбранных серверов и системы в Google Диск')
                ->schema([
                    Toggle::make('auto_backup_enabled')
                        ->label('Включить автоматические бэкапы')
                        ->default(true)
                        ->helperText(new HtmlString(
                            'Активирует регулярное резервное копирование в системном планировщике (cron) игровой ноды.'
                        )),
                    TextInput::make('auto_backup_time')
                        ->label('Время запуска автобэкапа')
                        ->default('04:30')
                        ->placeholder('04:30')
                        ->required()
                        ->regex('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/')
                        ->helperText(new HtmlString(
                            'Время в формате <code>ЧЧ:ММ</code> (24-часовой формат по времени сервера, например <code>04:30</code>).<br>' .
                            '• Справка по формату crontab: <a href="https://crontab.guru" target="_blank" style="color:#2563eb;text-decoration:underline;">Crontab.guru</a>'
                        )),
                    Checkbox::make('auto_backup_system')
                        ->label('Резервная копия всей системы (VDS / Game Node System)')
                        ->default(true)
                        ->helperText(new HtmlString(
                            'Создавать полный образ операционной системы ноды (/etc, конфигурации, сервисы).<br>' .
                            '• Управление снимками системы: <a href="/admin/system-backups" target="_blank" style="color:#2563eb;text-decoration:underline;">Резервные копии системы VDS</a>'
                        )),
                    CheckboxList::make('auto_backup_servers')
                        ->label('Игровые серверы для автоматического бэкапа')
                        ->options(function () {
                            try {
                                return Server::query()
                                    ->with('node')
                                    ->get()
                                    ->mapWithKeys(function (Server $s) {
                                        $shortUuid = substr($s->uuid, 0, 8);
                                        $nodeName = $s->node->name ?? 'Node ' . $s->node_id;
                                        return [$s->id => "{$s->name} [{$shortUuid}] ({$nodeName})"];
                                    })
                                    ->toArray();
                            } catch (\Throwable) {
                                return [];
                            }
                        })
                        ->bulkToggleable()
                        ->columns(2)
                        ->helperText(new HtmlString(
                            'Отметьте игровые серверы, для которых необходимо автоматически создавать ежедневные бэкапы.<br>' .
                            '• Управление серверами: <a href="/admin/servers" target="_blank" style="color:#2563eb;text-decoration:underline;">Список игровых серверов</a>'
                        )),
                ]),
        ];
    }

    public function saveSettings(array $data): void
    {
        $service = app(GDriveBackupService::class);

        // Handle token update if provided
        $updateToken = trim((string) ($data['update_token'] ?? ''));
        unset($data['update_token']); // Do not store raw token string in general settings json

        if (!empty($updateToken)) {
            $tokenUpdated = $service->updateRcloneToken($updateToken);
            if ($tokenUpdated) {
                // Clear cached diagnostic result so fresh test is performed
                Cache::forget('gdrive_last_diagnostic_result');

                Notification::make()
                    ->title('Токен Google Drive успешно обновлен')
                    ->body('Конфигурация rclone на игровой ноде обновлена.')
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title('Ошибка обновления токена')
                    ->body('Не удалось распознать формат токена Google Drive. Проверьте JSON токена.')
                    ->danger()
                    ->send();
            }
        }

        $path = $this->getSettingsFilePath();
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        foreach ($data as $k => $v) {
            config(["gdrive-backup.$k" => $v]);
        }

        // Apply configuration and cron to the game node
        try {
            $enabled = (bool) ($data['auto_backup_enabled'] ?? true);
            $time = (string) ($data['auto_backup_time'] ?? '04:30');
            $backupSystem = (bool) ($data['auto_backup_system'] ?? true);
            $selectedServerIds = (array) ($data['auto_backup_servers'] ?? []);

            $servers = Server::whereIn('id', $selectedServerIds)->get();
            $serversList = [];
            foreach ($servers as $s) {
                $serversList[] = [
                    'id' => $s->id,
                    'name' => $s->name,
                    'uuid' => $s->uuid,
                    'short_uuid' => strtolower(substr($s->uuid, 0, 8)),
                    'identifier' => $service->getServerIdentifier($s),
                ];
            }

            $configPayload = [
                'system' => $backupSystem,
                'servers' => $serversList,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $service->syncNodeConfiguration($enabled, $time, $configPayload);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
