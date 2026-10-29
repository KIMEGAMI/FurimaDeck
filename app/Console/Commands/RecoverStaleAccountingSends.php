<?php

namespace App\Console\Commands;

use App\Jobs\SendFreeeAccountingEntryJob;
use App\Jobs\SendMoneyForwardAccountingEntryJob;
use App\Services\FurimaDeckAccountingBatchService;
use App\Services\FurimaDeckAccountingStaleSendRecoveryService;
use Illuminate\Console\Command;

class RecoverStaleAccountingSends extends Command
{
    protected $signature = 'furimadeck:accounting-recover-stale-sends';

    protected $description = 'Stop stale accounting sends safely without retrying external journal submissions';

    public function handle(FurimaDeckAccountingStaleSendRecoveryService $recovery): int
    {
        $ageSeconds = (int) config('furimadeck.accounting.stale_send_recovery_seconds');
        $queueRetryAfter = config('queue.connections.'.FurimaDeckAccountingBatchService::QUEUE_CONNECTION.'.retry_after');
        if (! is_numeric($queueRetryAfter) || (int) $queueRetryAfter < 0) {
            $this->error('会計ジョブ用キューの再試行時間を特定できません。接続設定を確認するまで復旧処理を停止します。');

            return self::FAILURE;
        }

        $minimumAgeSeconds = max(
            $queueRetryAfter,
            SendMoneyForwardAccountingEntryJob::TIMEOUT_SECONDS,
            SendFreeeAccountingEntryJob::TIMEOUT_SECONDS,
        );
        if ($ageSeconds <= $minimumAgeSeconds) {
            $this->error('送信状態の復旧時間は、キューの再試行時間とジョブのタイムアウトより長く設定してください。');

            return self::FAILURE;
        }

        $counts = $recovery->recover($ageSeconds);
        $this->info("登録済みを確認: {$counts['succeeded']}件 / 送信結果不明として停止: {$counts['uncertain']}件 / 未送信として停止: {$counts['not_sent']}件。外部への再送はしていません。");

        if ($counts['deferred'] > 0) {
            $this->error("復旧保留: {$counts['deferred']}件。キュー状態を確認できないため変更していません。キューDBを復旧してから再実行してください。");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
