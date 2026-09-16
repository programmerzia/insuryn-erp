<?php

declare(strict_types=1);

namespace App\Http\Ledger\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionMethod;

/** Generates docs/api/ledger-v1.openapi.json from /api/v1 routes and LedgerOperation attributes. */
final class LedgerOpenApi
{
    public function __construct(private readonly Router $router) {}

    /** @return array<string, mixed> */
    public function document(): array
    {
        $paths = [];
        $routes = array_filter($this->router->getRoutes()->getRoutes(), fn (Route $route): bool => str_starts_with($route->uri(), 'api/v1'));
        usort($routes, fn (Route $a, Route $b): int => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);
        foreach ($routes as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $attributes = (new ReflectionMethod($class, $method))->getAttributes(LedgerOperation::class);
            if ($attributes === []) {
                continue;
            }
            $operation = $attributes[0]->newInstance();
            foreach (array_diff($route->methods(), ['HEAD']) as $httpMethod) {
                $paths['/'.$route->uri()][strtolower($httpMethod)] = $this->operation($route, $operation, $method);
            }
        }
        ksort($paths);

        return [
            'openapi' => '3.1.0',
            'info' => ['title' => 'Insuryn Ledger API', 'version' => '1.0.0',
                'description' => 'External accounting event ingest and read-only ledger queries. Integration users (kind=integration) obtain Bearer tokens from POST /api/v1/tokens. Amounts are in minor units.'],
            'servers' => [['url' => '/']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum token from POST /api/v1/tokens']],
                'parameters' => ['Tenant' => ['name' => 'X-Tenant', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string']]],
                'schemas' => LedgerSchemas::all(),
            ],
            'security' => [['bearer' => []]],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, LedgerOperation $operation, string $method): array
    {
        $parameters = [['$ref' => '#/components/parameters/Tenant']];
        foreach ($route->parameterNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        }
        foreach ($operation->query as $name => [$type, $description]) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => $name === 'account', 'schema' => ['type' => $type], 'description' => $description];
        }
        $error = ['description' => 'Refused', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $responses = [(string) $operation->status => $operation->response === ''
            ? ['description' => 'Done']
            : ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$operation->response}"]]]]];
        foreach ([...($operation->public ? [] : [401, 403]), ...$operation->errors] as $code) {
            $responses[(string) $code] = $error;
        }
        ksort($responses);
        $body = $operation->request === null ? [] : ['requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$operation->request}"]]]]];

        return array_filter([
            'operationId' => $method,
            'summary' => $operation->summary,
            'tags' => ['Ledger'],
            'parameters' => $parameters,
            ...$body,
            'responses' => $responses,
            'security' => $operation->public ? [] : [['bearer' => []]],
        ]);
    }
}
