<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Google Drive Remote Configuration
    |--------------------------------------------------------------------------
    |
    | The name of the rclone remote configured on the system and the base
    | folder where backups are stored.
    |
    */
    'remote' => env('GDRIVE_BACKUP_REMOTE', 'gdrive'),
    'folder' => env('GDRIVE_BACKUP_FOLDER', 'TF2_Backups'),
    'retention_days' => (int) env('GDRIVE_BACKUP_RETENTION', 14),

    /*
    |--------------------------------------------------------------------------
    | Automated Backup Schedule Configuration
    |--------------------------------------------------------------------------
    */
    'auto_backup_enabled' => (bool) env('GDRIVE_AUTO_BACKUP_ENABLED', true),
    'auto_backup_time' => env('GDRIVE_AUTO_BACKUP_TIME', '04:30'),
    'auto_backup_scope' => env('GDRIVE_AUTO_BACKUP_SCOPE', 'all'),
    'auto_backup_system' => (bool) env('GDRIVE_AUTO_BACKUP_SYSTEM', true),
    'auto_backup_servers' => [],

    /*
    |--------------------------------------------------------------------------
    | Target Gaming Node Connection
    |--------------------------------------------------------------------------
    */
    'node_host' => env('GDRIVE_BACKUP_NODE_HOST', '127.0.0.1'),
    'node_port' => (int) env('GDRIVE_BACKUP_NODE_PORT', 22),
    'node_user' => env('GDRIVE_BACKUP_NODE_USER', 'root'),
    'node_key_path' => env('GDRIVE_BACKUP_NODE_KEY', '/var/www/.ssh/id_ed25519'),

    /*
    |--------------------------------------------------------------------------
    | Script Paths on Target Node
    |--------------------------------------------------------------------------
    */
    'backup_script' => env('GDRIVE_BACKUP_SCRIPT', '/usr/local/bin/backup_to_gdrive.sh'),
    'restore_script' => env('GDRIVE_RESTORE_SCRIPT', '/usr/local/bin/restore_from_gdrive.sh'),
];
