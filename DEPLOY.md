# Production Deployment

> **Legacy FURUPRO deployment only.** This document retains the existing
> FURUPRO production path, database, domain, and integration settings for
> legacy-service maintenance. Do not use it to deploy FurimaDeck.
>
> FurimaDeck must use a dedicated application path, database, public URL,
> Stripe endpoint, and OAuth redirect URI. Follow
> `docs/furimadeck-cutover-handoff.md` after those values are confirmed.

This project is a Laravel application with Vite assets.

The production environment assumed here is:

- Ubuntu
- Apache
- PHP 8.4.1 or newer
- Composer
- Node.js / npm
- MySQL or MariaDB
- GitHub remote repository

## 1. Prepare the Server

Install the required packages.

```bash
sudo apt update
sudo apt install -y apache2 mysql-server git unzip
sudo apt install -y php php-cli php-fpm php-mysql php-xml php-mbstring php-curl php-zip php-bcmath
```

Install Composer and Node.js if they are not already available. If your OS package repository provides PHP older than 8.4.1, install a supported PHP 8.4 package source before continuing.

```bash
composer --version
node -v
npm -v
```

## 2. Clone or Update the Application

For the first deployment:

```bash
cd /var/www
sudo git clone https://github.com/KIMEGAMI/FurimaDeck.git furugi
sudo chown -R www-data:www-data /var/www/furugi
cd /var/www/furugi
```

For later deployments:

```bash
cd /var/www/furugi
sudo -u www-data git pull origin main
```

## 3. Configure `.env`

Create `.env` on the server if it does not exist.

```bash
sudo -u www-data cp .env.example .env
sudo -u www-data php artisan key:generate
```

Set production values.

```dotenv
APP_NAME="FURUPRO"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://furupro.shinji.work
APP_FORCE_HTTPS=true

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=furugi
DB_USERNAME=furugi_user
DB_PASSWORD=your_secure_password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
MAIL_MAILER=log
```

If Google login is used, also set the Google OAuth values in `.env`.

```dotenv
GOOGLE_CLIENT_ID=your_google_client_id
GOOGLE_CLIENT_SECRET=your_google_client_secret
GOOGLE_REDIRECT_URI=https://furupro.shinji.work/auth/google/callback
```

## 4. Install Dependencies and Build Assets

```bash
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data npm ci
sudo -u www-data npm run build
```

## 5. Database Migration

Run migrations in production mode.

```bash
sudo -u www-data php artisan migrate --force
```

Create or update an admin user in the database. The command asks for the password with hidden input and stores only the hashed password.

```bash
sudo -u www-data php artisan admin:create admin@shinji.work
```

The demo login button uses these environment variables:

- `DEMO_USER_EMAIL`
- `DEMO_USER_PASSWORD`

Create or update the demo user on the server if needed.

```bash
sudo -u www-data php artisan tinker
```

```php
\App\Models\User::updateOrCreate(
    ['email' => config('demo.user_email')],
    [
        'name' => 'Demo User',
        'password' => config('demo.user_password'),
        'email_verified_at' => now(),
    ]
);
```

## 6. Storage and Permissions

```bash
sudo -u www-data php artisan storage:link
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rw storage bootstrap/cache
```

## 7. Apache Configuration and HTTPS

Create an Apache site file.

```bash
sudo nano /etc/apache2/sites-available/furugi.conf
```

Example:

```apache
<VirtualHost *:80>
    ServerName furupro.shinji.work
    Redirect permanent / https://furupro.shinji.work/
</VirtualHost>

<VirtualHost *:443>
    ServerName furupro.shinji.work
    DocumentRoot /var/www/furugi/public

    <Directory /var/www/furugi/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/furugi_error.log
    CustomLog ${APACHE_LOG_DIR}/furugi_access.log combined

    SSLEngine on
    SSLCertificateFile /etc/letsencrypt/live/furupro.shinji.work/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/furupro.shinji.work/privkey.pem
</VirtualHost>
```

Enable the site, rewrite module, SSL module, and headers module.

```bash
sudo a2enmod rewrite ssl headers
sudo a2ensite furugi.conf
sudo apache2ctl configtest
sudo systemctl reload apache2
```

If the server does not have a certificate yet, use Certbot before enabling the final SSL virtual host.

```bash
sudo apt install -y certbot python3-certbot-apache
sudo certbot --apache -d furupro.shinji.work
sudo certbot renew --dry-run
```

After HTTPS is enabled, verify these production values.

```dotenv
APP_URL=https://furupro.shinji.work
APP_FORCE_HTTPS=true
SESSION_SECURE_COOKIE=true
GOOGLE_REDIRECT_URI=https://furupro.shinji.work/auth/google/callback
```

PWA installation requires HTTPS in normal production browsers. The login screen's app install button will only appear when the browser judges the site installable.

## 8. Optimize Laravel

Run these after each deployment.

```bash
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

## 9. Queue Worker

This app uses the database queue connection by default. If queued jobs are used in production, run a worker with Supervisor or systemd.

Recommended systemd setup:

```bash
cd /var/www/furugi
sudo bash deploy/scripts/install-startup-services.sh
```

This enables Apache2 at boot and installs `furugi-queue.service` for Laravel queue workers.

Simple manual start:

```bash
sudo -u www-data php artisan queue:work --tries=3
```

After each deployment:

```bash
sudo -u www-data php artisan queue:restart
```

Restart production services without rebooting the server:

```bash
cd /var/www/furugi
sudo bash deploy/scripts/restart-production-services.sh
```

Restart services and then reboot the server:

```bash
cd /var/www/furugi
sudo bash deploy/scripts/restart-production-services.sh --reboot
```

## 10. Pre-Deploy Checks

Before applying production migrations, confirm that a MySQL backup exists and that the latest backup completed successfully. Codex must not dump or modify the production database without explicit approval.

Run these checks after CI and Security workflows pass.

```bash
cd /var/www/furugi
sudo -u www-data git status
sudo -u www-data git log -1 --oneline
sudo -u www-data php artisan stripe:check
sudo -u www-data php artisan test
sudo -u www-data npm run build
sudo -u www-data composer audit
```

Do not deploy if `stripe:check`, tests, build, CI, or Security workflow fails.

## 11. Standard Manual Update Command

For normal deployments after code is pushed to GitHub:

```bash
cd /var/www/furugi
sudo -u www-data git pull origin main
sudo -u www-data composer install --no-dev --optimize-autoloader
sudo -u www-data rm -f public/hot
sudo -u www-data npm ci
sudo -u www-data npm run build
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan queue:restart
sudo systemctl reload apache2
```

If the login page or layout looks broken after pulling code, rebuild frontend assets and clear Laravel caches. The `public/build` directory is not committed to Git, so `git pull` alone does not update CSS or JavaScript assets.
Also make sure `public/hot` does not exist on the production server. That file is only for local Vite development; if it remains in production, Laravel will try to load assets from the local Vite dev server instead of `/public/build`.

```bash
sudo -u www-data rm -f public/hot
sudo -u www-data npm ci
sudo -u www-data npm run build
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan config:cache
```

## 12. GitHub Actions CI/CD

GitHub Actions workflows are defined in `.github/workflows`.

- `CI` runs tests, builds Vite assets, and audits dependencies on pull requests and pushes to `main`.
- Production deployment is manual. Run it only after CI and Security workflows succeed.
- If a deploy workflow exists, use it as a manually dispatched workflow unless the project owner explicitly enables automatic deployment.

Configure the required GitHub Secrets and optional Variables described in `docs/ci-cd.md`.

The deployment user must be able to run the standard update commands above in `/var/www/furugi` as `www-data`. If sudo requires a password, configure restricted passwordless sudo rules for only the required deployment commands or reload Apache outside the workflow.
