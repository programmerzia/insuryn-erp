<?php

declare(strict_types=1);

namespace App\Modules\Finance\Payables\Http\Controllers;

use App\Http\Pages\PageSupport;
use Illuminate\Support\Facades\DB;

/** Shared bits of the payables screens: who opens them, account and supplier labels, and the entity's active bank accounts. */
final class PayablesArea
{
    /** Payables screens open for any ap.* permission or read-only financial reporting. */
    public const AREA = ['ap.manage_suppliers', 'ap.enter_bills', 'ap.approve_bills', 'ap.prepare_payments', 'ap.approve_payments', 'ap.release_payments', 'reports.financial'];

    /** @return list<array{value: string, label: string}> */
    public static function categories(): array
    {
        $options = [];
        /** @var array<string, array{label: string, vat_bp: int, vds_bp: int, tds_bp: int}> $categories */
        $categories = config('erp.payables.categories', []);
        foreach ($categories as $code => $category) {
            $options[] = ['value' => (string) $code, 'label' => $category['label'].' · VAT '.PageSupport::percent($category['vat_bp']).'%, VDS '.PageSupport::percent($category['vds_bp'])
                .'%, TDS '.PageSupport::percent($category['tds_bp']).'%'];
        }

        return $options;
    }

    /** @return list<array{id: string, label: string}> */
    public static function bankAccounts(string $entityId): array
    {
        return array_values(DB::table('bank_accounts')->where('entity_id', $entityId)->where('status', 'active')->where('currency', 'BDT')->orderBy('bank_name')
            ->get(['id', 'bank_name', 'account_no_masked'])->map(fn (object $b): array => ['id' => (string) $b->id, 'label' => "{$b->bank_name} {$b->account_no_masked}"])->all());
    }

    public static function accountLabel(?string $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }
        $account = DB::table('accounts')->where('id', $accountId)->first(['code', 'name']);

        return $account === null ? null : "{$account->code} {$account->name}";
    }
}
