<?php

namespace Tests\Unit;

use App\Services\FurimaDeckAccountingJournalComparator;
use PHPUnit\Framework\TestCase;

class FurimaDeckAccountingJournalComparatorTest extends TestCase
{
    public function test_freee_requires_exact_amount_account_tax_date_and_all_lines(): void
    {
        $sent = [
            'company_id' => 12, 'issue_date' => '2026-10-10', 'ref_number' => 'SKU-1', 'adjustment' => false,
            'details' => [
                ['entry_side' => 'debit', 'account_item_id' => 10, 'tax_code' => 20, 'amount' => 100, 'description' => 'sale'],
                ['entry_side' => 'credit', 'account_item_id' => 11, 'tax_code' => 21, 'amount' => 100, 'description' => 'sale'],
            ],
        ];
        $remote = [...$sent, 'details' => array_reverse($sent['details'])];
        $comparator = new FurimaDeckAccountingJournalComparator;
        $this->assertTrue($comparator->matchesFreee($sent, $remote));
        foreach (['company_id' => 13, 'issue_date' => '2026-10-11', 'ref_number' => 'SKU-2'] as $key => $wrong) {
            $this->assertFalse($comparator->matchesFreee($sent, [...$remote, $key => $wrong]));
        }
        foreach (['amount' => 101, 'account_item_id' => 12, 'tax_code' => 22, 'entry_side' => 'debit'] as $key => $wrong) {
            $lines = $remote['details'];
            $lines[0][$key] = $wrong;
            $this->assertFalse($comparator->matchesFreee($sent, [...$remote, 'details' => $lines]));
        }
        $this->assertFalse($comparator->matchesFreee($sent, [...$remote, 'details' => [$remote['details'][0]]]));
        $this->assertFalse($comparator->matchesFreee($sent, [...$remote, 'details' => [...$remote['details'], $remote['details'][0]]]));
    }

    public function test_money_forward_requires_exact_amount_account_tax_date_and_all_branches(): void
    {
        $branch = [
            'remark' => 'sale',
            'debitor' => ['value' => 100, 'account_id' => 'account-1', 'tax_id' => 'tax-1', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
            'creditor' => ['value' => 100, 'account_id' => 'account-2', 'tax_id' => 'tax-2', 'invoice_kind' => 'INVOICE_KIND_NOT_TARGET'],
        ];
        $sent = ['transaction_date' => '2026-10-10', 'journal_type' => 'journal_entry', 'memo' => 'FurimaDeck #SKU-1', 'branches' => [$branch]];
        $comparator = new FurimaDeckAccountingJournalComparator;
        $this->assertTrue($comparator->matchesMoneyForward($sent, $sent));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'transaction_date' => '2026-10-11']));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'tags' => ['unexpected-tag']]));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'tags' => null]));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'branches' => [...$sent['branches'], $branch]]));
        foreach (['value' => 101, 'account_id' => 'account-3', 'tax_id' => 'tax-3'] as $key => $wrong) {
            $changed = $branch;
            $changed['debitor'][$key] = $wrong;
            $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'branches' => [$changed]]));
        }
        $this->assertFalse($comparator->matchesMoneyForward($sent, [...$sent, 'branches' => [['remark' => 'sale']]]));
    }

    public function test_money_forward_verification_rejects_an_unrequested_invoice_kind(): void
    {
        $branch = [
            'remark' => 'sale',
            'debitor' => ['value' => 100, 'account_id' => 'account-1', 'tax_id' => 'tax-1'],
            'creditor' => ['value' => 100, 'account_id' => 'account-2', 'tax_id' => 'tax-2'],
        ];
        $sent = ['transaction_date' => '2026-10-10', 'journal_type' => 'journal_entry', 'memo' => 'FurimaDeck #SKU-1', 'branches' => [$branch]];
        $comparator = new FurimaDeckAccountingJournalComparator;

        $this->assertTrue($comparator->matchesMoneyForward($sent, $sent));
        $classifiedRemote = $sent;
        $classifiedRemote['branches'][0]['debitor']['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';
        $this->assertFalse($comparator->matchesMoneyForward($sent, $classifiedRemote));
        $this->assertSame(
            ['debit_invoice'],
            $comparator->moneyForwardUnsentInvoiceKindDefaultFields($sent, $classifiedRemote),
        );

        $classifiedSent = $sent;
        $classifiedSent['branches'][0]['debitor']['invoice_kind'] = 'INVOICE_KIND_QUALIFIED';
        $this->assertFalse($comparator->matchesMoneyForward($classifiedSent, $classifiedRemote));
        $this->assertSame([], $comparator->moneyForwardUnsentInvoiceKindDefaultFields($classifiedSent, $classifiedRemote));
    }

    public function test_money_forward_verification_compares_invoice_kind_even_without_tax_category(): void
    {
        $sent = [
            'transaction_date' => '2026-10-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #SKU-1',
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 1100, 'account_id' => 'settlement-account'],
                'creditor' => ['value' => 1100, 'account_id' => 'sales-account'],
            ]],
        ];
        $received = $sent;
        $received['branches'][0]['debitor']['invoice_kind'] = 'INVOICE_KIND_QUALIFIED';
        $received['branches'][0]['creditor']['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';

        $comparator = new FurimaDeckAccountingJournalComparator;
        $this->assertFalse($comparator->matchesMoneyForward($sent, $received));
        $this->assertSame(['debit_invoice', 'credit_invoice'], $comparator->moneyForwardMismatchFields($sent, $received));

        $matching = $sent;
        $matching['branches'][0]['debitor']['invoice_kind'] = 'INVOICE_KIND_QUALIFIED';
        $matching['branches'][0]['creditor']['invoice_kind'] = 'INVOICE_KIND_NOT_TARGET';
        $this->assertTrue($comparator->matchesMoneyForward($matching, $received));

        $missingReturnedField = $matching;
        unset($missingReturnedField['branches'][0]['creditor']['invoice_kind']);
        $this->assertFalse($comparator->matchesMoneyForward($matching, $missingReturnedField));
    }

    public function test_money_forward_verification_rejects_provider_defaults_for_omitted_optional_fields(): void
    {
        $sentBranch = [
            'remark' => 'sale',
            'debitor' => ['value' => 1100, 'account_id' => 'settlement-account'],
            'creditor' => ['value' => 1100, 'account_id' => 'sales-account'],
        ];
        $sent = [
            'transaction_date' => '2026-10-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #SKU-1',
            'branches' => [$sentBranch],
        ];
        $received = [
            ...$sent,
            'branches' => [[
                'remark' => 'sale',
                'debitor' => [
                    'value' => 1100,
                    'account_id' => 'settlement-account',
                    'tax_id' => 'provider-default-tax',
                    'sub_account_id' => 'provider-default-sub-account',
                    'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
                ],
                'creditor' => [
                    'value' => 1100,
                    'account_id' => 'sales-account',
                    'tax_id' => 'provider-default-tax',
                    'invoice_kind' => 'INVOICE_KIND_NOT_TARGET',
                ],
            ]],
        ];

        $this->assertFalse((new FurimaDeckAccountingJournalComparator)->matchesMoneyForward($sent, $received));
        $this->assertSame([], (new FurimaDeckAccountingJournalComparator)->moneyForwardUnsentInvoiceKindDefaultFields($sent, $received));
    }

    public function test_money_forward_provider_invoice_default_is_not_classified_when_another_field_differs(): void
    {
        $sent = [
            'transaction_date' => '2026-10-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #SKU-1',
            'tags' => [],
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 1100, 'account_id' => 'settlement-account', 'tax_id' => 'tax-1'],
                'creditor' => ['value' => 1100, 'account_id' => 'sales-account', 'tax_id' => 'tax-2'],
            ]],
        ];
        $received = $sent;
        $received['branches'][0]['debitor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $received['branches'][0]['creditor']['invoice_kind'] = FurimaDeckAccountingJournalComparator::MONEY_FORWARD_INVOICE_KIND_NOT_TARGET;
        $received['branches'][0]['debitor']['tax_id'] = 'different-tax';

        $this->assertSame([], (new FurimaDeckAccountingJournalComparator)->moneyForwardUnsentInvoiceKindDefaultFields($sent, $received));
    }

    public function test_money_forward_verification_accepts_only_the_known_app_name_memo_suffix(): void
    {
        $sent = [
            'transaction_date' => '2026-10-10',
            'journal_type' => 'journal_entry',
            'memo' => 'FurimaDeck #SKU-1',
            'branches' => [[
                'remark' => 'sale',
                'debitor' => ['value' => 1100, 'account_id' => 'settlement-account'],
                'creditor' => ['value' => 1100, 'account_id' => 'sales-account'],
            ]],
        ];
        $comparator = new FurimaDeckAccountingJournalComparator;

        $this->assertTrue($comparator->matchesMoneyForward($sent, [
            ...$sent,
            'memo' => $sent['memo']."\nFurimaDeck Development",
        ], 'FurimaDeck Development'));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [
            ...$sent,
            'memo' => $sent['memo']."\nUser-added memo",
        ], 'FurimaDeck Development'));
        $this->assertFalse($comparator->matchesMoneyForward($sent, [
            ...$sent,
            'memo' => $sent['memo']."\nFurimaDeck Development",
        ]));
    }
}
