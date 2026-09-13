<?php

declare(strict_types=1);

namespace App\Http\Portal\OpenApi;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionMethod;

/**
 * Generates the producer portal's OpenAPI 3.1 document from the routes under /api/portal and the PortalOperation attribute on each controller
 * method (slice D9). `php artisan portal:openapi` writes docs/api/producer-portal.openapi.json; ProducerPortalTest fails when a route is not
 * documented or the file is out of date.
 */
final class PortalOpenApi
{
    public function __construct(private readonly Router $router) {}

    /** @return array{openapi: string, info: array<string, string>, servers: list<array<string, string>>, paths: array<string, array<string, mixed>>, components: array<string, mixed>, security: list<array<string, list<string>>>} */
    public function document(): array
    {
        $paths = [];
        $routes = array_filter($this->router->getRoutes()->getRoutes(), fn (Route $route): bool => str_starts_with($route->uri(), 'api/portal'));
        usort($routes, fn (Route $a, Route $b): int => [$a->uri(), $a->methods()[0]] <=> [$b->uri(), $b->methods()[0]]);
        foreach ($routes as $route) {
            $action = $route->getActionName();
            if (! str_contains($action, '@')) {
                continue;
            }
            [$class, $method] = explode('@', $action, 2);
            $attributes = (new ReflectionMethod($class, $method))->getAttributes(PortalOperation::class);
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
            'info' => ['title' => 'Insuryn producer portal', 'version' => '1.0.0',
                'description' => 'Read-only producer portal (my customers and policies, renewals due, collections to deposit, statements, licence, targets) plus recording cash collections. Every request names the tenant (X-Tenant header or tenant subdomain); tokens only work in the tenant that issued them.'],
            'servers' => [['url' => '/']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer', 'description' => 'Sanctum personal access token from POST /api/portal/tokens']],
                'parameters' => ['Tenant' => ['name' => 'X-Tenant', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string'], 'description' => 'Tenant id or slug, when not given by the subdomain']],
                'schemas' => PortalSchemas::all(),
            ],
            'security' => [['bearer' => []]],
        ];
    }

    /** @return array<string, mixed> */
    private function operation(Route $route, PortalOperation $operation, string $method): array
    {
        $parameters = [['$ref' => '#/components/parameters/Tenant']];
        foreach ($route->parameterNames() as $name) {
            $parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']];
        }
        foreach ($operation->query as $name => [$type, $description]) {
            $parameters[] = ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => ['type' => $type], 'description' => $description];
        }
        $error = ['description' => 'Refused', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]];
        $responses = [(string) $operation->status => $operation->response === ''
            ? ['description' => 'Done']
            : ['description' => 'OK', 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$operation->response}"]]]]];
        foreach ([...($operation->public ? [] : [401, 403]), ...$operation->errors] as $code) {
            $responses[(string) $code] = $error;
        }
        ksort($responses);

        return array_filter([
            'operationId' => $method,
            'summary' => $operation->summary,
            'tags' => ['Producer portal'],
            'parameters' => $parameters,
            'requestBody' => $operation->request === null ? null : ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => "#/components/schemas/{$operation->request}"]]]],
            'responses' => $responses,
            'security' => $operation->public ? [] : null,
            'x-token-ability' => $operation->ability,
        ], fn (mixed $value): bool => $value !== null);
    }
}
