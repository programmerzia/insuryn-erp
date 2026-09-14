<?php

declare(strict_types=1);

namespace Tests\Feature\Claims;

/** One operation ClaimReservePropertyTest drives against a claim; only the fields its kind uses are set. */
final readonly class ClaimOperation
{
    public const KINDS = ['reserve', 'approve', 'decide_payment', 'request_release', 'release', 'decide_release', 'close', 'reject', 'reopen', 'decide_reopen', 'recover'];

    public function __construct(
        public string $kind,
        public int $amount = 0,
        public string $payment = '',
        public bool $approve = false,
        public string $reason = '',
        public string $type = '',
    ) {}

    public function describe(): string
    {
        $parts = array_filter([
            'amount' => in_array($this->kind, ['reserve', 'approve', 'recover'], true) ? (string) $this->amount : null,
            'payment' => $this->payment !== '' ? $this->payment : null,
            'approve' => str_starts_with($this->kind, 'decide_') ? ($this->approve ? 'yes' : 'no') : null,
            'reason' => in_array($this->kind, ['close', 'reject', 'reopen'], true) ? "'{$this->reason}'" : null,
            'type' => $this->kind === 'recover' ? $this->type : null,
        ], fn (?string $value): bool => $value !== null);

        return $this->kind.'('.implode(', ', array_map(fn (string $key, string $value): string => "{$key}={$value}", array_keys($parts), $parts)).')';
    }
}
