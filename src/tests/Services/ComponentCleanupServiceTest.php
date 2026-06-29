<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Tests\Services;

use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Modules\ForgeWire\Services\ComponentCleanupService;
use Modules\ForgeWire\Services\ComponentRegistry;
use Forge\Core\Config\Config;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-cleanup")]
final class ComponentCleanupServiceTest extends TestCase
{
    private ComponentCleanupService $cleanupService;
    private SessionInterface $session;

    #[BeforeEach]
    public function setUp(): void
    {
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

        $config = new Config(BASE_PATH . '/config');
        $registry = new ComponentRegistry($this->session);
        $this->cleanupService = new ComponentCleanupService($config, $registry);
    }

    #[Test("remove deletes all component session keys")]
    public function remove_deletes_component_keys(): void
    {
        $this->session->set('forgewire:counter', ['count' => 1]);
        $this->session->set('forgewire:counter:class', 'App\\Counter');
        $this->session->set('forgewire:counter:action', 'index');
        $this->session->set('forgewire:counter:uses', ['count']);
        $this->session->set('forgewire:active:counter', time());

        $this->cleanupService->remove('counter', $this->session);

        $this->assertFalse($this->session->has('forgewire:counter'));
        $this->assertFalse($this->session->has('forgewire:counter:class'));
        $this->assertFalse($this->session->has('forgewire:counter:action'));
        $this->assertFalse($this->session->has('forgewire:counter:uses'));
        $this->assertFalse($this->session->has('forgewire:active:counter'));
    }

    #[Test("isStale returns true when component exceeds TTL")]
    public function is_stale_returns_true_when_expired(): void
    {
        $this->session->set('forgewire:active:counter', time() - 3600);

        $this->assertTrue($this->cleanupService->isStale('counter', $this->session));
    }

    #[Test("isStale returns false when component is within TTL")]
    public function is_stale_returns_false_when_fresh(): void
    {
        $this->session->set('forgewire:active:counter', time());

        $this->assertFalse($this->cleanupService->isStale('counter', $this->session));
    }

    #[Test("removeStale removes only stale components")]
    public function remove_stale_removes_only_stale(): void
    {
        $registry = new ComponentRegistry($this->session);
        $this->cleanupService = new ComponentCleanupService(new Config(BASE_PATH . '/config'), $registry);

        $registry->register('stale', 'App\\Stale', 'index');
        $registry->register('fresh', 'App\\Fresh', 'index');
        $this->session->set('forgewire:active:stale', time() - 3600);
        $this->session->set('forgewire:active:fresh', time());

        $removed = $this->cleanupService->removeStale($this->session);

        $this->assertSame(1, $removed);
        $this->assertFalse($this->session->has('forgewire:active:stale'));
        $this->assertTrue($this->session->has('forgewire:active:fresh'));
    }

    #[Test("remove removes component from shared group")]
    public function remove_cleans_shared_group(): void
    {
        $class = 'App\\Counter';
        $this->session->set('forgewire:counter:class', $class);
        $this->session->set("forgewire:shared-group:{$class}:components", ['counter', 'other']);
        $this->session->set('forgewire:active:counter', time());

        $this->cleanupService->remove('counter', $this->session);

        $group = $this->session->get("forgewire:shared-group:{$class}:components");
        $this->assertSame(['other'], $group);
    }
}
