<?php

namespace App\Services;

use App\Models\SystemSetting;
use Throwable;

class MaintenanceModeService
{
    public function enabled(): bool
    {
        if ((bool) config('maintenance.enabled')) {
            return true;
        }

        try {
            return SystemSetting::query() // nosemgrep: php.lang.security.exec-use.exec-use -- Eloquent database query only; no shell command execution.
                ->where('key', SystemSetting::KEY_MAINTENANCE_ENABLED)
                ->value('value') === '1';
        } catch (Throwable) {
            return false;
        }
    }

    public function setEnabled(bool $enabled): void
    {
        SystemSetting::query()->updateOrCreate( // nosemgrep: php.lang.security.exec-use.exec-use -- Eloquent database update only; no shell command execution.
            ['key' => SystemSetting::KEY_MAINTENANCE_ENABLED],
            ['value' => $enabled ? '1' : '0']
        );
    }
}
