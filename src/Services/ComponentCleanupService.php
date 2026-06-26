<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Services;

use Forge\Core\Config\Config;
use Forge\Core\DI\Attributes\Service;
use Forge\Core\Session\SessionInterface;

#[Service]
/**
 * Removes ForgeWire component session data and detects stale components.
 *
 * Staleness is determined by the `forgewire.component_ttl_seconds` config.
 * A value of 0 disables automatic cleanup.
 */
final class ComponentCleanupService
{
    public function __construct(
        private Config $config,
        private ComponentRegistry $registry,
    ) {
    }

    public function getTtlSeconds(): int
    {
        return (int) $this->config->get('forgewire.component_ttl_seconds', 1800);
    }

    /**
     * Check whether a component has exceeded the TTL without being touched.
     */
    public function isStale(string $componentId, SessionInterface $session): bool
    {
        $ttl = $this->getTtlSeconds();
        if ($ttl <= 0) {
            return false;
        }

        $lastActive = $session->get("forgewire:active:{$componentId}");
        if (!is_numeric($lastActive)) {
            return false;
        }

        return (time() - (int) $lastActive) > $ttl;
    }

    /**
     * Remove all session data for a single component.
     */
    public function remove(string $componentId, SessionInterface $session): void
    {
        $data = $this->registry->getComponentData($componentId);
        $componentClass = $data['class'] ?? $session->get("forgewire:{$componentId}:class");

        $this->registry->unregister($componentId);

        $prefix = "forgewire:{$componentId}";
        $keysToRemove = [];

        foreach (array_keys($session->all()) as $key) {
            if (str_starts_with($key, $prefix . ':') || $key === $prefix) {
                $keysToRemove[] = $key;
            }
        }

        foreach ($keysToRemove as $key) {
            $session->remove($key);
        }

        $session->remove("forgewire:active:{$componentId}");

        if ($componentClass !== null) {
            $this->removeFromSharedGroup($componentId, $componentClass, $session);
        }
    }

    /**
     * Remove all stale components and return the number removed.
     */
    public function removeStale(SessionInterface $session): int
    {
        $ttl = $this->getTtlSeconds();
        if ($ttl <= 0) {
            return 0;
        }

        $removed = 0;

        foreach ($this->registry->getAllComponentIds() as $componentId) {
            if ($this->isStale($componentId, $session)) {
                $this->remove($componentId, $session);
                $removed++;
            }
        }

        return $removed;
    }

    private function removeFromSharedGroup(string $componentId, string $componentClass, SessionInterface $session): void
    {
        $groupKey = "forgewire:shared-group:{$componentClass}:components";
        $components = $session->get($groupKey, []);

        if (!is_array($components)) {
            return;
        }

        $components = array_values(array_filter($components, fn($id) => $id !== $componentId));

        if ($components === []) {
            $session->remove($groupKey);
            $session->remove("forgewire:shared-group:{$componentClass}:initialized");
            $session->remove("forgewire:shared:{$componentClass}");
        } else {
            $session->set($groupKey, $components);
        }
    }
}
