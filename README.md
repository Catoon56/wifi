# Air Link WiFi - Production Wi-Fi Voucher Management System

**Air Link WiFi** is a production-ready, mobile-first Wi-Fi voucher management system engineered for high-reliability public hotspot operations in Tanzania.

- **Brand**: Air Link
- **Tagline**: Reliable Internet. Simple Access.
- **ISP**: TTCL Internet (Tanzania Telecommunications Corporation)
- **Access Point**: TP-Link EAP110 Outdoor
- **Captive Portal**: TP-Link Omada External Web Portal
- **Controller**: TP-Link Omada Software Controller (v5.x / v6.x)
- **Backend**: PHP 8.x + MySQL / MariaDB (PDO Prepared Statements)
- **Payment Gateway**: SonicPesa (Vodacom M-Pesa, Tigo Pesa, Airtel Money, Halopesa)
- **SMS Gateway**: SwalaSMS (Voucher notifications & delivery updates)
- **Timezone**: `Africa/Dar_es_Salaam` (UTC+3)
- **Currency**: Tanzanian Shilling (`TZS` / `TSh`)

---

## Key Features

1. **True Omada External Web Portal Architecture**:
   - Captures real Omada redirection query parameters: `clientMac`, `apMac`, `ssidName`, `radioId`, `target`, `site`, `originUrl`, `t`.
   - Communicates with Omada SDN Controller API (`/api/v2/hotspot/extPortal/auth`) via an isolated integration layer (`OmadaService.php`).
   - Supports Omada v5 (microseconds timing) and v6 (milliseconds timing).
2. **First-Use Timer Engine**:
   - Vouchers **do not** begin counting down upon generation.
   - The timer activates **only** when a customer first connects (`activated_at = NOW()`).
   - Disconnections preserve remaining session time. Expiration is calculated from initial activation.
   - Device lock prevents multi-device voucher hijacking when a package restricts `max_devices = 1`.
3. **Automated Online Digital Delivery (Zero Manual Printing for Buyers)**:
   - When a customer buys online via SonicPesa (M-Pesa, Tigo Pesa, Airtel Money), the system verifies the transaction backend-to-backend.
   - The backend automatically generates the voucher in MySQL, sends SMS credentials via SwalaSMS, and immediately presents the voucher code on the customer's browser screen with 1-click "Copy Code" and instant internet authorization.
4. **Administrative Console**:
   - KPI Dashboard: Total vouchers, unused, active, expired, today's sales, today's activations, active customers, total revenue.
   - Single & batch voucher generator with secure random codes (avoiding ambiguous characters like `0/O`, `1/I/L`).
   - Printable voucher templates: Thermal receipt printers (58mm/80mm) and A4 grid sheets.
   - Full package management (prices, durations, download/upload speed limits, device limits).
   - Sales ledger with Cash / SonicPesa breakdown and CSV export.
   - Active Wi-Fi sessions table with instant "Terminate Session" client kick.
   - Diagnostic tools for testing Omada Controller, SonicPesa API, and SwalaSMS connectivity.
5. **Production Hardened Security**:
   - 100% PDO prepared statements (zero SQL concatenation).
   - Passwords hashed using `PASSWORD_BCRYPT`.
   - Anti-CSRF cryptographic token validation on all state-changing forms.
   - Rate limiting on voucher entry and admin login to block brute-force dictionary attacks.
   - Session fixation protection (`session_regenerate_id`) and inactivity timeouts.
   - Audit logging to database and file system.

---

## Directory Structure

```
airlink/
├── admin/                     # Administrative Control Suite
│   ├── includes/              # Header, footer, auth checks
│   ├── index.php              # Dashboard KPIs & stats
│   ├── login.php              # Secure login with lockout
│   ├── logout.php             # Session destruction
│   ├── vouchers.php           # Voucher inventory, search, CSV export
│   ├── voucher-create.php     # Single & bulk generation
│   ├── voucher-print.php      # Thermal receipt & A4 print layouts
│   ├── packages.php           # Wi-Fi plans & speed limits
│   ├── sales.php              # Sales ledger & CSV export
│   ├── customers.php          # Device directory
│   ├── sessions.php           # Live sessions & kick buttons
│   ├── settings.php           # System & integration settings
│   ├── reports.php            # Revenue analytics & reports
│   ├── sonicpesa_test.php     # Diagnostic SonicPesa tester
│   ├── swalasms_test.php      # Diagnostic SwalaSMS tester
│   └── test-omada.php         # Diagnostic controller tester
├── api/                       # RESTful Endpoints
│   ├── voucher/               # validate.php, activate.php
│   ├── payment/               # create.php, sonicpesa_callback.php
│   ├── sms/                   # webhook.php (SwalaSMS delivery callbacks)
│   ├── omada/                 # authorize.php
│   └── session/               # status.php (heartbeat), terminate.php
├── assets/                    # Static Assets
│   ├── css/                   # portal.css, admin.css, print.css
│   ├── js/                    # portal.js, admin.js
│   └── images/                # logo.svg
├── config/                    # Configuration
│   ├── config.php             # Environment loader & constants
│   └── database.php           # PDO singleton
├── database/                  # DDL & Setup Scripts
│   ├── schema.sql             # MySQL schema with FKs and indexes
│   ├── seed.sql               # Default packages, settings & admin
│   └── setup.php              # Automated installer/migrator
├── services/                  # Business Logic Layer
│   ├── OmadaService.php       # TP-Link Omada External Portal API
│   ├── SonicPesaService.php   # SonicPesa payment client
│   ├── SwalaSmsService.php    # SwalaSMS client & webhook handler
│   ├── VoucherService.php     # Lifecycle, codes & timer engine
│   ├── SessionService.php     # Client session tracker & kicker
│   └── LoggerService.php      # Dual database & file audit logger
├── includes/                  # Core Utilities
│   ├── Auth.php               # Login & lockout security
│   ├── CSRF.php               # Anti-CSRF token guard
│   ├── RateLimiter.php        # IP & MAC brute-force protector
│   └── ViewHelper.php         # XSS escaper, formatters
├── cron/                      # Background Automation
│   └── cron-worker.php        # Expiration scanner & log purger
├── public/                    # Customer-Facing Portal
│   ├── index.php              # Captive portal login
│   ├── status.php             # Live status & countdown
│   ├── buy.php                # Package checkout
│   ├── payment-success.php    # Automatic digital voucher delivery
│   └── error.php              # Error screen
├── logs/                      # Private log files
├── .env.example               # Environment template
├── index.php                  # Root entrypoint router
└── status.php                 # Root shortcut to status page
```

---

## Installation & Deployment Guide for Ubuntu VPS

### Step 1: Update Server & Install Prerequisites

```bash
sudo apt update && sudo apt upgrade -y

# Install Nginx (or Apache), PHP 8.2/8.3, and MySQL/MariaDB
sudo apt install -y nginx mariadb-server \
    php8.2-fpm php8.2-mysql php8.2-curl php8.2-mbstring \
    php8.2-xml php8.2-zip git curl certbot python3-certbot-nginx
```

### Step 2: Configure MySQL Database

```bash
sudo mysql_secure_installation

# Log into MySQL
sudo mysql -u root -p
```

Execute SQL to create the database and user:

```sql
CREATE DATABASE airlink_wifi CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'airlink_user'@'localhost' IDENTIFIED BY 'StrongPasswordHere!2026';
GRANT ALL PRIVILEGES ON airlink_wifi.* TO 'airlink_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

### Step 3: Deploy Application Files

Clone or copy the application into your web root (e.g. `/var/www/html/airlink`):

```bash
sudo mkdir -p /var/www/html/airlink
# Copy repository contents into /var/www/html/airlink

# Set correct ownership and permissions
sudo chown -R www-data:www-data /var/www/html/airlink
sudo chmod -R 755 /var/www/html/airlink
sudo chmod -R 775 /var/www/html/airlink/airlink/logs
```

### Step 4: Configure Environment (`.env`)

```bash
cd /var/www/html/airlink
cp .env.example .env
nano .env
```

Update your `.env` values:
```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://portal.airlinkwifi.co.tz

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=airlink_wifi
DB_USER=airlink_user
DB_PASSWORD=StrongPasswordHere!2026

OMADA_CONTROLLER_URL=https://YOUR_CONTROLLER_IP:8043
OMADA_SITE=default
OMADA_OPERATOR_USER=airlink_operator
OMADA_OPERATOR_PASS=OperatorPassword123
OMADA_VERSION=v5
OMADA_SIMULATION_MODE=0

SONICPESA_ENABLED=true
SONICPESA_API_KEY=YOUR_SONICPESA_API_KEY
SONICPESA_BASE_URL=https://api.sonicpesa.com/api/v1
SONICPESA_WEBHOOK_SECRET=YOUR_SONICPESA_WEBHOOK_SECRET

SWALASMS_API_KEY=swl_live_YOUR_KEY_HERE
SWALASMS_SENDER_ID=AIRLINK
SWALASMS_BASE_URL=https://swalasms.com/api/v1
SWALASMS_WEBHOOK_SECRET=YOUR_SWALA_WEBHOOK_SECRET
```

### Step 5: Initialize Database Schema

Run the automated installer via CLI:

```bash
php /var/www/html/airlink/airlink/database/setup.php
```

Or open `http://YOUR_SERVER_IP/airlink/database/setup.php` in your browser.

> **Default Admin Account**:
> - **Username**: `admin`
> - **Password**: `admin123`
> *(Please change your password immediately after your first login via the admin settings).*

### Step 6: Configure Nginx Virtual Host & SSL

Create an Nginx configuration file:

```bash
nano /etc/nginx/sites-available/airlink
```

Add the following production configuration:

```nginx
server {
    listen 80;
    server_name portal.airlinkwifi.co.tz;
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name portal.airlinkwifi.co.tz;

    root /var/www/html/airlink;
    index public/index.php;

    ssl_certificate /etc/letsencrypt/live/portal.airlinkwifi.co.tz/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/portal.airlinkwifi.co.tz/privkey.pem;

    # Block sensitive directories
    location ~ ^/(config|database|logs|services|includes|cron)/ {
        deny all;
        return 404;
    }

    # Hide dotfiles
    location ~ /\. {
        deny all;
    }

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

Enable the virtual host:

```bash
ln -s /etc/nginx/sites-available/airlink /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
```

---

## Omada Controller External Portal Setup

1. Log into your **Omada Software Controller** (`https://YOUR_CONTROLLER_IP:8043`).
2. Navigate to: **Settings &rarr; Wireless Networks &rarr; Hotspot Manager &rarr; Portal**.
3. Create an External Web Portal:
   - Click **Add Portal**.
   - **Portal Name**: `Air Link External Portal`
   - **Applies to SSID**: Select your broadcast SSID (`Air Link WiFi` on EAP110 Outdoor).
   - **Authentication Type**: Select **External Web Portal**.
   - **External Web Portal URL**:
     `https://portal.airlinkwifi.co.tz/airlink/public/index.php`
     *(Omada will automatically append `clientMac`, `apMac`, `ssidName`, `radioId`, `target`, `site`, `originUrl`, and `t` to the query string).*
4. **Pre-Authentication Access List (Walled Garden)**:
   - In **Portal Settings** under **Pre-Authentication Access**, add:
     - Your Portal VPS IP or hostname (`portal.airlinkwifi.co.tz`)
     - SonicPesa payment domains (to allow mobile payment before internet authorization):
       - `api.sonicpesa.com`
       - `pay.sonicpesa.com`
       - `*.sonicpesa.com`
5. **Save & Apply**.

---

## SonicPesa Payment Gateway Configuration

1. Log into your merchant account at [SonicPesa](https://sonicpesa.com).
2. Under API Settings, copy your **API Key** (`X-API-KEY`).
3. Set your Webhook / Callback URL to:
   - Webhook URL: `https://portal.airlinkwifi.co.tz/airlink/api/payment/sonicpesa_callback.php`
4. In the Air Link Admin Panel (**Settings &rarr; SonicPesa Payment Gateway**), configure:
   - Enable SonicPesa: Checked
   - Base URL: `https://api.sonicpesa.com/api/v1`
   - API Key: Your SonicPesa API Key
   - Webhook Secret (if using signature validation)
   - Click **Test Connection** to verify live communication with the SonicPesa API.

---

## SwalaSMS Configuration

1. Log into your dashboard at [SwalaSMS](https://swalasms.com).
2. Generate your API Key (`swl_live_...` or `swl_test_...`) and submit your **Sender ID** for approval (e.g., `AIRLINK`).
3. Set your Delivery Webhook URL in SwalaSMS to:
   - Delivery Callback URL: `https://portal.airlinkwifi.co.tz/airlink/api/sms/webhook.php`
4. In the Air Link Admin Panel (**Settings &rarr; SwalaSMS Settings**), enter:
   - API Key (Bearer token)
   - Sender ID
   - Base URL: `https://swalasms.com/api/v1`
   - Webhook HMAC Secret
   - Click **Test Connection** to verify balance and connectivity.

---

## Maintenance & Diagnostic Commands

- **Test Omada Controller Connection**:
  In the Admin Panel, visit **Settings** and click **Test Omada Connection**, or run:
  ```bash
  php -r "require 'airlink/services/OmadaService.php'; var_dump((new OmadaService())->testConnection());"
  ```
- **Inspect Application Logs**:
  ```bash
  tail -f /var/www/html/airlink/airlink/logs/app.log
  tail -f /var/www/html/airlink/airlink/logs/omada.log
  tail -f /var/www/html/airlink/airlink/logs/payment.log
  ```
- **Manual Voucher Sweep & Expiration**:
  ```bash
  php /var/www/html/airlink/airlink/cron/cron-worker.php
  ```

---

*Air Link WiFi &bull; Reliable Internet. Simple Access.*
