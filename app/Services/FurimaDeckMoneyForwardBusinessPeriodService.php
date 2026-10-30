<?php

namespace App\Services;

use RuntimeException;

class FurimaDeckMoneyForwardBusinessPeriodService
{
    /** @param array<int, mixed> $periods @return list<array{from: string, to: string, invoice_kind: string}> */
    public function normalize(array $periods): array
    {
        if ($periods === []) {
            throw new RuntimeException('課税・免税状態を確認する対象期間を1件以上設定してください。');
        }

        $normalized = [];
        foreach ($periods as $period) {
            if (! is_array($period)) {
                throw new RuntimeException('Money Forwardの課税・免税対象期間の形式が正しくありません。');
            }

            $from = $this->strictDate((string) ($period['from'] ?? ''));
            $to = $this->strictDate((string) ($period['to'] ?? ''));
            $invoiceKind = $this->normalizedBusinessStatus($period['invoice_kind'] ?? null);
            if ($from === null || $to === null || $from > $to || $invoiceKind === null) {
                throw new RuntimeException('Money Forwardの課税・免税対象期間または状態が正しくありません。設定は保存していません。');
            }

            $normalized[] = ['from' => $from, 'to' => $to, 'invoice_kind' => $invoiceKind];
        }

        usort($normalized, static fn (array $left, array $right): int => $left['from'] <=> $right['from']);
        $previous = null;
        foreach ($normalized as $period) {
            if ($previous !== null && $period['from'] <= $previous['to']) {
                throw new RuntimeException('Money Forwardの課税・免税対象期間が重複しています。期間を重ねずに設定してください。');
            }
            $previous = $period;
        }

        return $normalized;
    }

    /** @param array<int|string, mixed> $periods @return array<int|string, array<string, mixed>> */
    public function normalizeForDisplay(array $periods): array
    {
        foreach ($periods as $index => $period) {
            if (! is_array($period)) {
                $periods[$index] = [
                    'from' => '',
                    'to' => '',
                    'invoice_kind' => FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED,
                ];

                continue;
            }

            $period['invoice_kind'] = $this->normalizedBusinessStatus($period['invoice_kind'] ?? null)
                ?? FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED;
            $periods[$index] = $period;
        }

        return $periods;
    }

    /** @param array<string, mixed> $settings */
    public function statusForDate(array $settings, string $date): string
    {
        $date = $this->strictDate($date);
        if ($date === null) {
            throw new RuntimeException('取引日を確認できないため、Money Forwardへの送信・CSV出力を停止しました。');
        }

        $configuredPeriods = (array) ($settings['business_tax_periods'] ?? []);
        if ($configuredPeriods === []) {
            throw new RuntimeException('取引日を含む課税・免税の確認期間が設定されていません。Money Forwardの設定を確認してください。');
        }

        $periods = $this->normalize($configuredPeriods);
        $matches = collect($periods)
            ->filter(static fn (array $period): bool => $period['from'] <= $date && $date <= $period['to'])
            ->values();
        if ($matches->count() !== 1) {
            throw new RuntimeException('取引日を含む課税・免税の確認期間が1件に特定できません。設定を確認するまでMoney Forwardへの送信・CSV出力を停止しました。');
        }

        $invoiceKind = (string) ($matches->first()['invoice_kind'] ?? '');
        if (! in_array($invoiceKind, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)) {
            throw new RuntimeException('取引日に適用するMoney Forwardの課税・免税状態が確認できません。送信・CSV出力を停止しました。');
        }

        return $invoiceKind;
    }

    private function strictDate(string $date): ?string
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

        return $parsed !== false && $parsed->format('Y-m-d') === $date ? $date : null;
    }

    private function normalizedBusinessStatus(mixed $status): ?string
    {
        if (in_array($status, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_LEGACY_VALUES, true)) {
            return FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_STATUS_UNCONFIRMED;
        }

        return in_array($status, FurimaDeckMoneyForwardInvoiceKindService::BUSINESS_SETTING_VALUES, true)
            ? $status
            : null;
    }
}
