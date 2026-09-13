<?php

declare(strict_types=1);

namespace App\Http\Portal\OpenApi;

/** JSON schemas of the producer portal (slice D9). Amounts are integers in minor units with the ISO currency beside them (CONTEXT.md #5). */
final class PortalSchemas
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        $string = ['type' => 'string'];
        $nullableString = ['type' => ['string', 'null']];
        $date = ['type' => 'string', 'format' => 'date'];
        $nullableDate = ['type' => ['string', 'null'], 'format' => 'date'];
        $minor = ['type' => 'integer', 'description' => 'Minor units'];
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $list = fn (string $item): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['type' => 'array', 'items' => ['$ref' => "#/components/schemas/{$item}"]]]];
        $one = fn (string $item): array => ['type' => 'object', 'required' => ['data'], 'properties' => ['data' => ['$ref' => "#/components/schemas/{$item}"]]];
        $object = fn (array $properties): array => ['type' => 'object', 'required' => array_keys($properties), 'properties' => $properties];

        return [
            'Error' => $object(['message' => $string, 'reason' => $string]),
            'TokenRequest' => ['type' => 'object', 'required' => ['email', 'password', 'device_name'], 'properties' => ['email' => ['type' => 'string', 'format' => 'email'], 'password' => $string,
                'device_name' => $string, 'abilities' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['portal:read', 'portal:collect']]]]],
            'Token' => $object(['token' => $string, 'abilities' => ['type' => 'array', 'items' => $string]]),
            'TokenResponse' => $one('Token'),
            'Producer' => $object(['id' => $uuid, 'code' => $string, 'name' => $string, 'type' => $string, 'status' => $string, 'channel' => $string, 'branch' => $string,
                'level' => $nullableString, 'reports_to' => $nullableString]),
            'ProducerResponse' => $one('Producer'),
            'Licence' => $object(['licence_no' => $string, 'authority' => $string, 'class' => $string, 'issued_on' => $date, 'expires_on' => $date, 'status' => $string]),
            'LicenceStatus' => $object(['valid' => ['type' => 'boolean'], 'expires_on' => $nullableDate, 'licences' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Licence']]]),
            'LicenceResponse' => $one('LicenceStatus'),
            'Customer' => $object(['id' => $uuid, 'name' => $string, 'policies' => ['type' => 'integer']]),
            'CustomerList' => $list('Customer'),
            'Policy' => $object(['id' => $uuid, 'number' => $nullableString, 'product' => $string, 'policyholder' => $string, 'status' => $string, 'inception' => $date, 'expiry' => $date,
                'gross_premium_minor' => $minor, 'currency' => $string]),
            'PolicyList' => $list('Policy'),
            'Installment' => $object(['id' => $uuid, 'no' => ['type' => 'integer'], 'due_date' => $date, 'amount_minor' => $minor, 'outstanding_minor' => $minor]),
            'PolicyDetail' => ['allOf' => [['$ref' => '#/components/schemas/Policy'], $object(['installments' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/Installment']]])]],
            'PolicyDetailResponse' => $one('PolicyDetail'),
            'CollectionsToDeposit' => $object(['undeposited_minor' => $minor, 'currency' => $string, 'collections' => ['type' => 'array', 'items' => $object(['receipt_number' => $string, 'value_date' => $date, 'amount_minor' => $minor])]]),
            'CollectionsToDepositResponse' => $one('CollectionsToDeposit'),
            'CollectionRequest' => ['type' => 'object', 'required' => ['installment_id', 'amount_minor', 'value_date'], 'properties' => ['installment_id' => $uuid, 'amount_minor' => $minor, 'value_date' => $date, 'reference' => $nullableString]],
            'Collection' => $object(['id' => $uuid, 'number' => $string, 'amount_minor' => $minor, 'currency' => $string, 'value_date' => $date]),
            'CollectionResponse' => $one('Collection'),
            'Statement' => $object(['id' => $uuid, 'number' => $nullableString, 'period_end' => $nullableDate, 'earned_minor' => $minor, 'override_minor' => $minor, 'bonus_minor' => $minor,
                'clawback_minor' => $minor, 'withholding_minor' => $minor, 'advances_recovered_minor' => $minor, 'net_minor' => $minor, 'currency' => $string, 'status' => $string, 'paid_via' => $string, 'paid_on' => $nullableDate]),
            'StatementList' => $list('Statement'),
            'StatementEntry' => $object(['earned_on' => $date, 'kind' => $string, 'role' => $string, 'policy_number' => $nullableString, 'amount_minor' => $minor, 'withholding_minor' => $minor]),
            'StatementDetail' => ['allOf' => [['$ref' => '#/components/schemas/Statement'], $object(['entries' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StatementEntry']]])]],
            'StatementDetailResponse' => $one('StatementDetail'),
            'Target' => $object(['metric' => $string, 'period_type' => $string, 'period_start' => $date, 'target' => ['type' => 'integer'], 'actual' => ['type' => 'integer'], 'achievement_bp' => ['type' => 'integer']]),
            'TargetList' => $list('Target'),
        ];
    }
}
