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
- **🧪 Real-Time 9-Stage Diagnostic Self-Testing Engine**:
  - Built-in interactive 9-stage self-test streamed in real time via Server-Sent Events (SSE):
    1. SSH link between Pelican Panel and game node.
    2. Game node utility checks (`rclone`, `tar`, `zstd`, `sha256sum`, `jq`).
    3. Test payload generation (64 KB random data).
    4. Multi-threaded Zstandard compression to `.tar.zst`.
    5. Cloud upload to Google Drive.
    6. Download back from Google Drive.
    7. Extraction & decompression into an isolated environment.
    8. Strict cryptographic **SHA-256** checksum integrity verification (100% byte-exact guarantee).
    9. Automatic cleanup of test artifacts.
  - Interactive web widget with live spinners, checkmarks, metrics, and instant error diagnostics.
- **🛡️ Full System & Game Server Disaster Recovery**:
  - **Server Backups**: 1-click snapshot creation and full recovery right from the Pelican server backup dashboard (`/server/{uuid}/backups`).
  - **Bare-Metal Node Recovery**: Admin UI modal to restore full system configurations (`/etc`, systemd units, panel/node configs) with mandatory uppercase confirmation.
- **📅 Automated Scheduled Backups (Cron)**:
  - Daily automatic backup jobs configured through the Filament Admin UI with retention policies (default: 14 days) and auto-pruning.
- **🔄 Live Progress & Self-Healing Sync**:
  - Real-time status detection (`InProgress`, `Completed`, `Failed`) with automatic database healing when background transfers finish.

---

## 🛠️ Quick Installation

### 🚀 1-Click Installation via URL (Recommended)
In Pelican Admin Panel ➔ **Plugins** ➔ click the **«Import from URL»** button (globe icon):
```text
https://github.com/MrPanica/pelican-gdrive-backup/archive/refs/heads/master.zip
```
Pelican will automatically download, unpack, and activate the plugin.

---

### Manual Installation via CLI
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

> [!IMPORTANT]
> In Google's **Testing** mode, refresh tokens expire every **7 days** (`invalid_grant: Token has been expired or revoked`). To obtain a **permanent token**, your OAuth application must be published to **In production** status as described below.

### Step 1. Enable Google Drive API
1. Open [Google API Library](https://console.cloud.google.com/apis/library/drive.googleapis.com).
2. Ensure your target project is selected.
3. Click **Enable**.

### Step 2. Configure OAuth Consent Screen (Branding)
1. Navigate to [Google Auth Platform ➔ Branding](https://console.cloud.google.com/auth/branding).
2. Fill in the required fields:
   - **App name**: e.g., `PGZ Storage` or `Server Backup` (*Do NOT use trademarked words like "Google" or "Rclone"*).
   - **User support email**: Select your Google account email.
   - **Application home page**: `https://your-domain.com`
   - **Application privacy policy link**: `https://your-domain.com/privacy`
   - **Authorized domains**: Click **+ Add domain** and enter your domain name (e.g. `your-domain.com`).
   - **Developer contact information**: Enter your email address.
3. Click **Save** at the bottom of the page.

### Step 3. Switch App to Production (Audience)
1. Navigate to [Google Auth Platform ➔ Audience](https://console.cloud.google.com/auth/audience).
2. Under **Publishing status**, click **«Publish app»** and confirm (**Confirm**).
3. The status will change from *Testing* to **In production** (refresh token will never expire after 7 days).

### Step 4. Create Desktop OAuth Client
1. Navigate to [Google Auth Platform ➔ Clients](https://console.cloud.google.com/auth/clients) (or Credentials).
2. Click **Create Client**.
3. Select **Application type: Desktop app** (Приложение для ПК).
4. Enter a name (e.g. `Pelican Rclone Client`) and click **Create**.
5. Copy your **Client ID** and **Client Secret**.

### Step 5. Authorize via Local PC & Obtain Token
On your local computer (Windows, macOS, or Linux) with `rclone` installed:

- **Windows** (PowerShell / CMD):
  ```powershell
  winget install Rclone.Rclone
  rclone authorize "drive" "<YOUR_CLIENT_ID>" "<YOUR_CLIENT_SECRET>"
  ```
- **Linux / macOS**:
  ```bash
  rclone authorize "drive" "<YOUR_CLIENT_ID>" "<YOUR_CLIENT_SECRET>"
  ```

A browser window will automatically open:
1. Choose your Google account.
2. If an unverified app warning appears, click **Advanced (Дополнительно)** ➔ **Go to PGZ Storage (unsafe)**.
3. Click **Continue / Allow** to grant drive permissions.
4. Return to your terminal. Rclone will print a JSON block:
   ```json
   {"access_token":"ya29...","token_type":"Bearer","refresh_token":"1//...","expiry":"..."}
   ```

### Step 6. Save Token in Pelican Panel
1. In Pelican Panel, navigate to **Admin ➔ Plugins ➔ Google Drive Backup ➔ Settings**.
2. Scroll to **«Авторизация Google Drive (Обновление OAuth токена)»**.
3. Paste the entire JSON string into the field and click **Save**.
4. The plugin will automatically update `/root/.config/rclone/rclone.conf` on your remote game node via SSH.

---

## 🧪 Running the Real-Time Diagnostic Test

### Via Web UI
1. Navigate to **Admin ➔ Plugins ➔ Google Drive Backup ➔ Settings** (or click the **«Запустить тест Google Диска»** action button on any server's Backups tab or Backup Hosts table).
2. Click the prominent blue button **«Запустить тест Google Диска»**.
3. Watch each of the 9 stages execute in real time with live loading spinners, checkmarks (`✓`), timing metrics, and SHA-256 hash match confirmation.

### Via Game Node Terminal (CLI)
```bash
/usr/local/bin/backup_to_gdrive.sh test
```

### Manual Backup Commands on Node
- Run automatic backup of all configured servers + system:
  ```bash
  /usr/local/bin/backup_to_gdrive.sh auto
  ```
- Check status of currently running backup:
  ```bash
  /usr/local/bin/backup_to_gdrive.sh status
  ```

---

# 🇷🇺 На русском

Высокопроизводительный плагин потокового облачного резервного копирования и аварийного восстановления через Google Диск для **Pelican Panel**, специально оптимизированный для игровых серверов на движке **Source Engine** (*Team Fortress 2, Counter-Strike: Source, CS:GO, Garry's Mod, Half-Life 2: Deathmatch, Left 4 Dead 2* и др.) с большими наборами карт, записями демок и модами.

---

## 🚀 Основные возможности

1. **Прямое потоковое копирование без расхода локального диска**:
   - Передача снимка сервера напрямую в Google Диск через конвейер: `tar -> zstd -> rclone rcat`.
   - Локальный диск игровой ноды не заполняется временными архивами, что полностью исключает падения игровых серверов из-за переполнения диска во время бэкапа.
2. **Многопоточное сжатие ZSTD**:
   - Алгоритм `zstd -3 -T2` обеспечивает максимальную скорость сжатия при минимальной нагрузке на процессор, не вызывая лагов и просадок FPS на активных игровых серверах.
3. **Интерактивная сквозная диагностика в реальном времени (9 этапов)**:
   - Проверка SSH-связи панели с игровой нодой;
   - Проверка наличия системных утилит (`tar`, `zstd`, `sha256sum`, `rclone`, `jq`);
   - Генерация тестового массива со случайными байтами (64 KB);
   - Сжатие в `.tar.zst` с замером времени и степени компрессии;
   - Выгрузка тестового архива на Google Диск;
   - Скачивание архива обратно на ноду;
   - Декомпрессия zstd и распаковка tar в изолированную директорию;
   - Побитовая сверка контрольной суммы **SHA-256** до и после облака (100% гарантия целостности данных);
   - Очистка временных файлов с локального диска и из корзины Google Диска.
   - Удобный виджет с анимацией выполнения каждого шага в реальном времени (Server-Sent Events), галочками (`✓`) и замером миллисекунд.
4. **Удобное управление токеном Google Drive прямо из панели**:
   - Вставка нового JSON-токена в форму настроек плагина с автоматической записью в `rclone.conf` на сервере ноды по SSH без ручного входа на сервер.
5. **Резервное копирование серверов и всей операционной системы**:
   - Снимки серверов в 1 клик прямо из вкладки бэкапов сервера (`/server/{uuid}/backups`).
   - Снимки всей операционной системы ноды (`/etc`, конфигурации, сервисы) в разделе админки `Бэкапы всей системы`.
6. **Автоматическое резервное копирование по расписанию (Cron)**:
   - Ежедневное резервное копирование серверов и системы с автоматической ротацией устаревших архивов (по умолчанию 14 дней).

---

## ⚙️ Пошаговая настройка Google Cloud (Интерфейс Google Auth Platform 2026)

> [!IMPORTANT]
> В режиме **Testing** Google отзывает refresh-токен ровно через **7 дней** (`invalid_grant: Token has been expired or revoked`). Чтобы токен стал **бессрочным**, обязательно переведите приложение в статус **In production** по инструкции ниже.

### Шаг 1. Включение Google Drive API
1. Откройте [Google API Library — Google Drive API](https://console.cloud.google.com/apis/library/drive.googleapis.com).
2. Выберите ваш проект в верхней панели.
3. Нажмите кнопку **Enable (Включить)**.

### Шаг 2. Настройка экрана согласия (Branding)
1. Перейдите в [Google Auth Platform ➔ Branding](https://console.cloud.google.com/auth/branding).
2. Заполните обязательные поля:
   - **App name**: понятное название приложения, например `PGZ Storage` или `Server Backup` (*слова «Google» и «Rclone» использовать запрещено*).
   - **User support email**: выберите ваш email адрес Gmail.
   - **Application home page**: URL вашего сайта (например, `https://ваш-домен.ru`).
   - **Application privacy policy link**: ссылка на политику конфиденциальности (например, `https://ваш-домен.ru/help/privacy-policy/`).
   - **Authorized domains**: нажмите **+ Add domain** и введите ваш домен (например, `ваш-домен.ru`).
   - **Developer contact information**: укажите ваш email адрес.
3. Нажмите кнопку **Save** внизу страницы.

### Шаг 3. Публикация приложения в Production (Audience)
1. Перейдите в раздел [Google Auth Platform ➔ Audience](https://console.cloud.google.com/auth/audience).
2. В блоке **Publishing status** нажмите кнопку **«Publish app» (Опубликовать приложение)**.
3. Подтвердите действие (**Confirm**).
4. Статус изменится с *Testing* на **In production** — теперь токены авторизации не будут сгорать через 7 дней.

### Шаг 4. Создание учетных данных OAuth (Clients)
1. Перейдите в раздел [Google Auth Platform ➔ Clients](https://console.cloud.google.com/auth/clients) (или Credentials).
2. Нажмите **Create Client**.
3. Выберите **Application type: Desktop app (Приложение для ПК)**.
4. Укажите название (например, `Pelican Backup Client`) и нажмите **Create**.
5. Скопируйте **Client ID** и **Client Secret**.

### Шаг 5. Получение токена через Rclone на локальном ПК
На своем домашнем или рабочем компьютере с браузером:

- **Windows** (установка через PowerShell):
  ```powershell
  winget install Rclone.Rclone
  rclone authorize "drive" "<ВАШ_CLIENT_ID>" "<ВАШ_CLIENT_SECRET>"
  ```
- **Linux / macOS**:
  ```bash
  rclone authorize "drive" "<ВАШ_CLIENT_ID>" "<ВАШ_CLIENT_SECRET>"
  ```

В открывшемся браузере:
1. Выберите ваш аккаунт Google.
2. При появлении предупреждения Google нажмите **Дополнительно (Advanced)** ➔ **Перейти на страницу PGZ Storage (небезопасно)**.
3. Нажмите **Продолжить (Allow / Разрешить)**.
4. Вернитесь в консоль — утилита rclone выведет JSON-строку:
   ```json
   {"access_token":"ya29...","token_type":"Bearer","refresh_token":"1//...","expiry":"..."}
   ```

### Шаг 6. Вставка токена в Pelican Panel
1. В панели управления откройте **Админка ➔ Плагины ➔ Google Drive Backup ➔ Настройки**.
2. В секции **«Авторизация Google Drive (Обновление OAuth токена)»** вставьте скопированную JSON-строку в поле **«Новый OAuth токен Google Drive»**.
3. Нажмите кнопку **Сохранить** внизу страницы.
4. Токен будет автоматически записан в конфигурационный файл `rclone.conf` на игровой ноде.

---

## 🧪 Запуск диагностики и тестирования

### Из веб-интерфейса Pelican Panel
1. Откройте **Админка ➔ Плагины ➔ Google Drive Backup ➔ Настройки** (или перейдите в **Резервные копии** любого сервера).
2. Нажмите синюю кнопку **«Запустить тест Google Диска»**.
3. В реальном времени отобразится прохождение всех 9 этапов с индикаторами выполнения, зелеными галочками (`✓`), замерами скорости и сверкой целостности SHA-256.

### Из консоли игровой ноды (CLI)
```bash
/usr/local/bin/backup_to_gdrive.sh test
```

Ручной запуск ежедневного автобэкапа всех серверов:
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
