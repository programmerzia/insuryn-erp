<?php

declare(strict_types=1);

namespace App\Modules\Platform\Notifications;

/**
 * One message to one recipient about one business object. The idempotency key makes a message go out at most once per channel (for example
 * `renewal_notice:<policy>:45`). The recipient is named by its record (a party) because parties hold no email address or phone number yet (A-131 gap):
 * a gateway adapter will need the address, which is null until then.
 */
final readonly class OutgoingNotification
{
    public function __construct(
        public string $recipientType,
        public ?string $recipientId,
        public string $recipientName,
        public ?string $recipientAddress,
        public string $subjectType,
        public string $subjectId,
        public string $title,
        public string $body,
        public string $idempotencyKey,
        public ?string $templateCode = null,
        public ?string $storedDocumentId = null,
    ) {}
}
