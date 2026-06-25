<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Tests\Security;

use App\Modules\ForgeTesting\Attributes\BeforeEach;
use App\Modules\ForgeTesting\Attributes\Group;
use App\Modules\ForgeTesting\Attributes\Test;
use App\Modules\ForgeTesting\TestCase;
use App\Modules\ForgeWire\Security\Checksum;
use Forge\Core\Config\Config;
use Forge\Core\Session\SessionInterface;

#[Group("security")]
final class ChecksumTest extends TestCase
{
    private Checksum $checksum;
    private SessionInterface $session;

    #[BeforeEach]
    public function setUpChecksum(): void
    {
        $config = new Config(BASE_PATH . '/config');
        $this->checksum = new Checksum($config);
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

    #[Test("sign stores fingerprint with class and path")]
    public function sign_stores_fingerprint(): void
    {
        $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
        ]);

        $fp = $this->session->get('forgewire:counter:fp');
        $this->assertSame('App\\Controllers\\CounterController', $fp['class']);
        $this->assertSame('/counter', $fp['path']);
        $this->assertNotEmpty($this->session->get('forgewire:counter:sig'));
    }

    #[Test("verify rejects path mismatch")]
    public function verify_rejects_path_mismatch(): void
    {
        $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
        ]);

        $this->session->set('forgewire:counter', ['count' => 1]);

        $this->shouldFail(function () {
            $this->checksum->verify(null, 'forgewire:counter', $this->session, [
                'class' => 'App\\Controllers\\CounterController',
                'path' => '/admin',
            ]);
        }, \RuntimeException::class);
    }

    #[Test("verify rejects class mismatch")]
    public function verify_rejects_class_mismatch(): void
    {
        $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
        ]);

        $this->session->set('forgewire:counter', ['count' => 1]);

        $this->shouldFail(function () {
            $this->checksum->verify(null, 'forgewire:counter', $this->session, [
                'class' => 'App\\Controllers\\OtherController',
                'path' => '/counter',
            ]);
        }, \RuntimeException::class);
    }

    #[Test("verify rejects tampered checksum")]
    public function verify_rejects_tampered_checksum(): void
    {
        $sig = $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
        ]);

        $this->session->set('forgewire:counter', ['count' => 1]);

        $this->shouldFail(function () use ($sig) {
            $this->checksum->verify($sig . 'x', 'forgewire:counter', $this->session, [
                'class' => 'App\\Controllers\\CounterController',
                'path' => '/counter',
            ]);
        }, \RuntimeException::class);
    }

    #[Test("depends is included in checksum")]
    public function depends_is_included_in_checksum(): void
    {
        $sigWithoutDepends = $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
        ]);

        $this->session->remove('forgewire:counter:sig');
        $this->session->remove('forgewire:counter:fp');

        $sigWithDepends = $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
            'depends' => ['count'],
        ]);

        $this->assertNotEquals($sigWithoutDepends, $sigWithDepends);
    }

    #[Test("verify rejects changed depends")]
    public function verify_rejects_changed_depends(): void
    {
        $sig = $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
            'depends' => ['count'],
        ]);

        $this->session->set('forgewire:counter', ['count' => 1]);

        $this->shouldFail(function () use ($sig) {
            $this->checksum->verify($sig, 'forgewire:counter', $this->session, [
                'class' => 'App\\Controllers\\CounterController',
                'path' => '/counter',
                'depends' => ['user'],
            ]);
        }, \RuntimeException::class);
    }

    #[Test("verify allows one-time depends initialization")]
    public function verify_allows_one_time_depends_initialization(): void
    {
        $sig = $this->checksum->sign('forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
            'depends' => [],
        ]);

        $this->session->set('forgewire:counter', ['count' => 1]);

        $this->checksum->verify($sig, 'forgewire:counter', $this->session, [
            'class' => 'App\\Controllers\\CounterController',
            'path' => '/counter',
            'depends' => ['count'],
        ]);

        $fp = $this->session->get('forgewire:counter:fp');
        $this->assertSame(['count'], $fp['depends']);
    }

    #[Test("expected action signature is stored and verified")]
    public function expected_action_signature_is_verified(): void
    {
        $this->checksum->storeExpectedAction('forgewire:counter', $this->session, 'increment', []);

        $this->assertTrue(
            $this->checksum->isExpectedAction('forgewire:counter', $this->session, 'increment', [])
        );
        $this->assertFalse(
            $this->checksum->isExpectedAction('forgewire:counter', $this->session, 'delete', [])
        );
    }

    #[Test("expected action signature includes args")]
    public function expected_action_signature_includes_args(): void
    {
        $this->checksum->storeExpectedAction('forgewire:counter', $this->session, 'increment', ['step' => 1]);

        $this->assertFalse(
            $this->checksum->isExpectedAction('forgewire:counter', $this->session, 'increment', ['step' => 2])
        );
    }

    #[Test("different action names produce different signatures")]
    public function different_action_names_produce_different_signatures(): void
    {
        $this->checksum->storeExpectedAction('forgewire:counter', $this->session, 'increment', []);

        $this->assertFalse(
            $this->checksum->isExpectedAction('forgewire:counter', $this->session, 'decrement', [])
        );
    }
}
