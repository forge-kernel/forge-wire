<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Core;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Attributes\Action;
use App\Modules\ForgeWire\Attributes\Reactive;
use App\Modules\ForgeWire\Attributes\State;
use App\Modules\ForgeWire\Attributes\Validate;
use App\Modules\ForgeWire\Core\Hydrator;
use App\Modules\ForgeWire\Core\WireKernel;
use App\Modules\ForgeWire\Security\Checksum;
use App\Modules\ForgeWire\Services\ActionDispatcher;
use App\Modules\ForgeWire\Services\ComponentCleanupService;
use App\Modules\ForgeWire\Services\ComponentRegistry;
use App\Modules\ForgeWire\Services\DependencyTracker;
use App\Modules\ForgeWire\Services\SharedStateManager;
use App\Modules\ForgeWire\Traits\WithWireResponse;
use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;

#[Group("forgewire-validation")]
final class ValidationTest extends TestCase
{
    private WireKernel $kernel;
    private SessionInterface $session;
    private \ReflectionMethod $validateMethod;

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
        $hydrator = $container->get(Hydrator::class);
        $config = new Config(BASE_PATH . '/config');
        $checksum = new Checksum($config);
        $registry = new ComponentRegistry($this->session);
        $cleanupService = new ComponentCleanupService($config, $registry);
        $sharedStateManager = new SharedStateManager($cleanupService, $registry);
        $actionDispatcher = new ActionDispatcher($checksum);
        $dependencyTracker = new DependencyTracker($container, $hydrator, $actionDispatcher, $registry);
        $this->kernel = new WireKernel($container, $hydrator, $checksum, $cleanupService, $actionDispatcher, $sharedStateManager, $dependencyTracker, $registry);

        $this->validateMethod = new \ReflectionMethod($this->kernel, 'validateReactiveState');
        $this->validateMethod->setAccessible(true);
    }

    #[Test("validate with array rules accepts valid data")]
    public function validate_array_rules_passes(): void
    {
        $instance = new ArrayRulesController;
        $instance->name = 'John Doe';
        $instance->email = 'john@example.com';

        $errors = $this->validateMethod->invoke(
            $this->kernel,
            $instance,
            ['name' => 'John Doe', 'email' => 'john@example.com'],
            ArrayRulesController::class,
            true,
            'validation-test',
            $this->session
        );

        $this->assertSame([], $errors);
    }

    #[Test("validate with array rules rejects invalid data")]
    public function validate_array_rules_fails(): void
    {
        $instance = new ArrayRulesController;
        $instance->name = '';
        $instance->email = 'not-an-email';

        $errors = $this->validateMethod->invoke(
            $this->kernel,
            $instance,
            ['name' => '', 'email' => 'not-an-email'],
            ArrayRulesController::class,
            true,
            'validation-test',
            $this->session
        );

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('name', $errors);
    }

    #[Test("validate with string rules (pipe-separated) accepts valid data")]
    public function validate_string_rules_passes(): void
    {
        $instance = new StringRulesController;
        $instance->name = 'John Doe';

        $errors = $this->validateMethod->invoke(
            $this->kernel,
            $instance,
            ['name' => 'John Doe'],
            StringRulesController::class,
            true,
            'validation-test',
            $this->session
        );

        $this->assertSame([], $errors);
    }

    #[Test("validate with string rules rejects invalid data")]
    public function validate_string_rules_fails(): void
    {
        $instance = new StringRulesController;
        $instance->name = 'ab';

        $errors = $this->validateMethod->invoke(
            $this->kernel,
            $instance,
            ['name' => 'ab'],
            StringRulesController::class,
            true,
            'validation-test',
            $this->session
        );

        $this->assertNotEmpty($errors);
        $this->assertArrayHasKey('name', $errors);
    }
}

#[Reactive]
final class ArrayRulesController
{
    use WithWireResponse;

    #[State]
    #[Validate(rules: ['required', 'min:3'])]
    public string $name = '';

    #[State]
    #[Validate(rules: ['required', 'email'])]
    public string $email = '';

    public function render(): string
    {
        return '<div fw:id="validation-test">OK</div>';
    }
}

#[Reactive]
final class StringRulesController
{
    use WithWireResponse;

    #[State]
    #[Validate(rules: "required|min:3")]
    public string $name = '';

    public function render(): string
    {
        return '<div fw:id="validation-test">OK</div>';
    }
}
