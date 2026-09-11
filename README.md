# ☁️ Pelican Google Drive Backup

**English** | [Русский](#-на-русском)

A high-performance streamed cloud backup and disaster recovery plugin for **Pelican Panel**, primarily designed and optimized for dedicated game servers on **Source Engine** (*Team Fortress 2, Counter-Strike, Garry's Mod, Half-Life 2, Left 4 Dead 2*) with large map pools, demo recordings, and add-ons.

---

## ✨ Key Features (English)

- **🚀 Zero-Disk-Footprint Direct Streaming**:
  - Streams server snapshots directly to Google Drive via pipe: `tar -> zstd -> rclone rcat`.
  - No temporary archive files on the game node disk, preventing out-of-disk crashes during large backup operations.
- **🔒 Multi-Threaded ZSTD High-Ratio Compression**:
  - Uses `zstd -3 -T2` for high-throughput compression and minimal game server CPU latency.
- **🧪 End-to-End Diagnostic Self-Testing Engine**:
  - Built-in comprehensive 9-stage self-test: SSH link, utility checks, dummy payload generation, compression, upload to Google Drive, download back, decompression, and SHA-256 cryptographic integrity matching.
  - Available right inside the Pelican Panel UI (Plugin Settings, Server Backups, Backup Hosts, System Backups) and via CLI (`backup_to_gdrive.sh test`).
- **🛡️ Full System & Game Server Disaster Recovery**:
  - **Server Backups**: 1-click snapshot creation and full recovery right from the Pelican server backup dashboard (`/server/{uuid}/backups`).
  - **Bare-Metal Node Recovery**: Admin UI modal to restore full system configurations (`/etc`, systemd units, panel/node configs) with mandatory uppercase confirmation.
- **📅 Automated Scheduled Backups (Cron)**:
  - Daily automatic backup jobs configured through the Filament Admin UI with retention policies (default: 14 days) and auto-pruning.
- **🔄 Live Progress & Self-Healing Sync**:
  - Real-time status detection (`InProgress`, `Completed`, `Failed`) with automatic database healing when background transfers finish.

---

## 🛠️ Quick Installation

### 1. Game Node Requirements
On the target game node, install the required utilities:
```bash
apt update && apt install -y rclone zstd tar jq coreutils
```

### 2. Install Plugin in Pelican Panel
```bash
cd /var/www/pelican/plugins
git clone https://github.com/MrPanica/pelican-gdrive-backup.git

# Set permissions and clear caches
chown -R www-data:www-data /var/www/pelican/plugins/pelican-gdrive-backup
cd /var/www/pelican
php artisan optimize:clear
php artisan filament:optimize-clear
```

---

## 🔑 Google Cloud OAuth 2.0 Configuration (2026 UI)

To prevent Google from revoking the refresh token every 7 days, your OAuth application must be published to **In production** status:

1. Open [Google Cloud Console (Google Auth Platform)](https://console.cloud.google.com/auth).
2. Create an OAuth 2.0 Client ID (**Application type: Desktop app**).
3. Navigate to **Branding**:
   - **App name**: e.g., `PGZ Storage` or `Server Backup` (*Do not use trademarked names like "Rclone"*).
   - **User support email**: Select your Google account email.
   - **Application home page**: `https://your-domain.com`
   - **Application privacy policy link**: `https://your-domain.com/privacy`
   - **Authorized domains**: Click **+ Add domain** and enter your domain (e.g. `your-domain.com`).
   - **Developer contact information**: Enter your email address.
   - Click **Save** at the bottom.
4. Navigate to **Audience**:
   - Under **Publishing status**, click **«Publish app»** and confirm. The status will change from *Testing* to **In production** (permanent token).

### Generating Token via Rclone
On your local computer with `rclone` installed:
```bash
rclone authorize "drive" "<YOUR_CLIENT_ID>" "<YOUR_CLIENT_SECRET>"
```
Log in via the browser, click **Advanced ➔ Continue**, and copy the resulting JSON string:
```json
{"access_token":"ya29...","token_type":"Bearer","refresh_token":"1//...","expiry":"..."}
```
Paste this JSON string into the plugin settings in Pelican Panel (**Admin ➔ Plugins ➔ Google Drive Backup ➔ Settings**) in the *«New Google Drive OAuth Token»* field and click **Save**.

---

## 🧪 Running the Diagnostic Test

### Via Web UI
Navigate to **Admin ➔ Plugins ➔ Google Drive Backup ➔ Settings** (or to any server's **Backups** tab) and click **«Run Google Drive Test»**.

### Via Terminal (Node CLI)
```bash
/usr/local/bin/backup_to_gdrive.sh test
```

---

# 🇷🇺 На русском

Высокопроизводительный плагин потокового облачного резервного копирования и аварийного восстановления через Google Диск для **Pelican Panel**, специально оптимизированный для игровых серверов на движке **Source Engine** (*Team Fortress 2, Counter-Strike: Source, CS:GO, Garry's Mod, Half-Life 2: Deathmatch, Left 4 Dead 2* и др.) с большими наборами карт, записями демок и модами.

---

## 🚀 Основные возможности

1. **Прямое потоковое копирование без использования локального диска**:
   - Передача снимка сервера напрямую в Google Диск через конвейер: `tar -> zstd -> rclone rcat`.
   - Локальный диск ноды не заполняется временными архивами, что исключает сбои игровых серверов из-за нехватки дискового пространства во время бэкапа.
2. **Многопоточное сжатие ZSTD**:
   - Использование многопоточного алгоритма `zstd -3 -T2` с минимальной нагрузкой на процессор во время активной игры на сервере.
3. **Сквозная система самодиагностики и тестирования (9 этапов)**:
   - Проверка SSH-связи панели с игровой нодой;
   - Проверка системных утилит (`tar`, `zstd`, `sha256sum`, `rclone`, `jq`);
   - Генерация тестового файла со случайными данными (64 KB);
   - Сжатие в `.tar.zst` с измерением времени и степени сжатия;
   - Загрузка тестового архива на Google Диск;
   - Скачивание архива обратно на игровую ноду;
   - Разархивация и распаковка архива;
   - Побитовая сверка контрольной суммы **SHA-256** до архивации и после распаковки (100% гарантия целостности данных);
   - Автоматическая очистка тестовых файлов из облака и с диска.
4. **Удобное управление токеном Google Drive прямо из панели**:
   - Вставка нового JSON-токена в форму настроек плагина с автоматической перезаписью `rclone.conf` на сервере ноды по SSH.
5. **Резервное копирование серверов и всей системы**:
   - Снимки серверов в 1 клик прямо из вкладки бэкапов сервера (`/server/{uuid}/backups`).
   - Снимки всей операционной системы ноды (`/etc`, конфигурации, сервисы) в разделе админки `Бэкапы всей системы`.
6. **Автоматическое резервное копирование по расписанию (Cron)**:
   - Ежедневное резервное копирование серверов и системы с автоматической ротацией устаревших архивов (по умолчанию 14 дней).

---

## ⚙️ Пошаговая настройка Google Cloud (Интерфейс 2026)

Чтобы Google **не отзывал токен каждые 7 дней**, приложение должно быть переведено в статус **In production**:

1. Откройте [Google Cloud Console (Google Auth Platform)](https://console.cloud.google.com/auth).
2. Создайте учетные данные OAuth 2.0 (**Тип приложения: Desktop app / Приложение для ПК**).
3. Перейдите в раздел **Branding**:
   - **App name**: укажите любое название, например `PGZ Storage` (*слово "Rclone" использовать нельзя*).
   - **User support email**: выберите ваш адрес Gmail.
   - **Application home page**: `https://ваш-домен.ru`
   - **Application privacy policy link**: `https://ваш-домен.ru/help/privacy-policy/`
   - **Authorized domains**: нажмите **+ Add domain** и введите ваш домен (например, `ваш-домен.ru`).
   - **Developer contact information**: укажите ваш email.
   - Нажмите **Save** внизу страницы.
4. Перейдите в раздел **Audience**:
   - В блоке **Publishing status** нажмите кнопку **«Publish app»** (Опубликовать) и подтвердите действие (**Confirm**).
   - Статус изменится с *Testing* на **In production** (токен становится бессрочным).

### Получение токена через Rclone
На компьютере с установленной утилитой `rclone` выполните:
```bash
rclone authorize "drive" "<ВАШ_CLIENT_ID>" "<ВАШ_CLIENT_SECRET>"
```
В браузере откроется страница Google:
- Выберите аккаунт.
- При появлении предупреждения нажмите **Advanced (Дополнительно)** ➔ **Go to PGZ Storage (unsafe)**.
- Нажмите **Continue (Продолжить / Разрешить)**.
- В консоли скопируйте строку:
```json
{"access_token":"ya29...","token_type":"Bearer","refresh_token":"1//...","expiry":"..."}
```
Вставьте эту строку в настройках плагина в Pelican Panel (**Админка ➔ Плагины ➔ Google Drive Backup ➔ Настройки**) в поле *«Новый OAuth токен Google Drive»* и нажмите **Сохранить**.

---

## 🧪 Запуск диагностики

### Из веб-интерфейса Pelican Panel
Откройте **Админка ➔ Плагины ➔ Google Drive Backup ➔ Настройки** (или перейдите в **Резервные копии** любого сервера) и нажмите кнопку **«Тест Google Диска»**.

### Из консоли игровой ноды (CLI)
```bash
/usr/local/bin/backup_to_gdrive.sh test
```

Ручной запуск автобэкапа серверов:
```bash
/usr/local/bin/backup_to_gdrive.sh auto
```

Проверка статуса текущего бэкапа:
```bash
/usr/local/bin/backup_to_gdrive.sh status
```

---

## 📄 Лицензия

MIT License. Разработано для игровых сообществ Source Engine на Pelican Panel.
