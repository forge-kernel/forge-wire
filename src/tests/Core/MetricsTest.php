<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Tests\Core;

use Modules\ForgeTesting\Attributes\BeforeEach;
use Modules\ForgeTesting\Attributes\Group;
use Modules\ForgeTesting\Attributes\Test;
use Modules\ForgeTesting\TestCase;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeWire\Attributes\Action;
use Modules\ForgeWire\Attributes\Reactive;
use Modules\ForgeWire\Attributes\State;
use Modules\ForgeWire\Core\WireKernel;
use Modules\ForgeWire\Security\Checksum;
use Modules\ForgeWire\Services\ActionDispatcher;
use Modules\ForgeWire\Services\ComponentCleanupService;
use Modules\ForgeWire\Services\ComponentRegistry;
use Modules\ForgeWire\Services\DependencyTracker;
use Modules\ForgeWire\Services\SharedStateManager;
use Forge\Core\Config\Config;
use Forge\Core\Debug\Metrics;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-metrics")]
final class MetricsTest extends TestCase
{
    private WireKernel $kernel;
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

        $container = Container::getInstance();
        $hydrator = $container->get(\Modules\ForgeWire\Core\Hydrator::class);
        $config = new Config(BASE_PATH . '/config');
        $checksum = new Checksum($config);
        $registry = new ComponentRegistry($this->session);
        $cleanupService = new ComponentCleanupService($config, $registry);
        $sharedStateManager = new SharedStateManager($cleanupService, $registry);
        $actionDispatcher = new ActionDispatcher($checksum);
        $dependencyTracker = new DependencyTracker($container, $hydrator, $actionDispatcher, $registry);
        $this->kernel = new WireKernel($container, $hydrator, $checksum, $cleanupService, $actionDispatcher, $sharedStateManager, $dependencyTracker, $registry);

        $this->resetMetrics();
        $_ENV['APP_METRICS_ENABLED'] = true;
    }

    #[Test("wire request records kernel and render metrics")]
    public function records_kernel_and_render_metrics(): void
    {
        $componentId = 'metrics-counter';
        $this->session->set("forgewire:{$componentId}", ['count' => 1]);
        $this->session->set("forgewire:{$componentId}:class", MetricsTestController::class);
        $this->session->set("forgewire:{$componentId}:action", 'index');
        $this->session->set("forgewire:{$componentId}:uses", ['count']);

        $payload = [
            'id' => $componentId,
            'controller' => MetricsTestController::class,
            'action' => 'increment',
            'args' => [],
            'dirty' => [],
            'depends' => ['count'],
            'fingerprint' => ['path' => '/'],
            'checksum' => null,
        ];

        $this->kernel->process($payload, $this->createRequest(), $this->session);

        $metrics = Metrics::get();
        $this->assertArrayHasKey('forgewire_kernel', $metrics);
        $this->assertArrayHasKey('forgewire_render', $metrics);
        $this->assertGreaterThan(0, $metrics['forgewire_kernel']['duration']);
        $this->assertGreaterThanOrEqual(0, $metrics['forgewire_render']['duration']);
    }

    #[Test("metrics are empty when disabled")]
    public function metrics_empty_when_disabled(): void
    {
        $_ENV['APP_METRICS_ENABLED'] = false;
        $this->resetMetrics();

        $componentId = 'metrics-counter-disabled';
        $this->session->set("forgewire:{$componentId}", ['count' => 1]);
        $this->session->set("forgewire:{$componentId}:class", MetricsTestController::class);
        $this->session->set("forgewire:{$componentId}:action", 'index');
        $this->session->set("forgewire:{$componentId}:uses", ['count']);

        $payload = [
            'id' => $componentId,
            'controller' => MetricsTestController::class,
            'action' => 'increment',
            'args' => [],
            'dirty' => [],
            'depends' => ['count'],
            'fingerprint' => ['path' => '/'],
            'checksum' => null,
        ];

        $this->kernel->process($payload, $this->createRequest(), $this->session);

        $this->assertSame([], Metrics::get());
    }

    private function resetMetrics(): void
    {
        $reflection = new \ReflectionClass(Metrics::class);
        $enabled = $reflection->getProperty('enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, null);
        $timers = $reflection->getProperty('timers');
        $timers->setAccessible(true);
        $timers->setValue(null, []);
    }

    private function createRequest(): Request
    {
        return new Request(
            queryParams: [],
            postData: [],
            serverParams: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            requestMethod: 'GET',
            cookies: []
        );
    }
}

#[Reactive]
final class MetricsTestController
{
    use \Modules\ForgeWire\Traits\WithWireResponse;

    #[State]
    public int $count = 0;

    #[Action]
    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div fw:id="metrics-counter"><span>' . $this->count . '</span></div>';
    }
}
