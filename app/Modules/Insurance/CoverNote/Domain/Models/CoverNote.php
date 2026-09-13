<?php

declare(strict_types=1);

namespace App\Modules\Insurance\CoverNote\Domain\Models;

use App\Modules\Insurance\CoverNote\Domain\Enums\CoverNoteStatus;
use App\Modules\Platform\Tenancy\BelongsToTenant;
use App\Modules\Platform\Tenancy\HasUuid7;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Phase 3 design §2 cover note (slice R6): temporary evidence of cover for an approved proposal, valid from `valid_from` to `valid_to` inclusive.
 *
 * @property string $id
 * @property string $entity_id
 * @property string $branch_id
 * @property string $proposal_id
 * @property string $number
 * @property string $class_code
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable $valid_to
 * @property CoverNoteStatus $status
 * @property string $issued_by
 * @property CarbonImmutable $issued_at
 * @property string|null $superseded_by_policy_id
 * @property CarbonImmutable|null $superseded_at
 * @property string|null $cancelled_by
 * @property CarbonImmutable|null $cancelled_at
 * @property string|null $cancel_reason
 * @property CarbonImmutable|null $expired_at
 */
final class CoverNote extends Model
{
    use BelongsToTenant;
    use HasUuid7;

    protected $table = 'cover_notes';
    protected $guarded = [];
    protected $casts = [
        'status' => CoverNoteStatus::class, 'valid_from' => 'immutable_date', 'valid_to' => 'immutable_date', 'issued_at' => 'immutable_datetime',
        'superseded_at' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'expired_at' => 'immutable_datetime',
    ];
}
