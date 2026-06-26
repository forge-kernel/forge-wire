<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Core;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Attributes\Reactive;
use App\Modules\ForgeWire\Attributes\State;
use App\Modules\ForgeWire\Core\WireKernel;
use App\Modules\ForgeWire\Security\Checksum;
use App\Modules\ForgeWire\Services\ActionDispatcher;
use App\Modules\ForgeWire\Services\ComponentCleanupService;
use App\Modules\ForgeWire\Services\ComponentRegistry;
use App\Modules\ForgeWire\Services\DependencyTracker;
use App\Modules\ForgeWire\Services\SharedStateManager;
use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-target")]
final class TargetRenderingTest extends TestCase
{
    private WireKernel $kernel;
    private SessionInterface $session;
    private \ReflectionMethod $renderAffectedComponent;

    #[BeforeEach]
    public function setUpKernel(): void
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
        $hydrator = $container->get(\App\Modules\ForgeWire\Core\Hydrator::class);
        $config = new Config(BASE_PATH . '/config');
        $checksum = new Checksum($config);
        $registry = new ComponentRegistry($this->session);
        $cleanupService = new ComponentCleanupService($config, $registry);
        $sharedStateManager = new SharedStateManager($cleanupService, $registry);
        $actionDispatcher = new ActionDispatcher($checksum);
        $dependencyTracker = new DependencyTracker($container, $hydrator, $actionDispatcher, $registry);
        $this->kernel = new WireKernel($container, $hydrator, $checksum, $cleanupService, $actionDispatcher, $sharedStateManager, $dependencyTracker, $registry);

        $this->renderAffectedComponent = new \ReflectionMethod($this->kernel, 'renderAffectedComponent');
        $this->renderAffectedComponent->setAccessible(true);
    }

    #[Test("renderAffectedComponent returns only fw:target fragments")]
    public function returns_only_target_fragments(): void
    {
        $this->seedComponentSession('counter-target');

        $result = $this->renderAffectedComponent->invoke(
            $this->kernel,
            'counter-target',
            TargetTestController::class,
            $this->createRequest(),
            $this->session,
            'forgewire:shared:' . TargetTestController::class
        );

        $this->assertNotNull($result);
        $this->assertSame('counter-target', $result['id']);
        $this->assertStringContainsString('fw:target', $result['html']);
        $this->assertStringNotContainsString('should-not-appear', $result['html']);
        $this->assertStringContainsString('target-value', $result['html']);
    }

    #[Test("renderAffectedComponent returns full component when no fw:target exists")]
    public function returns_full_component_without_targets(): void
    {
        $this->seedComponentSession('counter-notarget');

        $result = $this->renderAffectedComponent->invoke(
            $this->kernel,
            'counter-notarget',
            NoTargetTestController::class,
            $this->createRequest(),
            $this->session,
            'forgewire:shared:' . NoTargetTestController::class
        );

        $this->assertNotNull($result);
        $this->assertSame('counter-notarget', $result['id']);
        $this->assertStringContainsString('full-component', $result['html']);
    }

    private function seedComponentSession(string $id): void
    {
        $this->session->set("forgewire:{$id}", ['count' => 0]);
        $this->session->set("forgewire:{$id}:class", TargetTestController::class);
        $this->session->set("forgewire:{$id}:action", 'index');
        $this->session->set("forgewire:{$id}:uses", ['count']);
    }

    private function createRequest(): \App\Modules\ForgeRouter\Http\Request
    {
        return new \App\Modules\ForgeRouter\Http\Request(
            queryParams: [],
            postData: [],
            serverParams: ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/'],
            requestMethod: 'GET',
            cookies: []
        );
    }
}

#[Reactive]
final class TargetTestController
{
    use \App\Modules\ForgeWire\Traits\WithWireResponse;

    #[State]
    public int $count = 0;

    public function render(): string
    {
        return '<div fw:id="counter-target"><span class="should-not-appear">Hidden</span><span fw:target>target-value ' . $this->count . '</span></div>';
    }
}

#[Reactive]
final class NoTargetTestController
{
    use \App\Modules\ForgeWire\Traits\WithWireResponse;

    #[State]
    public int $count = 0;

    public function render(): string
    {
        return '<div fw:id="counter-notarget"><span class="full-component">Full ' . $this->count . '</span></div>';
    }
}
