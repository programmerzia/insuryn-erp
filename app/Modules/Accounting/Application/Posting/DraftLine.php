<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Application\Posting;

use App\Modules\Accounting\Domain\Enums\Side;
use App\Modules\Accounting\Domain\Models\JournalLine;
use InvalidArgumentException;

/** One line of a journal that is not yet written. Amounts are positive minor units; the side carries the sign. */
final readonly class DraftLine
{
    public function __construct(
        public int $lineNo,
        public string $accountId,
        public Side $side,
        public int $amountMinor,
        public string $currency,
        public int $baseAmountMinor,
        public ?string $roleCode,
        public ?string $memo,
        public LineDimensions $dimensions,
    ) {
        if ($amountMinor <= 0 || $baseAmountMinor <= 0) {
            throw new InvalidArgumentException("Draft line {$lineNo} must carry a positive amount; the side carries the sign.");
        }
    }

    public static function fromStoredLine(JournalLine $line): self
    {
        return new self(
            lineNo: (int) $line->line_no,
            accountId: (string) $line->account_id,
            side: $line->side,
            amountMinor: $line->amount_minor,
            currency: (string) $line->currency,
            baseAmountMinor: $line->base_amount_minor,
            roleCode: $line->role_code === null ? null : (string) $line->role_code,
            memo: $line->memo === null ? null : (string) $line->memo,
            dimensions: LineDimensions::fromStoredLine($line),
        );
    }

    /** The same line on the opposite side (design §2.3 reversal strategy "mirror"). */
    public function mirrored(): self
    {
        return new self($this->lineNo, $this->accountId, $this->side->opposite(), $this->amountMinor, $this->currency,
            $this->baseAmountMinor, $this->roleCode, $this->memo, $this->dimensions);
    }
}
