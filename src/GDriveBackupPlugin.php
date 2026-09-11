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
use Filament\View\PanelsRenderHook;
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
            ->label(trans('gdrive-backup::messages.diag_btn_label'))
            ->icon(TablerIcon::PlayerPlay)
            ->color('info')
            ->modalHeading(trans('gdrive-backup::messages.diag_modal_title'))
            ->modalDescription(trans('gdrive-backup::messages.diag_modal_desc'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(trans('gdrive-backup::messages.diag_close'))
            ->schema(function () {
                $service = app(GDriveBackupService::class);
                $cached = Cache::get('gdrive_last_diagnostic_result');

                return [
                    Placeholder::make('diagnostic_results')
                        ->hiddenLabel()
                        ->content(new HtmlString($service->renderRealtimeDiagnosticWidget($cached))),
                ];
            });
    }

    public function register(Panel $panel): void
    {
        $panel->renderHook(
            PanelsRenderHook::BODY_END,
            fn () => new HtmlString(GDriveBackupService::getWidgetScriptHtml())
        );

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
                        ->label(trans('gdrive-backup::messages.plural_label'))
                        ->icon(TablerIcon::Server)
                        ->color('info')
                        ->url(fn () => SystemBackupResource::getUrl()),

                    Action::make('gdrive_system_restore')
                        ->label(trans('gdrive-backup::messages.restore'))
                        ->icon(TablerIcon::CloudDownload)
                        ->color('danger')
                        ->modalHeading(trans('gdrive-backup::messages.restore_confirm_desc'))
                        ->modalDescription(trans('gdrive-backup::messages.restore_confirm_desc'))
                        ->modalSubmitActionLabel(trans('gdrive-backup::messages.restore_submit'))
                        ->schema([
                            Select::make('backup_file')
                                ->label(trans('gdrive-backup::messages.archive_name'))
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
                                }),
                            TextInput::make('confirmation')
                                ->label(trans('gdrive-backup::messages.restore_confirm_input'))
                                ->placeholder(trans('gdrive-backup::messages.restore_confirm_placeholder'))
                                ->required()
                                ->rules(['in:RESTORE']),
                        ])
                        ->action(function (array $data) {
                            $service = app(GDriveBackupService::class);
                            $service->triggerSystemRestore($data['backup_file']);

                            Notification::make()
                                ->title(trans('gdrive-backup::messages.system_restore_started_title'))
                                ->body(trans('gdrive-backup::messages.system_restore_started_body', ['name' => $data['backup_file']]))
                                ->success()
                                ->send();
                        }),

                    Action::make('gdrive_system_backup')
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
                            ->label(trans('gdrive-backup::messages.storage'))
                            ->badge()
                            ->color(fn ($state) => $state === 'Google Drive' ? 'primary' : 'gray'),
                        TextColumn::make('created_at_formatted')
                            ->label(trans('gdrive-backup::messages.exact_date'))
                            ->state(fn (Backup $record) => ($record->completed_at ?: $record->created_at)?->format('d.m.Y H:i:s'))
                            ->sortable(query: fn ($query, $direction) => $query->orderBy('created_at', $direction)),
                    ])
                    ->pushRecordActions([
                        Action::make('restore_gdrive')
                            ->label(trans('gdrive-backup::messages.restore_from_gdrive'))
                            ->icon(TablerIcon::CloudDownload)
                            ->color('warning')
                            ->visible(fn (Backup $record) => $record->backupHost?->schema === 'gdrive' && $record->status === BackupStatus::Successful)
                            ->requiresConfirmation()
                            ->modalHeading(fn (Backup $record) => trans('gdrive-backup::messages.server_restore_title', ['name' => $record->name]))
                            ->modalDescription(trans('gdrive-backup::messages.server_restore_desc'))
                            ->modalSubmitActionLabel(trans('gdrive-backup::messages.restore'))
                            ->action(function (Backup $record) {
                                $service = app(GDriveBackupService::class);
                                $container = $service->detectContainerName($record->server);
                                $service->triggerServerRestore($container, $record->name);

                                Notification::make()
                                    ->title(trans('gdrive-backup::messages.server_restore_started_title'))
                                    ->body(trans('gdrive-backup::messages.server_restore_started_body', ['container' => $container, 'name' => $record->name]))
                                    ->success()
                                    ->send();
                            }),

                        Action::make('delete_gdrive')
                            ->label(trans('gdrive-backup::messages.delete_from_gdrive'))
                            ->icon(TablerIcon::Trash)
                            ->color('danger')
                            ->visible(fn (Backup $record) => $record->backupHost?->schema === 'gdrive')
                            ->requiresConfirmation()
                            ->modalHeading(fn (Backup $record) => trans('gdrive-backup::messages.server_delete_title', ['name' => $record->name]))
                            ->modalDescription(trans('gdrive-backup::messages.server_delete_desc'))
                            ->modalSubmitActionLabel(trans('gdrive-backup::messages.delete'))
                            ->action(function (Backup $record) {
                                $name = $record->name;
                                $service = app(GDriveBackupService::class);
                                $service->deleteServerBackup($record);

                                Notification::make()
                                    ->title(trans('gdrive-backup::messages.server_backup_deleted_title'))
                                    ->body(trans('gdrive-backup::messages.server_backup_deleted_body', ['name' => $name]))
                                    ->success()
                                    ->send();
                            }),
                    ])
                    ->pushToolbarActions([
                        static::getDiagnosticTestAction(),

                        Action::make('gdrive_server_backup')
                            ->label(trans('gdrive-backup::messages.backup_to_gdrive'))
                            ->icon(TablerIcon::BrandGoogleDrive)
                            ->color('primary')
                            ->requiresConfirmation()
                            ->modalHeading(trans('gdrive-backup::messages.server_backup_modal_title'))
                            ->modalDescription(trans('gdrive-backup::messages.server_backup_modal_desc'))
                            ->modalSubmitActionLabel(trans('gdrive-backup::messages.create_backup_submit'))
                            ->action(function () {
                                /** @var Server $server */
                                $server = Filament::getTenant();
                                $service = app(GDriveBackupService::class);
                                $backup = $service->createServerBackup($server);

                                Notification::make()
                                    ->title(trans('gdrive-backup::messages.server_backup_started_title'))
                                    ->body(trans('gdrive-backup::messages.server_backup_started_body', ['name' => $backup->name]))
                                    ->success()
                                    ->send();
                            }),

                        Action::make('gdrive_sync')
                            ->label(trans('gdrive-backup::messages.sync_gdrive'))
                            ->icon(TablerIcon::Refresh)
                            ->color('gray')
                            ->action(function () {
                                /** @var Server $server */
                                $server = Filament::getTenant();
                                if ($server instanceof Server) {
                                    app(GDriveBackupService::class)->syncServerBackups($server);
                                }

                                Notification::make()
                                    ->title(trans('gdrive-backup::messages.sync_completed_title'))
                                    ->body(trans('gdrive-backup::messages.sync_completed_body'))
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
        return [
            Section::make(trans('gdrive-backup::messages.settings_diag_section'))
                ->description(trans('gdrive-backup::messages.settings_diag_desc'))
                ->schema([
                    Placeholder::make('diag_status_view')
                        ->hiddenLabel()
                        ->content(function () {
                            $service = app(GDriveBackupService::class);
                            $cached = Cache::get('gdrive_last_diagnostic_result');
                            return new HtmlString($service->renderRealtimeDiagnosticWidget($cached));
                        }),
                ]),

            Section::make(trans('gdrive-backup::messages.settings_storage_section'))
                ->schema([
                    TextInput::make('remote')
                        ->label(trans('gdrive-backup::messages.settings_remote_label'))
                        ->default('gdrive')
                        ->required()
                        ->helperText(new HtmlString(
                            '• rclone Google Drive: <a href="https://rclone.org/drive/" target="_blank" style="color:#2563eb;text-decoration:underline;">rclone docs</a><br>' .
                            '• 1. <a href="https://console.cloud.google.com/apis/library/drive.googleapis.com" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Drive API Library</a><br>' .
                            '• 2. <a href="https://console.cloud.google.com/auth/clients" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Auth Platform (Clients)</a>'
                        )),
                    TextInput::make('folder')
                        ->label(trans('gdrive-backup::messages.settings_folder_label'))
                        ->default('TF2_Backups')
                        ->required()
                        ->helperText(new HtmlString(
                            '• <a href="https://drive.google.com/drive/my-drive" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Drive (My Drive)</a>'
                        )),
                    TextInput::make('retention_days')
                        ->label(trans('gdrive-backup::messages.settings_retention_label'))
                        ->numeric()
                        ->default(14)
                        ->helperText(new HtmlString(
                            '• <a href="https://drive.google.com/drive/trash" target="_blank" style="color:#2563eb;text-decoration:underline;">Google Drive Trash</a>'
                        )),
                ])->columns(3),

            Section::make(trans('gdrive-backup::messages.settings_node_section'))
                ->description(trans('gdrive-backup::messages.settings_node_desc'))
                ->schema([
                    TextInput::make('node_host')
                        ->label(trans('gdrive-backup::messages.settings_node_host'))
                        ->default('87.228.56.213')
                        ->required()
                        ->helperText('Node IP (e.g. <code>87.228.56.213</code>)'),
                    TextInput::make('node_port')
                        ->label(trans('gdrive-backup::messages.settings_node_port'))
                        ->numeric()
                        ->default(228)
                        ->required()
                        ->helperText('SSH port (e.g. <code>228</code>)'),
                    TextInput::make('node_user')
                        ->label(trans('gdrive-backup::messages.settings_node_user'))
                        ->default('root')
                        ->required()
                        ->helperText('SSH user with root privileges'),
                    TextInput::make('node_key_path')
                        ->label(trans('gdrive-backup::messages.settings_node_key_path'))
                        ->default('/var/www/.ssh/id_ed25519')
                        ->required()
                        ->helperText('SSH private key path (e.g. <code>/var/www/.ssh/id_ed25519</code>)'),
                ])->columns(4),

            Section::make(trans('gdrive-backup::messages.settings_token_section'))
                ->description(trans('gdrive-backup::messages.settings_token_desc'))
                ->schema([
                    Textarea::make('update_token')
                        ->label(trans('gdrive-backup::messages.settings_token_label'))
                        ->rows(4)
                        ->placeholder('{"access_token":"...","token_type":"Bearer","refresh_token":"...","expiry":"..."}')
                        ->helperText(new HtmlString(
                            '<div style="line-height:1.7;font-size:12px;">' .
                            '<b>1.</b> Google Drive API ➔ Enable<br>' .
                            '<b>2.</b> Google Auth Platform ➔ Clients ➔ Create Desktop App Client (Client ID & Secret)<br>' .
                            '<b>3.</b> Run: <code>rclone authorize "drive" "&lt;Client_ID&gt;" "&lt;Client_Secret&gt;"</code><br>' .
                            '<b>4.</b> Paste the output JSON <code>{...}</code> here and click Save.' .
                            '</div>'
                        )),
                ]),

            Section::make(trans('gdrive-backup::messages.settings_schedule_section'))
                ->description(trans('gdrive-backup::messages.settings_schedule_desc'))
                ->schema([
                    Toggle::make('auto_backup_enabled')
                        ->label(trans('gdrive-backup::messages.settings_auto_enabled'))
                        ->default(true),
                    TextInput::make('auto_backup_time')
                        ->label(trans('gdrive-backup::messages.settings_auto_time'))
                        ->default('04:30')
                        ->placeholder('04:30')
                        ->required()
                        ->regex('/^([01]?[0-9]|2[0-3]):[0-5][0-9]$/'),
                    Checkbox::make('auto_backup_system')
                        ->label(trans('gdrive-backup::messages.settings_auto_system'))
                        ->default(true),
                    CheckboxList::make('auto_backup_servers')
                        ->label(trans('gdrive-backup::messages.settings_auto_servers'))
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
                        ->columns(2),
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
                    ->title(trans('gdrive-backup::messages.token_updated_title'))
                    ->body(trans('gdrive-backup::messages.token_updated_body'))
                    ->success()
                    ->send();
            } else {
                Notification::make()
                    ->title(trans('gdrive-backup::messages.token_error_title'))
                    ->body(trans('gdrive-backup::messages.token_error_body'))
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
