# ☁️ Pelican Google Drive Backup

**English** | [Русский](#-русский)

A high-performance streamed cloud backup and disaster recovery plugin for **Pelican Panel**, primarily designed and optimized for **Source Engine** dedicated game servers (*Team Fortress 2, Counter-Strike: Source, CS:GO, Garry's Mod, Half-Life 2: Deathmatch, Left 4 Dead 2*, etc.) with massive map pools, demo files, and add-ons.

---

## ✨ Features (English)

- **🚀 Zero-Disk-Footprint Direct Streaming**:
  - Streams backups directly to Google Drive via pipe: `tar -> zstd -> rclone rcat`.
  - No temporary archives are saved on the local node disk, preventing out-of-disk crashes during backup of heavy game servers (TF2/CS maps, demos, workshop content).
- **🔒 Multi-Core ZSTD High-Ratio Compression**:
  - Leverages multi-threaded `zstd -T0` compression for lightning-fast archive generation and minimal CPU overhead.
- **🛡️ Full System & Game Server Restore**:
  - **Individual Server Backup & Restore**: 1-click snapshot creation and full recovery right from the Pelican server backup dashboard (`/server/{uuid}/backups`).
  - **Bare-Metal Node Recovery**: Admin UI modal to restore full system configurations (`/etc`, systemd units, panel/node configs) with mandatory uppercase confirmation.
- **📅 Automated Scheduled Backups (Cron)**:
  - Daily automatic backup jobs configured through the Filament Admin UI with retention policies (default: 14 days) and auto-pruning.
- **🔄 Live Progress & Self-Healing Sync**:
  - Real-time status detection (`InProgress`, `Completed`, `Failed`) with automatic database healing when background transfers finish.

---

## 🇷🇺 Описание (Русский)

**Pelican Google Drive Backup** — высокопроизводительный плагин потокового резервного копирования и аварийного восстановления через Google Диск для панели **Pelican Panel**, специально спроектированный для игровых серверов на движке **Source Engine** (*Team Fortress 2, Counter-Strike: Source, CS:GO, Garry's Mod, HL2:DM, L4D2* и др.) с большими наборами карт, записями демок и контентом мастерской.

### Основные возможности

- **🚀 Прямое потоковое копирование без использования локального диска**:
  - Передача архива напрямую в облако Google Drive через конвейер `tar -> zstd -> rclone rcat`.
  - Локальный диск игровой ноды не забивается временными файлами, исключая падение серверов из-за нехватки места при бэкапе больших игровых серверов (карты, демки, моды).
- **🔒 Многопоточное сжатие ZSTD**:
  - Использование многопоточного алгоритма `zstd -T0` для максимальной скорости сжатия и экономии дискового пространства облака.
- **🛡️ Резервное копирование серверов и всей системы**:
  - **Серверные бэкапы**: создание и восстановление снимка любого сервера в 1 клик прямо из вкладки бэкапов (`/server/{uuid}/backups`).
  - **Снимки всей системы VDS**: создание и восстановление системных конфигураций ноды (`/etc`, демоны, сервисы) с защитным подтверждением `RESTORE`.
- **📅 Автоматические бэкапы по расписанию**:
  - Ежедневный запуск по расписанию (cron) с автоматической очисткой устаревших архивов согласно политике хранения (по умолчанию 14 дней).
- **🔄 Живой статус и самовосстановление**:
  - Отображение статуса в реальном времени (`InProgress`, `Completed`) с автоматической синхронизацией и устранением ложных сбоев.

---

## 🚀 Installation / Установка

```bash
# Clone into Pelican plugins directory
cd /var/www/pelican/plugins
git clone https://github.com/MrPanica/pelican-gdrive-backup.git

# Clear cache and optimize
cd /var/www/pelican
php artisan optimize:clear
php artisan filament:optimize
```

### Requirements / Требования
- `rclone` configured on the target node with Google Drive remote (default: `gdrive`).
- `zstd` and `tar` installed on the target node.

---

## 📄 License

MIT License. Developed for gaming communities running Source engine servers on Pelican Panel.
