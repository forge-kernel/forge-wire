<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Services;

use Forge\Core\DI\Attributes\Service;
use Forge\Core\Session\SessionInterface;

#[Service]
final class ComponentRegistry
{
    private const string REGISTRY_KEY = 'forgewire:registry';

    private const string COMPONENT_IDS_KEY = 'ids';
    private const string COMPONENT_CLASSES_KEY = 'byClass';
    private const string COMPONENT_PATHS_KEY = 'byPath';
    private const string COMPONENT_USES_KEY = 'uses';

    public function __construct(
        private SessionInterface $session
    ) {
    }

    public function register(
        string $componentId,
        ?string $controllerClass = null,
        ?string $action = null,
        ?string $path = null
    ): void {
        $registry = $this->getOrCreateRegistry();

        if (isset($registry[self::COMPONENT_IDS_KEY][$componentId])) {
            if ($controllerClass !== null) {
                $registry[self::COMPONENT_IDS_KEY][$componentId]['class'] = $controllerClass;
            }
            if ($action !== null) {
                $registry[self::COMPONENT_IDS_KEY][$componentId]['action'] = $action;
            }
            if ($path !== null) {
                $registry[self::COMPONENT_IDS_KEY][$componentId]['path'] = $path;
            }
        } else {
            $registry[self::COMPONENT_IDS_KEY][$componentId] = [
                'class' => $controllerClass,
                'action' => $action,
                'path' => $path,
                self::COMPONENT_USES_KEY => null,
                'registeredAt' => time(),
            ];
        }

        if ($controllerClass !== null) {
            $registry[self::COMPONENT_CLASSES_KEY][$controllerClass] ??= [];
            if (!in_array($componentId, $registry[self::COMPONENT_CLASSES_KEY][$controllerClass], true)) {
                $registry[self::COMPONENT_CLASSES_KEY][$controllerClass][] = $componentId;
            }
        }

        if ($path !== null) {
            $registry[self::COMPONENT_PATHS_KEY][$path] ??= [];
            if (!in_array($componentId, $registry[self::COMPONENT_PATHS_KEY][$path], true)) {
                $registry[self::COMPONENT_PATHS_KEY][$path][] = $componentId;
            }
        }

        $this->session->set(self::REGISTRY_KEY, $registry);
    }

    public function unregister(string $componentId, ?string $controllerClass = null): void
    {
        $registry = $this->getRegistry();
        if ($registry === null) {
            return;
        }

        $componentData = $registry[self::COMPONENT_IDS_KEY][$componentId] ?? null;
        if ($componentData === null) {
            return;
        }

        $class = $controllerClass ?? $componentData['class'] ?? null;
        $path = $componentData['path'] ?? null;

        unset($registry[self::COMPONENT_IDS_KEY][$componentId]);

        if ($class !== null && isset($registry[self::COMPONENT_CLASSES_KEY][$class])) {
            $registry[self::COMPONENT_CLASSES_KEY][$class] = array_values(
                array_filter(
                    $registry[self::COMPONENT_CLASSES_KEY][$class],
                    fn($id) => $id !== $componentId
                )
            );
            if (empty($registry[self::COMPONENT_CLASSES_KEY][$class])) {
                unset($registry[self::COMPONENT_CLASSES_KEY][$class]);
            }
        }

        if ($path !== null && isset($registry[self::COMPONENT_PATHS_KEY][$path])) {
            $registry[self::COMPONENT_PATHS_KEY][$path] = array_values(
                array_filter(
                    $registry[self::COMPONENT_PATHS_KEY][$path],
                    fn($id) => $id !== $componentId
                )
            );
            if (empty($registry[self::COMPONENT_PATHS_KEY][$path])) {
                unset($registry[self::COMPONENT_PATHS_KEY][$path]);
            }
        }

        $this->session->set(self::REGISTRY_KEY, $registry);
    }

    public function setUses(string $componentId, array $uses): void
    {
        $registry = $this->getRegistry();
        if ($registry === null) {
            return;
        }
        if (isset($registry[self::COMPONENT_IDS_KEY][$componentId])) {
            $registry[self::COMPONENT_IDS_KEY][$componentId][self::COMPONENT_USES_KEY] = $uses;
            $this->session->set(self::REGISTRY_KEY, $registry);
        }
    }

    public function getUses(string $componentId): ?array
    {
        $registry = $this->getRegistry();
        return $registry[self::COMPONENT_IDS_KEY][$componentId][self::COMPONENT_USES_KEY] ?? null;
    }

    public function updateAction(string $componentId, string $action): void
    {
        $registry = $this->getRegistry();
        if ($registry === null) {
            return;
        }

        if (isset($registry[self::COMPONENT_IDS_KEY][$componentId])) {
            $registry[self::COMPONENT_IDS_KEY][$componentId]['action'] = $action;
            $this->session->set(self::REGISTRY_KEY, $registry);
        }
    }

    public function getComponentIdsByClass(string $controllerClass): array
    {
        $registry = $this->getRegistry();
        return $registry[self::COMPONENT_CLASSES_KEY][$controllerClass] ?? [];
    }

    public function getComponentIdsByPath(string $path): array
    {
        $registry = $this->getRegistry();
        return $registry[self::COMPONENT_PATHS_KEY][$path] ?? [];
    }

    public function getComponentData(string $componentId): ?array
    {
        $registry = $this->getRegistry();
        return $registry[self::COMPONENT_IDS_KEY][$componentId] ?? null;
    }

    public function getAllComponentIds(): array
    {
        $registry = $this->getRegistry();
        return array_keys($registry[self::COMPONENT_IDS_KEY] ?? []);
    }

    public function hasComponent(string $componentId): bool
    {
        $registry = $this->getRegistry();
        return isset($registry[self::COMPONENT_IDS_KEY][$componentId]);
    }

    public function getComponentIdsForClass(string $controllerClass, string $excludeId): array
    {
        $ids = $this->getComponentIdsByClass($controllerClass);
        return array_values(array_filter($ids, fn($id) => $id !== $excludeId));
    }

    public function clear(): void
    {
        $this->session->remove(self::REGISTRY_KEY);
    }

    public function getStats(): array
    {
        $registry = $this->getRegistry();
        return [
            'totalComponents' => count($registry[self::COMPONENT_IDS_KEY] ?? []),
            'totalClasses' => count($registry[self::COMPONENT_CLASSES_KEY] ?? []),
            'totalPaths' => count($registry[self::COMPONENT_PATHS_KEY] ?? []),
        ];
    }

    private function getOrCreateRegistry(): array
    {
        $registry = $this->session->get(self::REGISTRY_KEY);
        if ($registry === null) {
            $registry = [
                self::COMPONENT_IDS_KEY => [],
                self::COMPONENT_CLASSES_KEY => [],
                self::COMPONENT_PATHS_KEY => [],
            ];
            $this->session->set(self::REGISTRY_KEY, $registry);
        }
        return $registry;
    }

    private function getRegistry(): ?array
    {
        $registry = $this->session->get(self::REGISTRY_KEY);
        return $registry ?? null;
    }
}