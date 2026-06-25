<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Core;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Core\Html\HtmlTokenizer;
use App\Modules\ForgeWire\Core\WireKernel;
use App\Modules\ForgeWire\Security\Checksum;
use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-session")]
final class WireKernelSessionBatchingTest extends TestCase
{
    private WireKernel $kernel;
    private SessionInterface $session;
    private \ReflectionMethod $storeExpectedActions;
    private \ReflectionMethod $hasAnyExpectedActions;
    private \ReflectionMethod $sessionKeys;

    #[BeforeEach]
    public function setUpKernel(): void
    {
        $container = Container::getInstance();
        $hydrator = $container->get(\App\Modules\ForgeWire\Core\Hydrator::class);
        $config = new Config(BASE_PATH . '/config');
        $checksum = new Checksum($config);
        $this->kernel = new WireKernel($container, $hydrator, $checksum);

        $this->session = new class implements SessionInterface {
            private array $data = [];
            private string $id = 'test-session-id';
            public int $allCalls = 0;

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
            public function all(): array { $this->allCalls++; return $this->data; }
        };

        $this->storeExpectedActions = new \ReflectionMethod($this->kernel, 'storeExpectedActions');
        $this->storeExpectedActions->setAccessible(true);
        $this->hasAnyExpectedActions = new \ReflectionMethod($this->kernel, 'hasAnyExpectedActions');
        $this->hasAnyExpectedActions->setAccessible(true);
        $this->sessionKeys = new \ReflectionMethod($this->kernel, 'sessionKeys');
        $this->sessionKeys->setAccessible(true);
    }

    #[Test("storeExpectedActions uses a registry key instead of scanning all keys")]
    public function store_expected_actions_uses_registry(): void
    {
        $html = '<button fw:id="counter" fw:click="increment" fw:param-step="1">+</button>';
        $this->storeExpectedActions->invoke($this->kernel, $html, 'counter', $this->session, 'forgewire:counter');

        $registry = $this->session->get('forgewire:counter:actions:list', []);
        $this->assertCount(1, $registry);

        $signature = $registry[0];
        $this->assertTrue($this->session->has("forgewire:counter:actions:{$signature}"));

        $allCallsBefore = $this->session->allCalls;
        $hasActions = $this->hasAnyExpectedActions->invoke($this->kernel, 'forgewire:counter', $this->session);
        $this->assertTrue($hasActions);
        $this->assertSame($allCallsBefore, $this->session->allCalls, 'hasAnyExpectedActions should not scan all keys');
    }

    #[Test("storeExpectedActions clears old signatures when storing new ones")]
    public function store_expected_actions_clears_old_signatures(): void
    {
        $html = '<button fw:id="counter" fw:click="increment">+</button>';
        $this->storeExpectedActions->invoke($this->kernel, $html, 'counter', $this->session, 'forgewire:counter');

        $oldRegistry = $this->session->get('forgewire:counter:actions:list', []);
        $oldSignature = $oldRegistry[0];

        $html = '<button fw:id="counter" fw:click="decrement">-</button>';
        $this->storeExpectedActions->invoke($this->kernel, $html, 'counter', $this->session, 'forgewire:counter');

        $this->assertFalse($this->session->has("forgewire:counter:actions:{$oldSignature}"));
        $this->assertNotSame($oldRegistry, $this->session->get('forgewire:counter:actions:list', []));
    }

    #[Test("sessionKeys helper calls all() exactly once")]
    public function session_keys_helper_calls_all_once(): void
    {
        $this->session->set('forgewire:a', 1);
        $this->session->set('forgewire:b', 2);
        $this->session->set('other', 3);

        $callsBefore = $this->session->allCalls;
        $keys = $this->sessionKeys->invoke($this->kernel, $this->session);
        $callsAfter = $this->session->allCalls;

        $this->assertSame(3, count($keys));
        $this->assertSame($callsBefore + 1, $callsAfter);
    }

    #[Test("sessionKeys helper returns empty array for empty session")]
    public function session_keys_helper_returns_empty_for_empty_session(): void
    {
        $keys = $this->sessionKeys->invoke($this->kernel, $this->session);
        $this->assertSame([], $keys);
    }
}
