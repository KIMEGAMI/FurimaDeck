#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/furimadeck}"
QUEUE_SERVICE_NAME="${QUEUE_SERVICE_NAME:-furimadeck-queue.service}"
QUEUE_SERVICE_TEMPLATE="${APP_DIR}/deploy/systemd/furimadeck-queue.service.template"
QUEUE_SERVICE_TARGET="/etc/systemd/system/${QUEUE_SERVICE_NAME}"
SCHEDULER_SERVICE_NAME="furimadeck-scheduler.service"
SCHEDULER_SERVICE_TEMPLATE="${APP_DIR}/deploy/systemd/furimadeck-scheduler.service.template"
SCHEDULER_TIMER_NAME="furimadeck-scheduler.timer"
SCHEDULER_TIMER_SOURCE="${APP_DIR}/deploy/systemd/${SCHEDULER_TIMER_NAME}"
SCHEDULER_TIMER_TARGET="/etc/systemd/system/${SCHEDULER_TIMER_NAME}"

if [[ "${EUID}" -ne 0 ]]; then
    echo "ERROR: Run as root. Example: sudo bash deploy/scripts/install-startup-services.sh"
    exit 1
fi

if [[ "${APP_DIR}" != /* || "${APP_DIR}" =~ [^A-Za-z0-9_./-] ]]; then
    echo "ERROR: APP_DIR must be an absolute path containing only letters, numbers, '_', '.', '/', and '-'."
    exit 1
fi

if [[ ! -d "${APP_DIR}" || ! -f "${APP_DIR}/artisan" ]]; then
    echo "ERROR: APP_DIR is not a Laravel project: ${APP_DIR}"
    exit 1
fi

for source in "${QUEUE_SERVICE_TEMPLATE}" "${SCHEDULER_SERVICE_TEMPLATE}" "${SCHEDULER_TIMER_SOURCE}"; do
    if [[ ! -f "${source}" ]]; then
        echo "ERROR: deployment service file was not found: ${source}"
        exit 1
    fi
done

render_unit_template() {
    local template="$1"
    local target="$2"
    local rendered

    rendered="$(mktemp)"
    if ! sed "s|@APP_DIR@|${APP_DIR}|g" "${template}" > "${rendered}" \
        || ! install -m 0644 "${rendered}" "${target}"; then
        rm -f "${rendered}"
        return 1
    fi
    rm -f "${rendered}"
}

echo "==> Enable Apache2 auto start"
systemctl enable apache2

echo "==> Install FurimaDeck queue worker and scheduler systemd units"
render_unit_template "${QUEUE_SERVICE_TEMPLATE}" "${QUEUE_SERVICE_TARGET}"
render_unit_template "${SCHEDULER_SERVICE_TEMPLATE}" "/etc/systemd/system/${SCHEDULER_SERVICE_NAME}"
install -m 0644 "${SCHEDULER_TIMER_SOURCE}" "${SCHEDULER_TIMER_TARGET}"
systemctl daemon-reload
systemctl enable "${QUEUE_SERVICE_NAME}"
systemctl enable "${SCHEDULER_TIMER_NAME}"

echo "==> Rebuild Laravel production cache"
cd "${APP_DIR}"
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache

echo "==> Start or restart services"
systemctl restart apache2
systemctl restart "${QUEUE_SERVICE_NAME}"
systemctl start "${SCHEDULER_TIMER_NAME}"

echo "==> Service status"
systemctl --no-pager --full status apache2 || true
systemctl --no-pager --full status "${QUEUE_SERVICE_NAME}" || true
systemctl --no-pager --full status "${SCHEDULER_TIMER_NAME}" || true

echo "Done: Apache2, ${QUEUE_SERVICE_NAME}, and ${SCHEDULER_TIMER_NAME} are enabled."

