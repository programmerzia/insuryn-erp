<?php

declare(strict_types=1);

namespace App\Modules\Platform\Approvals;

use Illuminate\Contracts\Container\Container;

/** object type → handler class, registered by the owning module's service provider. */
final class ApprovalHandlerRegistry
{
    /** @var array<string, class-string<ApprovalHandler>> */
    private array $handlers = [];

    public function __construct(private readonly Container $container) {}

    /** @param class-string<ApprovalHandler> $handlerClass */
    public function register(string $objectType, string $handlerClass): void
    {
        $this->handlers[$objectType] = $handlerClass;
    }

    public function for(string $objectType): ApprovalHandler
    {
        $class = $this->handlers[$objectType] ?? throw new ApprovalException('NO_HANDLER', "No approval handler is registered for {$objectType}.");

        return $this->container->make($class);
    }
}
