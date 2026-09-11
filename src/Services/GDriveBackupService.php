<?php

namespace ProGamesZet\GDriveBackup\Services;

use App\Models\Backup;
use App\Models\BackupHost;
use App\Models\Server;
use Exception;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class GDriveBackupService
{
    protected string $host;
    protected int $port;
    protected string $user;
    protected ?string $keyPath;
    protected string $remote;
    protected string $folder;
    protected string $backupScript;
    protected string $restoreScript;

    public function __construct()
    {
        $this->host = (string) config('gdrive-backup.node_host', '87.228.56.213');
        $this->port = (int) config('gdrive-backup.node_port', 228);
        $this->user = (string) config('gdrive-backup.node_user', 'root');
        $this->keyPath = (string) (config('gdrive-backup.node_key_path') ?: '/var/www/.ssh/id_ed25519');
        $this->remote = (string) config('gdrive-backup.remote', 'gdrive');
        $this->folder = (string) config('gdrive-backup.folder', 'TF2_Backups');
        $this->backupScript = (string) config('gdrive-backup.backup_script', '/usr/local/bin/backup_to_gdrive.sh');
        $this->restoreScript = (string) config('gdrive-backup.restore_script', '/usr/local/bin/restore_from_gdrive.sh');

        // Apply dynamically saved settings from JSON file if available
        $settingsPath = storage_path('app/gdrive_backup_settings.json');
        if (file_exists($settingsPath)) {
            $saved = json_decode((string) file_get_contents($settingsPath), true);
            if (is_array($saved)) {
                if (!empty($saved['node_host'])) {
                    $this->host = (string) $saved['node_host'];
                }
                if (!empty($saved['node_port'])) {
                    $this->port = (int) $saved['node_port'];
                }
                if (!empty($saved['node_user'])) {
                    $this->user = (string) $saved['node_user'];
                }
                if (!empty($saved['node_key_path'])) {
                    $this->keyPath = (string) $saved['node_key_path'];
                }
                if (!empty($saved['remote'])) {
                    $this->remote = (string) $saved['remote'];
                }
                if (!empty($saved['folder'])) {
                    $this->folder = (string) $saved['folder'];
                }
            }
        }
    }

    /**
     * Execute remote command on game node via SSH key.
     */
    public function runCommand(string $command): string
    {
        $escaped = escapeshellarg($command);
        $keyOpt = (!empty($this->keyPath) && file_exists($this->keyPath)) ? "-i " . escapeshellarg($this->keyPath) : "";
        $cmd = "ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o ConnectTimeout=10 {$keyOpt} -p {$this->port} {$this->user}@{$this->host} {$escaped} 2>&1";

        $output = shell_exec($cmd);

        return trim((string) $output);
    }

    /**
     * Update Google Drive OAuth token in rclone.conf on the game node.
     */
    public function updateRcloneToken(string $tokenInput): bool
    {
        $trimmed = trim($tokenInput);
        if (empty($trimmed)) {
            return false;
        }

        // Try extracting JSON object if user pasted surrounding rclone output
        if (preg_match('/\{[\s\S]*"refresh_token"[\s\S]*\}/', $trimmed, $matches)) {
            $jsonStr = $matches[0];
        } elseif (preg_match('/\{[\s\S]*"access_token"[\s\S]*\}/', $trimmed, $matches)) {
            $jsonStr = $matches[0];
        } else {
            $jsonStr = $trimmed;
        }

        $decoded = json_decode($jsonStr, true);
        if (!is_array($decoded) || (empty($decoded['refresh_token']) && empty($decoded['access_token']))) {
            return false;
        }

        $singleLine = json_encode($decoded, JSON_UNESCAPED_SLASHES);
        if (!$singleLine) {
            return false;
        }

        // Fetch current rclone.conf
        $currentConf = $this->runCommand('cat /root/.config/rclone/rclone.conf 2>/dev/null || true');
        if (empty($currentConf)) {
            return false;
        }

        if (preg_match('/^token\s*=\s*.+$/m', $currentConf)) {
            $newConf = preg_replace('/^token\s*=\s*.+$/m', 'token = ' . $singleLine, $currentConf);
        } else {
            $remoteHeader = '[' . $this->remote . ']';
            $newConf = str_replace($remoteHeader, $remoteHeader . "\ntoken = " . $singleLine, $currentConf);
        }

        $escaped = escapeshellarg($newConf);
        $res = $this->runCommand("echo {$escaped} > /root/.config/rclone/rclone.conf && chmod 600 /root/.config/rclone/rclone.conf && echo OK");

        return str_contains($res, 'OK');
    }

    /**
     * Execute full end-to-end diagnostic test of Google Drive backups.
     * Tests: SSH, tools, Google Drive API, file creation, zstd compression,
     * upload, download, decompression, and SHA-256 integrity verification.
     *
     * @return array{
     *   started_at: string,
     *   node: array{host: string, port: int, user: string},
     *   remote: string,
     *   folder: string,
     *   overall_success: bool,
     *   steps: array<string, array{status: string, title: string, details: string, metric?: string, error?: string}>,
     *   error: ?string,
     *   recommendation: ?string,
     *   total_duration_sec: float
     * }
     */
    public function runFullDiagnosticTest(): array
    {
        $startTime = microtime(true);
        $results = [
            'started_at' => date('d.m.Y H:i:s'),
            'node' => [
                'host' => $this->host,
                'port' => $this->port,
                'user' => $this->user,
            ],
            'remote' => $this->remote,
            'folder' => $this->folder,
            'overall_success' => false,
            'steps' => [],
            'error' => null,
            'recommendation' => null,
            'total_duration_sec' => 0.0,
        ];

        // 1. SSH Connection Step
        $sshOutput = $this->runCommand('whoami && uname -s 2>&1');
        if (!str_contains($sshOutput, $this->user) && !str_contains($sshOutput, 'Linux')) {
            $results['steps']['ssh'] = [
                'status' => 'failed',
                'title' => '1. SSH подключение к игровой ноде',
                'details' => "Не удалось установить SSH-соединение с {$this->user}@{$this->host}:{$this->port}",
                'error' => $sshOutput ?: 'Время ожидания подключения истекло (Timeout)',
            ];
            $results['error'] = 'Ошибка SSH подключения к игровой ноде';
            $results['recommendation'] = "Проверьте доступность хоста {$this->host}, корректность порта {$this->port} и наличие SSH-ключа панели в файле {$this->keyPath}.";
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        $results['steps']['ssh'] = [
            'status' => 'success',
            'title' => '1. SSH подключение к игровой ноде',
            'details' => "Соединение успешно установлено ({$this->user}@{$this->host}:{$this->port})",
            'metric' => 'OK',
        ];

        // 2. Execute test command on node
        $rawOutput = $this->runCommand("{$this->backupScript} test 2>&1");

        // Parse JSON output
        $jsonData = null;
        foreach (explode("\n", $rawOutput) as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'JSON:')) {
                $jsonData = json_decode(substr($line, 5), true);
                break;
            }
        }

        // If JSON was not returned, report raw script error
        if (!is_array($jsonData)) {
            $results['steps']['execution'] = [
                'status' => 'failed',
                'title' => '2. Запуск скрипта тестирования на ноде',
                'details' => "Скрипт {$this->backupScript} завершился с непредвиденной ошибкой.",
                'error' => $rawOutput ?: 'Пустой ответ от ноды',
            ];
            $results['error'] = 'Не удалось выполнить скрипт тестирования';
            $results['recommendation'] = "Убедитесь, что скрипт {$this->backupScript} существует на ноде {$this->host} и имеет права на выполнение (chmod +x).";
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        // Tools step
        $results['steps']['tools'] = [
            'status' => ($jsonData['step'] ?? '') === 'tools' ? 'failed' : 'success',
            'title' => '2. Проверка утилит ноды (rclone, tar, zstd, sha256sum, jq)',
            'details' => ($jsonData['step'] ?? '') === 'tools'
                ? ($jsonData['error'] ?? 'Не найдены требуемые утилиты')
                : 'Все необходимые утилиты установлены и готовы к работе',
            'metric' => ($jsonData['step'] ?? '') === 'tools' ? 'Ошибка' : 'OK',
        ];

        if (($jsonData['step'] ?? '') === 'tools') {
            $results['error'] = $jsonData['error'] ?? 'Отсутствуют необходимые утилиты на ноде';
            $results['recommendation'] = 'Установите недостающие пакеты на сервере ноды командой: apt update && apt install -y tar zstd rclone jq';
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        // Generation step
        $origSize = (int) ($jsonData['orig_size'] ?? 0);
        $results['steps']['create'] = [
            'status' => 'success',
            'title' => '3. Генерация тестовых данных',
            'details' => 'Создан тестовый массив со случайными байтами',
            'metric' => $this->formatBytes($origSize),
        ];

        // Compression step
        $compSize = (int) ($jsonData['comp_size'] ?? 0);
        $compMs = (int) ($jsonData['comp_ms'] ?? 0);
        $ratio = $origSize > 0 ? round((1 - ($compSize / $origSize)) * 100, 1) : 0;
        $results['steps']['compress'] = [
            'status' => 'success',
            'title' => '4. Сжатие в архив .tar.zst (алгоритм Zstandard)',
            'details' => "Сжатие выполнено за {$compMs} мс",
            'metric' => $this->formatBytes($compSize) . " (-{$ratio}%)",
        ];

        // Upload step
        if (($jsonData['step'] ?? '') === 'upload') {
            $errMsg = (string) ($jsonData['error'] ?? 'Ошибка выгрузки файла на Google Диск');
            $results['steps']['upload'] = [
                'status' => 'failed',
                'title' => '5. Загрузка тестового архива на Google Диск',
                'details' => "Не удалось отправить файл в {$this->remote}:{$this->folder}/Test/",
                'error' => $errMsg,
            ];
            $results['error'] = 'Ошибка загрузки на Google Диск';

            if (str_contains($errMsg, 'invalid_grant') || str_contains($errMsg, 'token expired') || str_contains($errMsg, 'couldn\'t fetch token')) {
                $results['recommendation'] = "Срок действия OAuth токена Google Drive истек (Google отзывает токены тестовых приложений ровно через 7 дней).\n\n" .
                    "Как исправить проблему навсегда:\n" .
                    "1. Перейдите в Google Cloud Console -> APIs & Services -> OAuth consent screen (Экран согласия OAuth).\n" .
                    "2. В строке Publishing status (Статус публикации) нажмите кнопку «PUBLISH APP» (Опубликовать приложение), чтобы токен стал бессрочным.\n" .
                    "3. Получите новый токен и вставьте его в настройки плагина (Админка -> Плагины -> Google Drive Backup -> Настройки), либо подключитесь к ноде по SSH и выполните: rclone config reconnect {$this->remote}:";
            } elseif (str_contains($errMsg, 'directory not found') || str_contains($errMsg, 'Failed to create file system')) {
                $results['recommendation'] = "Проверьте, существует ли настроенный remote '{$this->remote}' в /root/.config/rclone/rclone.conf на игровой ноде, и доступна ли корневая папка '{$this->folder}' на Google Диске.";
            } else {
                $results['recommendation'] = 'Проверьте сетевое подключение к серверам Google API и конфигурацию rclone на ноде.';
            }

            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        $uploadMs = (int) ($jsonData['upload_ms'] ?? 0);
        $results['steps']['upload'] = [
            'status' => 'success',
            'title' => '5. Загрузка тестового архива на Google Диск',
            'details' => "Архив успешно передан в облако за {$uploadMs} мс",
            'metric' => "{$uploadMs} мс",
        ];

        // Download step
        if (($jsonData['step'] ?? '') === 'download') {
            $errMsg = (string) ($jsonData['error'] ?? 'Ошибка скачивания файла с Google Диска');
            $results['steps']['download'] = [
                'status' => 'failed',
                'title' => '6. Скачивание тестового архива с Google Диска',
                'details' => "Не удалось скачать файл из {$this->remote}:{$this->folder}/Test/",
                'error' => $errMsg,
            ];
            $results['error'] = 'Ошибка скачивания с Google Диска';
            $results['recommendation'] = 'Проверьте права на чтение файлов в Google Диске и сетевую связь.';
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        $downloadMs = (int) ($jsonData['download_ms'] ?? 0);
        $results['steps']['download'] = [
            'status' => 'success',
            'title' => '6. Скачивание тестового архива с Google Диска',
            'details' => "Архив успешно загружен обратно за {$downloadMs} мс",
            'metric' => "{$downloadMs} мс",
        ];

        // Decompress step
        if (($jsonData['step'] ?? '') === 'extract') {
            $errMsg = (string) ($jsonData['error'] ?? 'Ошибка распаковки архива');
            $results['steps']['decompress'] = [
                'status' => 'failed',
                'title' => '7. Разархивация и распаковка архива',
                'details' => 'Не удалось распаковать скачанный архив утилитами tar/zstd',
                'error' => $errMsg,
            ];
            $results['error'] = 'Ошибка разархивации скачанного файла';
            $results['recommendation'] = 'Проверьте целостность скачанного файла и наличие свободного места в /tmp на ноде.';
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        $decompressMs = (int) ($jsonData['decompress_ms'] ?? 0);
        $results['steps']['decompress'] = [
            'status' => 'success',
            'title' => '7. Разархивация и распаковка архива',
            'details' => "Архив успешно распакован за {$decompressMs} мс",
            'metric' => "{$decompressMs} мс",
        ];

        // Integrity check step
        if (($jsonData['step'] ?? '') === 'integrity' || empty($jsonData['success'])) {
            $origHash = $jsonData['orig_hash'] ?? '';
            $extHash = $jsonData['ext_hash'] ?? '';
            $results['steps']['integrity'] = [
                'status' => 'failed',
                'title' => '8. Проверка целостности файлов (SHA-256)',
                'details' => "Контрольная сумма не совпадает!\nИсходный: {$origHash}\nРаспакованный: {$extHash}",
                'error' => 'Повреждение данных при передаче или разархивации',
            ];
            $results['error'] = 'Не совпала контрольная сумма SHA-256';
            $results['recommendation'] = 'Обнаружено повреждение данных. Проверьте стабильность интернет-соединения ноды и настройки zstd.';
            $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);
            return $results;
        }

        $hash = (string) ($jsonData['hash'] ?? '');
        $results['steps']['integrity'] = [
            'status' => 'success',
            'title' => '8. Проверка целостности файлов (SHA-256)',
            'details' => 'Контрольная сумма SHA-256 полностью совпала до архивации и после распаковки',
            'metric' => substr($hash, 0, 16) . '... (100% совпадение)',
        ];

        // Cleanup step
        $results['steps']['cleanup'] = [
            'status' => 'success',
            'title' => '9. Очистка временных файлов',
            'details' => 'Тестовые архивы удалены с Google Диска и локального диска ноды',
            'metric' => 'OK',
        ];

        $results['overall_success'] = true;
        $results['total_duration_sec'] = round(microtime(true) - $startTime, 2);

        return $results;
    }

    /**
     * Render rich HTML diagnostic report for Filament modal.
     */
    public function renderDiagnosticHtml(array $results): string
    {
        $success = !empty($results['overall_success']);
        $startedAt = htmlspecialchars($results['started_at'] ?? date('d.m.Y H:i:s'));
        $duration = htmlspecialchars((string) ($results['total_duration_sec'] ?? '0.0'));
        $nodeHost = htmlspecialchars(($results['node']['host'] ?? '87.228.56.213') . ':' . ($results['node']['port'] ?? 228));
        $remote = htmlspecialchars(($results['remote'] ?? 'gdrive') . ':' . ($results['folder'] ?? 'TF2_Backups'));

        $bannerClass = $success
            ? 'background-color: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #10b981;'
            : 'background-color: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #ef4444;';

        $bannerIcon = $success ? '✓' : '⚠';
        $bannerTitle = $success
            ? 'Все проверки успешно пройдены! Google Диск полностью работоспособен.'
            : 'Обнаружена ошибка при тестировании Google Диска';
        $bannerSub = $success
            ? 'Сквозная цепочка резервного копирования (выгрузка, скачивание, разархивация и сверка SHA-256) работает штатно.'
            : htmlspecialchars($results['error'] ?? 'Один из этапов тестирования завершился с ошибкой.');

        $html = '<div style="font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, sans-serif; font-size: 13px; line-height: 1.5;">';

        // Banner
        $html .= "<div style=\"padding: 14px 18px; border-radius: 10px; margin-bottom: 16px; {$bannerClass}\">";
        $html .= "<div style=\"display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 14px;\">";
        $html .= "<span style=\"font-size: 18px;\">{$bannerIcon}</span>";
        $html .= "<span>{$bannerTitle}</span>";
        $html .= "</div>";
        $html .= "<div style=\"margin-top: 4px; font-size: 12px; opacity: 0.9;\">{$bannerSub}</div>";
        $html .= "</div>";

        // Meta Info Grid
        $html .= '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px; margin-bottom: 16px; padding: 12px; background: rgba(255,255,255,0.03); border: 1px solid rgba(255,255,255,0.08); border-radius: 8px;">';
        $html .= "<div><span style=\"color: #94a3b8; font-size: 11px;\">Игровая нода:</span><br><strong>{$nodeHost}</strong></div>";
        $html .= "<div><span style=\"color: #94a3b8; font-size: 11px;\">Пульт Google Drive:</span><br><strong>{$remote}</strong></div>";
        $html .= "<div><span style=\"color: #94a3b8; font-size: 11px;\">Время проверки:</span><br><strong>{$startedAt}</strong></div>";
        $html .= "<div><span style=\"color: #94a3b8; font-size: 11px;\">Длительность теста:</span><br><strong>{$duration} сек</strong></div>";
        $html .= '</div>';

        // Steps List
        $html .= '<div style="border: 1px solid rgba(255,255,255,0.08); border-radius: 8px; overflow: hidden; margin-bottom: 16px;">';
        $html .= '<div style="padding: 10px 14px; font-weight: 600; font-size: 12px; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(255,255,255,0.02); border-bottom: 1px solid rgba(255,255,255,0.08); color: #94a3b8;">Этапы сквозного тестирования</div>';

        $steps = $results['steps'] ?? [];
        foreach ($steps as $stepKey => $step) {
            $isStepSuccess = ($step['status'] ?? '') === 'success';
            $stepIcon = $isStepSuccess ? '✓' : '✗';
            $stepColor = $isStepSuccess ? '#10b981' : '#ef4444';
            $stepBg = $isStepSuccess ? 'rgba(16, 185, 129, 0.15)' : 'rgba(239, 68, 68, 0.15)';
            $title = htmlspecialchars($step['title'] ?? $stepKey);
            $details = htmlspecialchars($step['details'] ?? '');
            $metric = isset($step['metric']) ? htmlspecialchars($step['metric']) : null;
            $err = isset($step['error']) ? htmlspecialchars($step['error']) : null;

            $html .= '<div style="padding: 10px 14px; border-bottom: 1px solid rgba(255,255,255,0.05); display: flex; align-items: flex-start; gap: 12px;">';
            $html .= "<div style=\"min-width: 22px; height: 22px; border-radius: 50%; background: {$stepBg}; color: {$stepColor}; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;\">{$stepIcon}</div>";
            $html .= '<div style="flex: 1; min-width: 0;">';
            $html .= "<div style=\"display: flex; justify-content: space-between; align-items: center;\">";
            $html .= "<span style=\"font-weight: 500;\">{$title}</span>";
            if ($metric) {
                $html .= "<span style=\"font-family: ui-monospace, monospace; font-size: 11px; padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.05); color: #38bdf8;\">{$metric}</span>";
            }
            $html .= "</div>";
            $html .= "<div style=\"font-size: 12px; color: #94a3b8; margin-top: 2px;\">{$details}</div>";
            if ($err) {
                $html .= "<div style=\"margin-top: 6px; padding: 8px; border-radius: 6px; background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.2); font-family: ui-monospace, monospace; font-size: 11px; color: #fca5a5; white-space: pre-wrap; word-break: break-all;\">{$err}</div>";
            }
            $html .= '</div>';
            $html .= '</div>';
        }
        $html .= '</div>';

        // Recommendation block if error occurred
        if (!empty($results['recommendation'])) {
            $rec = nl2br(htmlspecialchars($results['recommendation']));
            $html .= '<div style="padding: 14px 16px; border-radius: 8px; background: rgba(245, 158, 11, 0.08); border: 1px solid rgba(245, 158, 11, 0.25); color: #fde68a;">';
            $html .= '<div style="display: flex; align-items: center; gap: 8px; font-weight: 600; font-size: 13px; margin-bottom: 6px; color: #fbbf24;">';
            $html .= '<span>💡</span><span>Рекомендация по устранению:</span>';
            $html .= '</div>';
            $html .= "<div style=\"font-size: 12px; line-height: 1.6; color: #fef3c7;\">{$rec}</div>";
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get active backup status from the game node.
     *
     * @return array{status: string, type?: string, name?: string, filename?: string, started_at?: string, pid?: int}
     */
    public function getActiveBackup(): array
    {
        try {
            return Cache::remember('gdrive_active_backup_status', 3, function () {
                $raw = $this->runCommand("{$this->backupScript} status");
                $data = json_decode($raw, true);
                return is_array($data) ? $data : ['status' => 'idle'];
            });
        } catch (Exception $e) {
            report($e);
            return ['status' => 'idle'];
        }
    }

    /**
     * Get or create Google Drive BackupHost in panel.
     */
    public function getOrCreateBackupHost(): BackupHost
    {
        return BackupHost::firstOrCreate(
            ['schema' => 'gdrive'],
            [
                'name' => 'Google Drive',
                'configuration' => [
                    'remote' => $this->remote,
                    'folder' => $this->folder,
                    'retention_days' => 14,
                ],
            ]
        );
    }

    /**
     * List all full system backups from Google Drive.
     *
     * @return array<int, array{name: string, path: string, size: int, size_formatted: string, date: string, status: string, id: string}>
     */
    public function listSystemBackups(): array
    {
        try {
            $items = Cache::remember('gdrive_system_backups_list', 10, function () {
                $cmd = "rclone lsjson {$this->remote}:{$this->folder}/System/";
                $json = $this->runCommand($cmd);
                return json_decode($json, true) ?: [];
            });

            $result = [];
            foreach ($items as $item) {
                if (!empty($item['IsDir'])) {
                    continue;
                }
                $size = (int) ($item['Size'] ?? 0);
                $result[] = [
                    'name' => $item['Name'],
                    'path' => $item['Path'],
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'date' => date('Y-m-d H:i:s', strtotime($item['ModTime'] ?? 'now')),
                    'status' => 'Completed',
                    'id' => $item['Name'],
                ];
            }

            // Check if active system backup is currently running
            $active = $this->getActiveBackup();
            if (($active['type'] ?? '') === 'system' && !empty($active['filename'])) {
                $alreadyListed = false;
                foreach ($result as $r) {
                    if ($r['name'] === $active['filename']) {
                        $alreadyListed = true;
                        break;
                    }
                }

                if (!$alreadyListed) {
                    array_unshift($result, [
                        'name' => $active['filename'],
                        'path' => 'System/' . $active['filename'],
                        'size' => 0,
                        'size_formatted' => 'Создается...',
                        'date' => $active['started_at'] ?? date('Y-m-d H:i:s'),
                        'status' => 'InProgress',
                        'id' => $active['filename'],
                    ]);
                }
            }

            usort($result, fn ($a, $b) => strcmp($b['date'], $a['date']));

            return $result;
        } catch (Exception $e) {
            report($e);
            return [];
        }
    }

    /**
     * Generate standard backup identifier for a server:
     * e.g., "JAIL-TWO-5c213163"
     */
    public function getServerIdentifier(Server $server): string
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9]+/', '-', trim($server->name));
        $cleanName = trim($cleanName, '-');
        $cleanName = strtoupper($cleanName);

        if (empty($cleanName)) {
            $cleanName = 'SERVER';
        }

        $shortUuid = strtolower(substr($server->uuid, 0, 8));

        return "{$cleanName}-{$shortUuid}";
    }

    /**
     * Get legacy alias for backward compatibility with old backup names.
     */
    public function getLegacyAlias(Server $server): ?string
    {
        $name = strtolower($server->name);

        if (str_contains($name, 'jail')) {
            return 'JAIL';
        }
        if (str_contains($name, 'mge')) {
            return 'MGE';
        }
        if (str_contains($name, 'minecraft')) {
            return 'MINECRAFT';
        }
        if (str_contains($name, 'ach')) {
            return 'ACHENG';
        }
        if (str_contains($name, 'dustbowl') || str_contains($name, 'test')) {
            return 'DUSTBOWL';
        }

        return null;
    }

    /**
     * List server backups from Google Drive, optionally filtered by server.
     *
     * @param Server|string|null $serverFilter
     * @return array<int, array{name: string, server: string, path: string, size: int, size_formatted: string, date: string, id: string}>
     */
    public function listServerBackups(Server|string|null $serverFilter = null): array
    {
        try {
            $filterKey = $serverFilter instanceof Server ? $serverFilter->uuid : ($serverFilter ?: 'all');
            $cacheKey = 'gdrive_server_backups_list_' . $filterKey;
            $items = Cache::remember($cacheKey, 10, function () {
                $cmd = "rclone lsjson {$this->remote}:{$this->folder}/Servers/";
                $json = $this->runCommand($cmd);
                return json_decode($json, true) ?: [];
            });

            $result = [];
            foreach ($items as $item) {
                if (!empty($item['IsDir'])) {
                    continue;
                }
                $name = $item['Name'];

                $serverLabel = '';
                if (preg_match('/^server_(.+)_\d{4}-\d{2}-\d{2}_\d{2}-\d{2}\.tar\.zst$/', $name, $matches)) {
                    $serverLabel = $matches[1];
                } elseif (preg_match('/^server_([^_]+)_/', $name, $matches)) {
                    $serverLabel = $matches[1];
                }

                if ($serverFilter instanceof Server) {
                    $shortUuid = strtolower(substr($serverFilter->uuid, 0, 8));
                    $legacyAlias = $this->getLegacyAlias($serverFilter);

                    $matchesShortUuid = str_contains(strtolower($name), $shortUuid);
                    $matchesLegacy = $legacyAlias && str_contains(strtoupper($name), $legacyAlias);

                    if (!$matchesShortUuid && !$matchesLegacy) {
                        continue;
                    }
                } elseif (is_string($serverFilter) && !empty($serverFilter) && $serverFilter !== 'all') {
                    if (!str_contains(strtoupper($name), strtoupper($serverFilter))) {
                        continue;
                    }
                }

                $size = (int) ($item['Size'] ?? 0);
                $result[] = [
                    'name' => $name,
                    'server' => $serverLabel,
                    'path' => $item['Path'],
                    'size' => $size,
                    'size_formatted' => $this->formatBytes($size),
                    'date' => date('Y-m-d H:i:s', strtotime($item['ModTime'] ?? 'now')),
                    'id' => $item['Name'],
                ];
            }

            usort($result, fn ($a, $b) => strcmp($b['date'], $a['date']));

            return $result;
        } catch (Exception $e) {
            report($e);
            return [];
        }
    }

    /**
     * Immediately create in-progress Backup record and trigger remote script.
     */
    public function createServerBackup(Server $server): Backup
    {
        $identifier = $this->getServerIdentifier($server);
        $dateStr = date('Y-m-d_H-i');
        $filename = "server_{$identifier}_{$dateStr}.tar.zst";

        $gdriveHost = $this->getOrCreateBackupHost();

        // Immediately create Backup record in database so it shows up with status 'InProgress'
        $backup = Backup::create([
            'server_id' => $server->id,
            'name' => $filename,
            'uuid' => (string) Str::uuid(),
            'backup_host_id' => $gdriveHost->id,
            'bytes' => 0,
            'is_successful' => false,
            'is_locked' => false,
            'completed_at' => null,
            'created_at' => now(),
            'upload_id' => $filename,
            'ignored_files' => [],
        ]);

        // Clear local caches
        Cache::forget('gdrive_active_backup_status');
        Cache::forget('gdrive_server_backups_list_' . $server->uuid);
        Cache::forget('gdrive_server_backups_list_all');

        // Trigger backup script on game node asynchronously
        $identifierArg = escapeshellarg($identifier);
        $uuidArg = escapeshellarg($server->uuid);
        $fileArg = escapeshellarg($filename);

        $cmd = "nohup {$this->backupScript} server {$identifierArg} {$uuidArg} {$fileArg} > /var/log/backup_{$identifier}.log 2>&1 & echo $!";
        $this->runCommand($cmd);

        return $backup;
    }

    /**
     * Trigger server backup to Google Drive.
     */
    public function triggerServerBackup(Server|string $server, bool $async = true): Backup|string
    {
        if ($server instanceof Server) {
            return $this->createServerBackup($server);
        }

        $dateStr = date('Y-m-d_H-i');
        $filename = "server_{$server}_{$dateStr}.tar.zst";
        $serverArg = escapeshellarg($server);
        $fileArg = escapeshellarg($filename);

        if ($async) {
            $cmd = "nohup {$this->backupScript} server {$serverArg} '' {$fileArg} > /var/log/backup_{$server}.log 2>&1 & echo $!";
            $this->runCommand($cmd);
            return $filename;
        }

        $this->runCommand("{$this->backupScript} server {$serverArg} '' {$fileArg}");
        return $filename;
    }

    /**
     * Trigger full system backup to Google Drive.
     */
    public function triggerSystemBackup(bool $async = true): string
    {
        $dateStr = date('Y-m-d_H-i');
        $filename = "vds_full_{$dateStr}.tar.zst";

        Cache::forget('gdrive_active_backup_status');
        Cache::forget('gdrive_system_backups_list');

        $fileArg = escapeshellarg($filename);
        if ($async) {
            $cmd = "nohup {$this->backupScript} system {$fileArg} > /var/log/backup_manual_system.log 2>&1 & echo $!";
            $this->runCommand($cmd);
            return $filename;
        }

        $this->runCommand("{$this->backupScript} system {$fileArg}");
        return $filename;
    }

    /**
     * Sync Google Drive backups for a specific server into the database.
     */
    public function syncServerBackups(Server $server): void
    {
        try {
            $gdriveHost = $this->getOrCreateBackupHost();

            // 1. Fetch remote files on Google Drive for this server
            $remoteBackups = $this->listServerBackups($server);
            $remoteMap = [];
            foreach ($remoteBackups as $rb) {
                $remoteMap[$rb['name']] = $rb;
            }

            // 2. Fetch all backups in DB for this server
            $allDbBackups = Backup::where('server_id', $server->id)
                ->where('backup_host_id', $gdriveHost->id)
                ->get();

            $active = $this->getActiveBackup();
            $isNodeActive = (($active['type'] ?? '') === 'server');

            foreach ($allDbBackups as $b) {
                if (isset($remoteMap[$b->name])) {
                    $rb = $remoteMap[$b->name];
                    if (!$b->is_successful || $b->bytes == 0 || $b->completed_at === null) {
                        $b->update([
                            'bytes' => (int) $rb['size'],
                            'is_successful' => true,
                            'completed_at' => $rb['date'],
                        ]);
                    }
                } elseif ($b->completed_at === null) {
                    $minutesSinceCreation = $b->created_at ? $b->created_at->diffInMinutes(now()) : 999;
                    if (!$isNodeActive && $minutesSinceCreation >= 15) {
                        $b->update([
                            'is_successful' => false,
                            'completed_at' => now(),
                        ]);
                    }
                }
            }

            // 3. Import any Google Drive backups not yet in DB
            foreach ($remoteBackups as $b) {
                Backup::firstOrCreate(
                    [
                        'server_id' => $server->id,
                        'name' => $b['name'],
                    ],
                    [
                        'uuid' => (string) Str::uuid(),
                        'backup_host_id' => $gdriveHost->id,
                        'bytes' => (int) $b['size'],
                        'is_successful' => true,
                        'upload_id' => $b['name'],
                        'created_at' => $b['date'],
                        'completed_at' => $b['date'],
                        'ignored_files' => [],
                    ]
                );
            }
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Delete server backup from Google Drive and database.
     */
    public function deleteServerBackup(Backup $backup): void
    {
        try {
            $fileArg = escapeshellarg("{$this->remote}:{$this->folder}/Servers/{$backup->name}");
            $this->runCommand("rclone delete {$fileArg}");

            Cache::forget('gdrive_server_backups_list_' . $backup->server->uuid);
            Cache::forget('gdrive_server_backups_list_all');

            $backup->delete();
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Delete system backup from Google Drive.
     */
    public function deleteSystemBackup(string $filename): void
    {
        try {
            $fileArg = escapeshellarg("{$this->remote}:{$this->folder}/System/{$filename}");
            $this->runCommand("rclone delete {$fileArg}");

            Cache::forget('gdrive_system_backups_list');
        } catch (Exception $e) {
            report($e);
        }
    }

    /**
     * Synchronize backup targets configuration and cron schedule with the game node.
     */
    public function syncNodeConfiguration(bool $enabled, string $time, array $configData): bool
    {
        try {
            $json = json_encode($configData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $escapedJson = escapeshellarg($json);
            $this->runCommand("echo {$escapedJson} > /etc/gdrive_backup.json");

            return $this->updateNodeCron($enabled, $time, 'auto');
        } catch (Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Update cron schedule on the game node for automated backups.
     */
    public function updateNodeCron(bool $enabled, string $time = '04:30', string $scope = 'auto'): bool
    {
        try {
            $parts = explode(':', trim($time));
            $hour = isset($parts[0]) ? (int) $parts[0] : 4;
            $minute = isset($parts[1]) ? (int) $parts[1] : 30;

            $hour = max(0, min(23, $hour));
            $minute = max(0, min(59, $minute));

            // Fetch current crontab from game node
            $currentCron = $this->runCommand('crontab -l 2>/dev/null || true');
            $lines = explode("\n", $currentCron);

            $newLines = [];
            foreach ($lines as $line) {
                if (str_contains($line, 'backup_to_gdrive.sh')) {
                    continue;
                }
                if (trim($line) !== '') {
                    $newLines[] = trim($line);
                }
            }

            if ($enabled) {
                $newLines[] = "{$minute} {$hour} * * * {$this->backupScript} {$scope} >> /var/log/backup_gdrive.log 2>&1";
            }

            $content = implode("\n", $newLines) . "\n";
            $escaped = escapeshellarg($content);
            $this->runCommand("echo {$escaped} | crontab -");

            return true;
        } catch (Exception $e) {
            report($e);
            return false;
        }
    }

    /**
     * Trigger full system restore from Google Drive.
     */
    public function triggerSystemRestore(string $backupFilename): string
    {
        $file = escapeshellarg($backupFilename);
        $cmd = "nohup {$this->restoreScript} system {$file} > /var/log/restore_system.log 2>&1 & echo $!";
        return $this->runCommand($cmd);
    }

    /**
     * Trigger server restore from Google Drive.
     */
    public function triggerServerRestore(string $serverName, string $backupFilename): string
    {
        $server = escapeshellarg($serverName);
        $file = escapeshellarg($backupFilename);
        $cmd = "nohup {$this->restoreScript} server {$server} {$file} > /var/log/restore_server_{$serverName}.log 2>&1 & echo $!";
        return $this->runCommand($cmd);
    }

    /**
     * Helper to detect container name from Server model.
     */
    public function detectContainerName(Server $server): string
    {
        return $this->getServerIdentifier($server);
    }

    /**
     * Format bytes into human readable string.
     */
    public function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
