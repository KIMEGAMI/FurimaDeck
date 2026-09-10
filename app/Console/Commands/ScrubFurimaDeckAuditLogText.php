<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Throwable;

class ScrubFurimaDeckAuditLogText extends Command
{
    private const BATCH_SIZE = 100;

    protected $signature = 'furimadeck:scrub-audit-log-text {--apply : Replace historical audit-log text values with redaction markers}';

    protected $description = 'Preview or scrub free-text values from FurimaDeck audit logs';

    public function handle(AuditLogger $auditLogger): int
    {
        $apply = (bool) $this->option('apply');
        $changed = 0;
        $failed = 0;

        AuditLog::query()
            ->whereNotNull('before_json')
            ->orWhereNotNull('after_json')
            ->lazyById(self::BATCH_SIZE)
            ->each(function (AuditLog $auditLog) use ($auditLogger, $apply, &$changed, &$failed): void {
                try {
                    $before = $auditLogger->sanitize($auditLog->before_json);
                    $after = $auditLogger->sanitize($auditLog->after_json);

                    if ($before === $auditLog->before_json && $after === $auditLog->after_json) {
                        return;
                    }

                    $changed++;
                    if ($apply) {
                        $auditLog->forceFill(['before_json' => $before, 'after_json' => $after])->save();
                    }
                } catch (Throwable) {
                    $failed++;
                    $this->error('監査ログID '.$auditLog->getKey().' を処理できませんでした。');
                }
            });

        $this->info(sprintf('%s: 対象変更 %d件、失敗 %d件。', $apply ? '適用結果' : 'Dry-run', $changed, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
