#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/furimadeck}"
QUEUE_SERVICE_NAME="${QUEUE_SERVICE_NAME:-furimadeck-queue.service}"
SCHEDULER_TIMER_NAME="furimadeck-scheduler.timer"
REBOOT_SERVER="false"

for arg in "$@"; do
    case "${arg}" in
        --reboot)
            REBOOT_SERVER="true"
            ;;
        *)
            echo "ERROR: Unknown argument: ${arg}"
            echo "Usage: sudo bash deploy/scripts/restart-production-services.sh [--reboot]"
            exit 1
            ;;
    esac
done

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: Run as root. Example: sudo bash deploy/scripts/restart-production-services.sh"
    exit 1
fi

if [[ ! -d "${APP_DIR}" || ! -f "${APP_DIR}/artisan" ]]; then
    echo "ERROR: APP_DIR is not a Laravel project: ${APP_DIR}"
    exit 1
fi

cd "${APP_DIR}"

echo "==> Remove public/hot"
sudo -u www-data rm -f public/hot

echo "==> Rebuild Laravel production cache"
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
sudo -u www-data php artisan queue:restart

echo "==> Restart services"
systemctl restart apache2

if systemctl list-unit-files "${QUEUE_SERVICE_NAME}" --no-pager | grep -q "${QUEUE_SERVICE_NAME}"; then
    systemctl restart "${QUEUE_SERVICE_NAME}"
else
    echo "INFO: ${QUEUE_SERVICE_NAME} is not installed. Run install-startup-services.sh if queue workers are needed."
fi

if systemctl list-unit-files "${SCHEDULER_TIMER_NAME}" --no-pager | grep -q "${SCHEDULER_TIMER_NAME}"; then
    systemctl restart "${SCHEDULER_TIMER_NAME}"
else
    echo "INFO: ${SCHEDULER_TIMER_NAME} is not installed. Run install-startup-services.sh to enable scheduled recovery."
fi

echo "==> Service status"
systemctl --no-pager --full status apache2 || true
if systemctl list-unit-files "${QUEUE_SERVICE_NAME}" --no-pager | grep -q "${QUEUE_SERVICE_NAME}"; then
    systemctl --no-pager --full status "${QUEUE_SERVICE_NAME}" || true
fi
if systemctl list-unit-files "${SCHEDULER_TIMER_NAME}" --no-pager | grep -q "${SCHEDULER_TIMER_NAME}"; then
    systemctl --no-pager --full status "${SCHEDULER_TIMER_NAME}" || true
fi

if [[ "${REBOOT_SERVER}" == "true" ]]; then
    echo "==> Reboot server in 5 seconds"
    sleep 5
    systemctl reboot
fi

echo "Done: production services were restarted."

