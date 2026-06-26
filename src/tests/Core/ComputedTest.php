<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Core;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Attributes\Computed;
use App\Modules\ForgeWire\Attributes\Reactive;
use App\Modules\ForgeWire\Attributes\State;
use App\Modules\ForgeWire\Core\Hydrator;
use App\Modules\ForgeWire\Traits\WithWireResponse;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-computed")]
final class ComputedTest extends TestCase
{
    private Hydrator $hydrator;
    private SessionInterface $session;

    #[BeforeEach]
    public function setUp(): void
    {
        $container = Container::getInstance();
        $this->hydrator = $container->get(Hydrator::class);
        $this->session = new class implements SessionInterface {
            private array $data = [];
            private string $id = 'test-session-id';
            public function start(): void {}
            public function save(): void {}
            public function getId(): string { return $this->id; }
            public function has(string $key): bool { return array_key_exists($key, $this->data); }
            public function get(string $key, mixed $default = null): mixed { return $this->data[$key] ?? $default; }
            public function set(string $key, mixed $value): void { $this->data[$key] = $value; }
            public function remove(string $key): void { unset($this->data[$key]); }
            public function clear(): void { $this->data = []; }
            public function regenerate(bool $deleteOldSession = true): void {}
            public function isStarted(): bool { return true; }
            public function all(): array { return $this->data; }
        };
    }

    protected function callCacheComputed(object $instance, string $method, callable $callback): mixed
    {
        $rm = new \ReflectionMethod($instance, 'cacheComputed');
        $rm->setAccessible(true);
        return $rm->invoke($instance, $method, $callback);
    }

    protected function callClearComputedCache(object $instance): void
    {
        $rm = new \ReflectionMethod($instance, 'clearComputedCache');
        $rm->setAccessible(true);
        $rm->invoke($instance);
    }

    protected function setFwId(object $instance, string $id): void
    {
        $rp = new \ReflectionProperty($instance, '__fw_id');
        $rp->setAccessible(true);
        $rp->setValue($instance, $id);
    }

    #[Test("cacheComputed returns cached value on repeated calls")]
    public function cache_computed_returns_cached_value(): void
    {
        $instance = new ComputedTestController;
        $callCount = 0;

        $result1 = $this->callCacheComputed($instance, 'testMethod', function () use (&$callCount) {
            $callCount++;
            return 42;
        });

        $result2 = $this->callCacheComputed($instance, 'testMethod', function () use (&$callCount) {
            $callCount++;
            return 999;
        });

        $this->assertSame(42, $result1);
        $this->assertSame(42, $result2);
        $this->assertSame(1, $callCount);
    }

    #[Test("clearComputedCache resets the cache")]
    public function clear_computed_cache_resets(): void
    {
        $instance = new ComputedTestController;
        $callCount = 0;

        $this->callCacheComputed($instance, 'testMethod', function () use (&$callCount) {
            $callCount++;
            return 42;
        });

        $this->callClearComputedCache($instance);

        $result = $this->callCacheComputed($instance, 'testMethod', function () use (&$callCount) {
            $callCount++;
            return 100;
        });

        $this->assertSame(100, $result);
        $this->assertSame(2, $callCount);
    }

    #[Test("hydrate clears computed cache when dirty state changes")]
    public function hydrate_clears_computed_cache(): void
    {
        $instance = new ComputedReactiveController;
        $this->setFwId($instance, 'computed-test-id');
        $instance->value = 5;

        $sessionKey = 'forgewire:computed-test-id';
        $sharedKey = 'forgewire:shared:' . ComputedReactiveController::class;

        $this->session->set($sessionKey, ['value' => 5]);
        $this->session->set("forgewire:computed-test-id:class", ComputedReactiveController::class);
        $this->session->set("forgewire:computed-test-id:uses", ['value']);

        $callCount = 0;
        $cached = $this->callCacheComputed($instance, 'expensive', function () use (&$callCount) {
            $callCount++;
            return $callCount;
        });

        $this->assertSame(1, $cached);
        $this->assertSame(1, $callCount);

        $this->hydrator->hydrate(
            $instance,
            ['value' => 10],
            $this->session,
            $sessionKey,
            $sharedKey
        );

        $recomputed = $this->callCacheComputed($instance, 'expensive', function () use (&$callCount) {
            $callCount++;
            return $callCount;
        });

        $this->assertSame(2, $recomputed);
        $this->assertSame(2, $callCount);
    }

    #[Test("hydrate preserves computed cache when no dirty state")]
    public function hydrate_preserves_cache_without_dirty(): void
    {
        $instance = new ComputedReactiveController;
        $this->setFwId($instance, 'computed-test-id-2');
        $instance->value = 5;

        $sessionKey = 'forgewire:computed-test-id-2';
        $sharedKey = 'forgewire:shared:' . ComputedReactiveController::class;

        $this->session->set($sessionKey, ['value' => 5]);
        $this->session->set("forgewire:computed-test-id-2:class", ComputedReactiveController::class);
        $this->session->set("forgewire:computed-test-id-2:uses", ['value']);

        $callCount = 0;
        $this->callCacheComputed($instance, 'expensive', function () use (&$callCount) {
            $callCount++;
            return $callCount;
        });

        $this->hydrator->hydrate(
            $instance,
            [],
            $this->session,
            $sessionKey,
            $sharedKey
        );

        $cached = $this->callCacheComputed($instance, 'expensive', function () use (&$callCount) {
            $callCount++;
            return $callCount;
        });

        $this->assertSame(1, $cached);
        $this->assertSame(1, $callCount);
    }

    #[Test("buildRecipe detects #[Computed] methods")]
    public function build_recipe_detects_computed_methods(): void
    {
        $recipe = Hydrator::getRecipe(ComputedReactiveController::class);
        $this->assertArrayHasKey('__computed__', $recipe);
        $this->assertContains('expensive', $recipe['__computed__']);
    }
}

#[Reactive]
final class ComputedTestController
{
    use WithWireResponse;
}

#[Reactive]
final class ComputedReactiveController
{
    use WithWireResponse;

    #[State]
    public int $value = 0;

    #[Computed]
    public function expensive(): int
    {
        return $this->cacheComputed(__FUNCTION__, fn() => $this->value * 2);
    }
}
