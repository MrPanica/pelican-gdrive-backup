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
        $this->host = (string) config('gdrive-backup.node_host', '127.0.0.1');
        $this->port = (int) config('gdrive-backup.node_port', 22);
        $this->user = (string) config('gdrive-backup.node_user', 'root');
        $this->keyPath = (string) (config('gdrive-backup.node_key_path') ?: '/var/www/.ssh/id_ed25519');
        $this->remote = (string) config('gdrive-backup.remote', 'gdrive');
        $this->folder = (string) config('gdrive-backup.folder', 'TF2_Backups');
        $this->backupScript = (string) config('gdrive-backup.backup_script', '/usr/local/bin/backup_to_gdrive.sh');
        $this->restoreScript = (string) config('gdrive-backup.restore_script', '/usr/local/bin/restore_from_gdrive.sh');
    }

    /**
     * Execute remote command on game node via SSH key.
     */
    public function runCommand(string $command): string
    {
        $escaped = escapeshellarg($command);
        $keyOpt = (!empty($this->keyPath) && file_exists($this->keyPath)) ? "-i " . escapeshellarg($this->keyPath) : "";
        $cmd = "ssh -o BatchMode=yes -o StrictHostKeyChecking=no {$keyOpt} -p {$this->port} {$this->user}@{$this->host} {$escaped} 2>&1";
        
        $output = shell_exec($cmd);

        return trim((string) $output);
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

                // Matches server_<IDENTIFIER>_<DATE>.tar.zst
                // Example: server_JAIL-TWO-5c213163_2026-09-04_22-34.tar.zst
                // Legacy example: server_DUSTBOWL_2026-09-04_22-27.tar.zst
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
            'completed_at' => null, // null completed_at = BackupStatus::InProgress
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
     * Updates in-progress backups to successful/failed, heals falsely-failed entries,
     * and imports completed ones.
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
                    // File is present on Google Drive!
                    $rb = $remoteMap[$b->name];
                    // If size was 0, or marked unsuccessful, heal it immediately:
                    if (!$b->is_successful || $b->bytes == 0 || $b->completed_at === null) {
                        $b->update([
                            'bytes' => (int) $rb['size'],
                            'is_successful' => true,
                            'completed_at' => $rb['date'],
                        ]);
                    }
                } elseif ($b->completed_at === null) {
                    // Backup is currently marked in-progress
                    // Only mark as failed if it's NOT active on node AND at least 15 minutes elapsed
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
                // Filter out existing backup_to_gdrive.sh jobs
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
