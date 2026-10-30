<?php

namespace App\Services;

use PHPUnit\Framework\TestCase;

class FurimaDeckAccountingApiReleaseGate
{
    private const ENABLED_FOR_RELEASE = false;

    public function enabled(): bool
    {
        $isUnitTestHarness = app()->environment('testing')
            && class_exists(TestCase::class, false);

        return self::allows(
            $isUnitTestHarness,
            PHP_SAPI === 'cli',
            (bool) config('furimadeck.accounting.provider_api_enabled'),
            (bool) config('furimadeck.accounting.direct_journal_send_enabled'),
        );
    }

    public static function allows(bool $isUnitTestHarness, bool $isCliProcess, bool $providerApiEnabled, bool $directJournalSendEnabled): bool
    {
        return $providerApiEnabled
            && $directJournalSendEnabled
            && (($isUnitTestHarness && $isCliProcess) || self::ENABLED_FOR_RELEASE);
    }
}
