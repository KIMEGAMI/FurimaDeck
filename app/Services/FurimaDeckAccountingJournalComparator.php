<?php

namespace App\Services;

class FurimaDeckAccountingJournalComparator
{
    public const MONEY_FORWARD_INVOICE_KIND_NOT_TARGET = 'INVOICE_KIND_NOT_TARGET';

    /** @param array<string, mixed> $sent
     * @param  array<string, mixed>  $received
     */
    public function matchesFreee(array $sent, array $received): bool
    {
        if ($this->integer($sent['company_id'] ?? null) === null
            || $this->integer($sent['company_id']) !== $this->integer($received['company_id'] ?? null)
            || ! $this->sameText($sent, $received, 'issue_date')
            || ! $this->sameText($sent, $received, 'ref_number')
            || ($sent['adjustment'] ?? null) !== ($received['adjustment'] ?? null)) {
            return false;
        }

        return $this->sameLines($sent['details'] ?? null, $received['details'] ?? null, function (array $line): ?array {
            $amount = $this->integer($line['amount'] ?? null);
            $account = $this->integer($line['account_item_id'] ?? null);
            $tax = $this->integer($line['tax_code'] ?? null);
            $side = $line['entry_side'] ?? null;
            if ($amount === null || $amount <= 0 || $account === null || $tax === null
                || ! in_array($side, ['debit', 'credit'], true) || ! is_string($line['description'] ?? null)) {
                return null;
            }

            return [$side, $account, $tax, $amount, $line['description']];
        });
    }

    /** @param array<string, mixed> $sent
     * @param  array<string, mixed>  $received
     */
    public function matchesMoneyForward(array $sent, array $received, ?string $applicationName = null): bool
    {
        return $this->moneyForwardMismatchFields($sent, $received, $applicationName) === [];
    }

    /** @param array<string, mixed> $sent
     * @param  array<string, mixed>  $received
     * @return list<string> Field paths only; never includes remote values.
     */
    public function moneyForwardMismatchFields(array $sent, array $received, ?string $applicationName = null): array
    {
        $mismatches = [];
        foreach (['transaction_date', 'journal_type'] as $field) {
            if (! $this->sameText($sent, $received, $field)) {
                $mismatches[] = $field;
            }
        }
        if (! $this->sameMoneyForwardMemo($sent['memo'] ?? null, $received['memo'] ?? null, $applicationName)) {
            $mismatches[] = 'memo';
        }
        if (! $this->sameMoneyForwardTags($sent['tags'] ?? [], $received)) {
            $mismatches[] = 'tags';
        }

        return [...$mismatches, ...$this->moneyForwardBranchMismatchFields($sent['branches'] ?? null, $received['branches'] ?? null)];
    }

    /**
     * @param  array<string, mixed>  $sent
     * @param  array<string, mixed>  $received
     * @return list<string>
     */
    public function moneyForwardUnsentInvoiceKindDefaultFields(array $sent, array $received, ?string $applicationName = null): array
    {
        $mismatches = $this->moneyForwardMismatchFields($sent, $received, $applicationName);
        $invoiceFields = ['debit_invoice', 'credit_invoice'];
        if ($mismatches === [] || array_diff($mismatches, $invoiceFields) !== []) {
            return [];
        }

        $sentBranches = $this->uniqueMoneyForwardBranches($sent['branches'] ?? null, true);
        $receivedBranches = $this->uniqueMoneyForwardBranches($received['branches'] ?? null, false);
        if ($sentBranches === null || $receivedBranches === null || array_diff_key($sentBranches, $receivedBranches) !== []
            || array_diff_key($receivedBranches, $sentBranches) !== []) {
            return [];
        }

        $defaultFields = [];
        foreach ($sentBranches as $base => $sentBranch) {
            $receivedBranch = $receivedBranches[$base];
            foreach (['debitor' => 'debit_invoice', 'creditor' => 'credit_invoice'] as $side => $field) {
                $sentValue = $sentBranch[$side];
                $receivedValue = $receivedBranch[$side];
                $sentHasInvoiceKind = array_key_exists('invoice_kind', $sentValue);
                $sentInvoiceKind = $sentValue['invoice_kind'] ?? null;
                $receivedInvoiceKind = $receivedValue['invoice_kind'] ?? null;

                if (! $sentHasInvoiceKind && $receivedInvoiceKind === self::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET) {
                    $defaultFields[] = $field;

                    continue;
                }
                if ($sentHasInvoiceKind && $sentInvoiceKind === $receivedInvoiceKind) {
                    continue;
                }
                if (! $sentHasInvoiceKind && $receivedInvoiceKind === null) {
                    continue;
                }

                return [];
            }
        }

        return array_values(array_unique($defaultFields));
    }

    /**
     * @return array<string, array{debitor: array<string, mixed>, creditor: array<string, mixed>}>|null
     */
    private function uniqueMoneyForwardBranches(mixed $branches, bool $validateOptionalFields): ?array
    {
        if (! is_array($branches) || ! array_is_list($branches) || $branches === []) {
            return null;
        }

        $normalizedBranches = [];
        foreach ($branches as $branch) {
            $normalized = is_array($branch) ? $this->normalizeMoneyForwardBranch($branch, $validateOptionalFields) : null;
            if ($normalized === null || array_key_exists($normalized['base'], $normalizedBranches)) {
                return null;
            }
            $normalizedBranches[$normalized['base']] = [
                'debitor' => $branch['debitor'],
                'creditor' => $branch['creditor'],
            ];
        }

        return $normalizedBranches;
    }

    /** @param array<string, mixed> $received */
    private function sameMoneyForwardTags(mixed $sent, array $received): bool
    {
        if (! is_array($sent) || ! array_is_list($sent)) {
            return false;
        }
        if (! array_key_exists('tags', $received)) {
            return $sent === [];
        }

        return is_array($received['tags'])
            && array_is_list($received['tags'])
            && $sent === $received['tags'];
    }

    private function sameMoneyForwardMemo(mixed $sent, mixed $received, ?string $applicationName): bool
    {
        if (! is_string($sent) || $sent === '' || ! is_string($received)) {
            return false;
        }

        return $received === $sent
            || ($applicationName !== null && $applicationName !== '' && $received === $sent."\n".$applicationName);
    }

    private function sameLines(mixed $sent, mixed $received, callable $normalize): bool
    {
        if (! is_array($sent) || ! is_array($received) || ! array_is_list($sent)
            || ! array_is_list($received) || $sent === [] || count($sent) !== count($received)) {
            return false;
        }
        $sentLines = [];
        $receivedLines = [];
        foreach ($sent as $line) {
            $normalized = is_array($line) ? $normalize($line) : null;
            if ($normalized === null) {
                return false;
            }
            $sentLines[] = json_encode($normalized, JSON_THROW_ON_ERROR);
        }
        foreach ($received as $line) {
            $normalized = is_array($line) ? $normalize($line) : null;
            if ($normalized === null) {
                return false;
            }
            $receivedLines[] = json_encode($normalized, JSON_THROW_ON_ERROR);
        }
        sort($sentLines);
        sort($receivedLines);

        return $sentLines === $receivedLines;
    }

    /**
     * @return list<string>
     */
    private function moneyForwardBranchMismatchFields(mixed $sent, mixed $received): array
    {
        if (! is_array($sent) || ! is_array($received) || ! array_is_list($sent)
            || ! array_is_list($received) || $sent === [] || count($sent) !== count($received)) {
            return ['branches'];
        }

        $sentBranches = [];
        foreach ($sent as $branch) {
            $normalized = is_array($branch) ? $this->normalizeMoneyForwardBranch($branch, true) : null;
            if ($normalized === null) {
                return ['branches'];
            }
            $sentBranches[] = $normalized;
        }
        $receivedBranches = [];
        foreach ($received as $branch) {
            $normalized = is_array($branch) ? $this->normalizeMoneyForwardBranch($branch, false) : null;
            if ($normalized === null) {
                return ['branches'];
            }
            $receivedBranches[] = $normalized;
        }

        $matchedSent = array_fill(0, count($sentBranches), false);
        $matchedReceived = array_fill(0, count($receivedBranches), false);
        foreach ($sentBranches as $sentIndex => $expected) {
            foreach ($receivedBranches as $receivedIndex => $actual) {
                if (! $matchedReceived[$receivedIndex] && $expected === $actual) {
                    $matchedSent[$sentIndex] = true;
                    $matchedReceived[$receivedIndex] = true;
                    break;
                }
            }
        }

        $mismatches = [];
        foreach ($sentBranches as $sentIndex => $expected) {
            if ($matchedSent[$sentIndex]) {
                continue;
            }
            $receivedIndex = null;
            foreach ($receivedBranches as $candidateIndex => $actual) {
                if (! $matchedReceived[$candidateIndex] && $expected['base'] === $actual['base']) {
                    $receivedIndex = $candidateIndex;
                    break;
                }
            }
            if ($receivedIndex === null) {
                return ['branches'];
            }

            $matchedSent[$sentIndex] = true;
            $matchedReceived[$receivedIndex] = true;
            foreach (['debitor', 'creditor'] as $side) {
                foreach (['tax_id' => 'tax', 'sub_account_id' => 'sub'] as $field => $code) {
                    if ($expected['sides'][$side][$field] !== $receivedBranches[$receivedIndex]['sides'][$side][$field]) {
                        $mismatches[] = ($side === 'debitor' ? 'debit_' : 'credit_').$code;
                    }
                }

                if ($expected['sides'][$side]['invoice_kind'] !== $receivedBranches[$receivedIndex]['sides'][$side]['invoice_kind']) {
                    $mismatches[] = ($side === 'debitor' ? 'debit_' : 'credit_').'invoice';
                }
            }
        }

        return $mismatches;
    }

    /** @param array<string, mixed> $branch
     * @return array{base: string, sides: array{debitor: array<string, mixed>, creditor: array<string, mixed>}}|null
     */
    private function normalizeMoneyForwardBranch(array $branch, bool $validateOptionalFields): ?array
    {
        if (! is_string($branch['remark'] ?? null)) {
            return null;
        }

        $sides = [];
        $requiredSides = [];
        foreach (['debitor', 'creditor'] as $side) {
            $value = $branch[$side] ?? null;
            if (! is_array($value) || ! is_string($value['account_id'] ?? null) || $value['account_id'] === '') {
                return null;
            }
            $amount = $this->integer($value['value'] ?? null);
            if ($amount === null || $amount <= 0) {
                return null;
            }

            if ($validateOptionalFields) {
                foreach (['tax_id', 'sub_account_id', 'invoice_kind'] as $field) {
                    if (array_key_exists($field, $value) && $value[$field] !== null
                        && (! is_string($value[$field]) || $value[$field] === '')) {
                        return null;
                    }
                }
            }

            $optional = [];
            foreach (['tax_id', 'sub_account_id', 'invoice_kind'] as $field) {
                $fieldValue = $value[$field] ?? null;
                if ($fieldValue !== null && $fieldValue !== '' && ! is_string($fieldValue)) {
                    return null;
                }
                $optional[$field] = $fieldValue === '' ? null : $fieldValue;
            }
            $sides[$side] = $optional;
            $requiredSides[] = [$amount, $value['account_id']];
        }

        return [
            'base' => json_encode([$branch['remark'], ...$requiredSides], JSON_THROW_ON_ERROR),
            'sides' => $sides,
        ];
    }

    /** @param array<string, mixed> $sent
     * @param  array<string, mixed>  $received
     */
    private function sameText(array $sent, array $received, string $key): bool
    {
        return is_string($sent[$key] ?? null) && $sent[$key] !== '' && $sent[$key] === ($received[$key] ?? null);
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (string) (int) $value === $value) {
            return (int) $value;
        }

        return null;
    }
}
