<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Services;

use Modules\ForgeWire\Core\Hydrator;
use Forge\Core\Session\SessionInterface;

final class SharedStateManager
{
  public function __construct(
    private ComponentCleanupService $cleanupService,
    private ComponentRegistry $registry,
  ) {
  }

  public function getSharedStates(object $instance, string $class): array
  {
    $recipe = Hydrator::getRecipe($class);
    $sharedStates = [];

    foreach ($recipe as $propName => $cfg) {
      if (($cfg['kind'] ?? null) === 'state' && ($cfg['shared'] ?? false)) {
        $sharedStates[$propName] = $cfg['reader']($instance);
      }
    }

    return $sharedStates;
  }

  public function getSharedStatesFromSession(array $sharedBag, string $class): array
  {
    $recipe = Hydrator::getRecipe($class);
    $sharedStates = [];

    foreach ($recipe as $propName => $cfg) {
      if (($cfg['kind'] ?? null) === 'state' && ($cfg['shared'] ?? false)) {
        $sharedStates[$propName] = $sharedBag[$propName] ?? null;
      }
    }

    return $sharedStates;
  }

  public function getSharedStateChanges(array $before, array $after): array
  {
    $changes = [];
    $allKeys = array_unique(array_merge(array_keys($before), array_keys($after)));

    foreach ($allKeys as $propName) {
      $beforeValue = $before[$propName] ?? null;
      $afterValue = $after[$propName] ?? null;

      $hasChanged = false;

      if (!array_key_exists($propName, $before) && array_key_exists($propName, $after)) {
        $hasChanged = true;
      } elseif (array_key_exists($propName, $before) && !array_key_exists($propName, $after)) {
        $hasChanged = true;
      } else {
        if (is_array($afterValue) || is_array($beforeValue)) {
          $hasChanged = json_encode($beforeValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) !== json_encode($afterValue, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } else {
          $hasChanged = $beforeValue !== $afterValue;
        }
      }

      if ($hasChanged) {
        $changes[$propName] = $afterValue;
      }
    }

    return $changes;
  }

  public function findAffectedComponents(
    array $sharedStateChanges,
    SessionInterface $session,
    string $controllerClass,
    string $triggeringId
  ): array {
    $affected = [];
    $changedKeys = array_keys($sharedStateChanges);

    foreach ($this->registry->getAllComponentIds() as $componentId) {
      if ($componentId === $triggeringId) {
        continue;
      }

      if ($this->cleanupService->isStale($componentId, $session)) {
        $this->cleanupService->remove($componentId, $session);
        continue;
      }

      $data = $this->registry->getComponentData($componentId);
      if ($data === null || $data['class'] !== $controllerClass) {
        continue;
      }

      $uses = $this->registry->getUses($componentId);
      if (!is_array($uses)) {
        continue;
      }

      if (!array_intersect($uses, $changedKeys)) {
        continue;
      }

      $affected[] = [
        'id' => $componentId,
        'class' => $data['class'],
      ];
    }

    return $affected;
  }
}
