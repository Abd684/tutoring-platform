<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

class ApiRouteIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_api_controller_actions_and_implicit_bindings_are_valid(): void
    {
        $names = [];
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/')) {
                continue;
            }
            if ($name = $route->getName()) {
                $this->assertNotContains($name, $names, "Duplicate route name: $name");
                $names[] = $name;
            }
            if (! str_contains($route->getActionName(), '@')) {
                continue;
            }
            [$controller, $action] = explode('@', $route->getActionName());
            $this->assertTrue(method_exists($controller, $action), $route->uri());
            $method = new ReflectionMethod($controller, $action);
            $this->assertTrue($method->isPublic(), $route->uri());
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();
                if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                    $this->assertContains($parameter->getName(), $route->parameterNames(), $route->uri());
                }
            }
        }
    }

    public function test_every_authenticated_api_route_rejects_guests(): void
    {
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/') || ! in_array('auth:sanctum', $route->gatherMiddleware(), true)) {
                continue;
            }
            $url = '/'.preg_replace('/\{[^}]+\}/', '999999', str_replace('{decision}', 'approve', $route->uri()));
            $this->assertSame(401, $this->json($route->methods()[0], $url)->status(), $url);
        }
    }

    public function test_role_protected_api_routes_reject_other_roles(): void
    {
        foreach (['student', 'teacher', 'company_admin'] as $role) {
            Sanctum::actingAs(User::factory()->create(['role' => $role]));
            foreach (Route::getRoutes() as $route) {
                if (! str_starts_with($route->uri(), 'api/')) {
                    continue;
                }
                foreach ($route->gatherMiddleware() as $middleware) {
                    if (! str_starts_with($middleware, 'role:') || in_array($role, explode(',', substr($middleware, 5)), true)) {
                        continue;
                    }
                    $url = '/'.preg_replace('/\{[^}]+\}/', '999999', str_replace('{decision}', 'approve', $route->uri()));
                    // Missing bound records may be hidden before role middleware runs.
                    $response = $this->json($route->methods()[0], $url);
                    $this->assertContains($response->status(), [403, 404], "$role {$route->uri()}");
                }
            }
        }
    }
}
