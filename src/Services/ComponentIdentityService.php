<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Services;

use Modules\ForgeWire\Attributes\Reactive;
use Modules\ForgeWire\Security\Checksum;
use Forge\Core\Session\SessionInterface;

final class ComponentIdentityService
{
    /**
     * @var array<string, bool>
     */
    private static array $reflectionCache = [];

    public function __construct(
        private SessionInterface $session,
        private Checksum $checksum,
        private ComponentRegistry $registry
    ) {
    }

    /**
     * Set up session keys and sign a checksum for the given component.
     * Returns the checksum signature string, or empty string if the controller
     * is not reactive.
     */
    public function getFingerprint(
        string $id,
        string $controllerClass,
        string $currentPath,
        ?string $method = 'index',
        array $uses = []
    ): string {
        if (!$this->isReactive($controllerClass)) {
            return '';
        }

        $sharedKey = "forgewire:shared:{$controllerClass}";
        $this->session->remove($sharedKey);
        $this->session->remove("forgewire:{$id}");
        $this->session->remove("forgewire:{$id}:models");
        $this->session->remove("forgewire:{$id}:dtos");

        $this->session->set("forgewire:{$id}:class", $controllerClass);
        $this->session->set("forgewire:{$id}:action", $method ?? 'index');

        $this->registry->register($id, $controllerClass);

        return $this->checksum->sign("forgewire:{$id}", $this->session, [
            'class' => $controllerClass,
            'path' => $currentPath,
            'depends' => $uses,
        ]);
    }

    public function isReactive(string $class): bool
    {
        return $this->isReactiveInternal($class);
    }

    /**
     * Check if a controller is ForgeWire compatible using reflection and static caching.
     */
    private function isReactiveInternal(string $class): bool
    {
        if (isset(self::$reflectionCache[$class])) {
            return self::$reflectionCache[$class];
        }

        try {
            if (!class_exists($class)) {
                return self::$reflectionCache[$class] = false;
            }

            $refl = new \ReflectionClass($class);
            $attributes = $refl->getAttributes(Reactive::class);

            return self::$reflectionCache[$class] = !empty($attributes);
        } catch (\Throwable) {
            return self::$reflectionCache[$class] = false;
        }
    }
}
