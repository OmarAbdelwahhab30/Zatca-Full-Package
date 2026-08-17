# ZATCA Package — Production & Server Requirements Guide

This document specifies all system, server, software, and configuration requirements needed to run the `zatca/zatca` Laravel package reliably in production or staging environments.

---

## 📋 Table of Contents
1. [System & OS Requirements](#1-system--os-requirements)
2. [Java Runtime Environment (JRE/JDK)](#2-java-runtime-environment-jrejdk)
3. [ZATCA Java SDK File Setup](#3-zatca-java-sdk-file-setup)
4. [Supervisor Queue Worker Setup](#4-supervisor-queue-worker-setup)
5. [Cron Job & Laravel Scheduler](#5-cron-job--laravel-scheduler)
6. [Storage Directory & Permissions](#6-storage-directory--permissions)
7. [Verification & Sanity Checks](#7-verification--sanity-checks)
8. [Automated One-Click Setup Script](#8-automated-one-click-setup-script)

---

## 1. System & OS Requirements

* **Operating System:** Ubuntu 20.04 / 22.04 LTS or Debian 11+ (recommended).
* **PHP Version:** PHP 8.1, 8.2, or 8.3.
* **Required PHP Extensions:**
  * `ext-dom`
  * `ext-xml`
  * `ext-openssl`
  * `ext-mbstring`
  * `ext-bcmath`
  * `ext-json`
* **System Utilities:** `curl`, `unzip`, `cron`, `supervisor`.

---

## 2. Java Runtime Environment (JRE/JDK)

The ZATCA package relies on the official ZATCA Java SDK for XML cryptographic signing, hash generation, and UBL 2.1 validation.

* **Supported Java Versions:** **Java 11, 12, 13, 14, or 15** (OpenJDK or Oracle JDK).
* **Requirement:** The `java` executable binary MUST be present in system `PATH` for the web server user (`www-data`, `nginx`, etc.).

### Installation on Ubuntu/Debian:
```bash
sudo apt-get update
sudo apt-get install -y openjdk-11-jre-headless
```

### Verification:
```bash
java -version
# Output MUST show openjdk version "11.x.x" (or 12-15)
```

---

## 3. ZATCA Java SDK File Setup

Place the official ZATCA SDK release folder on your server (e.g. inside `storage/app/private/zatca/sdk/` or `/var/www/zatca-sdk/`).

### Required `.env` Configuration:
```env
# Absolute path to the ZATCA SDK JAR file
ZATCA_SDK_EXECUTABLE="/var/www/app/storage/app/private/zatca/sdk/Apps/fatoora-executable.jar"

# SDK Version string
ZATCA_SDK_VERSION="V3.4.8"

# Optional SHA-256 fingerprint validation
ZATCA_SDK_FINGERPRINT="your_sha256_hash_here"
```

---

## 4. Supervisor Queue Worker Setup

All invoice generation and reporting jobs are processed asynchronously via the `zatca` queue to avoid blocking HTTP requests.

### Configuration File: `/etc/supervisor/conf.d/zatca-worker.conf`

```ini
[program:zatca-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/app/artisan queue:work --queue=zatca --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/app/storage/logs/zatca-worker.log
stopwaitsecs=3600
```

### Activation Commands:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start zatca-worker:*
```

---

## 5. Cron Job & Laravel Scheduler

The Laravel Scheduler handles certificate status checks, warning notifications, transient retry jobs (`zatca:requeue --only-transient`), and automatic generated-document repair (`zatca:repair-generated-documents`).

The application schedule should include:

```php
$schedule->command('zatca:report-pending')->everyFiveMinutes();
$schedule->command('zatca:requeue --only-transient')->hourly();
$schedule->command('zatca:repair-generated-documents')->everyTenMinutes()->withoutOverlapping();
$schedule->command('zatca:certificate:check')->daily();
$schedule->command('zatca:status')->hourly();
$schedule->command('zatca:prune-submission-attempts')->weekly();
$schedule->command('zatca:prune-sdk-files')->daily();
```

### Add Crontab Entry for `www-data`:
```bash
* * * * * cd /var/www/app && php artisan schedule:run >> /dev/null 2>&1
```

---

## 6. Storage Directory & Permissions

The package generates ephemeral workspace directories for each signing process under `storage/app/private/zatca/runtime/`.

### Commands:
```bash
mkdir -p /var/www/app/storage/app/private/zatca
sudo chown -R www-data:www-data /var/www/app/storage
sudo chmod -R 775 /var/www/app/storage
```

---

## 7. Verification & Sanity Checks

Run these commands on the server to verify the setup:

```bash
# 1. Check overall package & certificate status
php artisan zatca:status

# 2. Test local Java SDK signing (does not call ZATCA API)
php artisan zatca:sdk-smoke-test

# 3. Test environment configuration & paths
php artisan zatca:health-check

# 4. Check Supervisor status
sudo supervisorctl status zatca-worker:*
```

---

## 8. Automated One-Click Setup Script

Save this script as `setup_zatca.sh` on your server and execute it with `sudo`:

```bash
#!/bin/bash
set -e

GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${GREEN}=== Starting ZATCA Production Server Setup ===${NC}"

read -p "Enter absolute path to your Laravel Application (e.g. /var/www/coursefer): " APP_DIR

if [ ! -f "$APP_DIR/artisan" ]; then
    echo -e "${RED}Error: Could not find artisan file at $APP_DIR${NC}"
    exit 1
fi

WEB_USER="www-data"

echo -e "${YELLOW}[1/5] Installing OpenJDK 11 & Supervisor...${NC}"
sudo apt-get update -y
sudo apt-get install -y openjdk-11-jre-headless supervisor unzip curl

echo -e "${YELLOW}[2/5] Setting up storage permissions...${NC}"
mkdir -p "$APP_DIR/storage/app/private/zatca"
sudo chown -R $WEB_USER:$WEB_USER "$APP_DIR/storage"
sudo chmod -R 775 "$APP_DIR/storage"

echo -e "${YELLOW}[3/5] Configuring Supervisor...${NC}"
SUPERVISOR_CONF="/etc/supervisor/conf.d/zatca-worker.conf"
sudo bash -c "cat > $SUPERVISOR_CONF" <<EOF
[program:zatca-worker]
process_name=%(program_name)s_%(process_num)02d
command=php $APP_DIR/artisan queue:work --queue=zatca --sleep=3 --tries=3 --max-time=3600
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=$WEB_USER
numprocs=2
redirect_stderr=true
stdout_logfile=$APP_DIR/storage/logs/zatca-worker.log
stopwaitsecs=3600
EOF

echo -e "${YELLOW}[4/5] Reloading Supervisor...${NC}"
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start zatca-worker:*

echo -e "${YELLOW}[5/5] Registering Laravel Cron...${NC}"
CRON_CMD="* * * * * cd $APP_DIR && php artisan schedule:run >> /dev/null 2>&1"
( crontab -l -u $WEB_USER 2>/dev/null | grep -F "$APP_DIR" ) || ( (crontab -l -u $WEB_USER 2>/dev/null; echo "$CRON_CMD") | crontab -u $WEB_USER - )

echo -e "\n${GREEN}=== ZATCA Server Setup Completed Successfully! ===${NC}"
sudo supervisorctl status zatca-worker:*
```
