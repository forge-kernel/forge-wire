<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Services;

use App\Modules\ForgeWire\Core\Html\HtmlTokenizer;
use App\Modules\ForgeWire\Core\Hydrator;
use App\Modules\ForgeRouter\Http\Request;
use Forge\Core\DI\Container;
use Forge\Core\Session\SessionInterface;
use LogicException;

final class DependencyTracker
{
  public function __construct(
    private Container $container,
    private Hydrator $hydrator,
    private ActionDispatcher $actionDispatcher,
    private ComponentRegistry $registry,
  ) {
  }

  private function tokenizerFor(HtmlTokenizer|string $source): HtmlTokenizer
  {
    return $source instanceof HtmlTokenizer ? $source : new HtmlTokenizer($source);
  }

  public function parseAndStoreUses(HtmlTokenizer|string $source, string $componentId, SessionInterface $session): void
  {
    $tokenizer = $this->tokenizerFor($source);
    $uses = [];

    foreach (['fw:uses', 'fw:depends'] as $attribute) {
      foreach ($tokenizer->collectAttributeValues($attribute) as $value) {
        $values = array_map('trim', explode(',', $value));
        foreach ($values as $v) {
          if ($v !== '') {
            $uses[$v] = true;
          }
        }
      }
    }

    $usesArray = array_keys($uses);
    $session->set("forgewire:{$componentId}:uses", $usesArray);
    $this->registry->setUses($componentId, $usesArray);
  }

  public function parseAndStoreUsesForAllComponents(HtmlTokenizer|string $source, SessionInterface $session, ?string $controllerClass = null): void
  {
    $tokenizer = $this->tokenizerFor($source);

    foreach ($tokenizer->collectAttributeValues('fw:id') as $componentId) {
      $data = $this->registry->getComponentData($componentId);
      $componentClass = $data['class'] ?? null;

      if ($componentClass === null) {
        if ($controllerClass !== null) {
          $session->set("forgewire:{$componentId}:class", $controllerClass);
          $session->set("forgewire:{$componentId}:action", "index");
          $this->registry->register($componentId, $controllerClass, "index");
          $componentClass = $controllerClass;
        } else {
          continue;
        }
      }

      if ($controllerClass !== null && $componentClass !== $controllerClass) {
        continue;
      }

      $componentHtml = $tokenizer->extractFirstElementByAttribute('fw:id', $componentId);
      if ($componentHtml !== null) {
        $this->parseAndStoreUses($componentHtml, $componentId, $session);
      } else {
        $this->parseAndStoreUses($tokenizer, $componentId, $session);
      }
    }
  }

  public function discoverAndStoreUsesForRegisteredComponents(HtmlTokenizer|string $source, SessionInterface $session, string $controllerClass): void
  {
    $tokenizer = $this->tokenizerFor($source);
    $foundInHtml = array_flip($tokenizer->collectAttributeValues('fw:id'));

    foreach ($this->registry->getAllComponentIds() as $componentId) {
      $data = $this->registry->getComponentData($componentId);
      if ($data === null || $data['class'] !== $controllerClass) {
        continue;
      }

      if (isset($foundInHtml[$componentId])) {
        continue;
      }

      if ($this->registry->getUses($componentId) !== null) {
        continue;
      }

      $componentHtml = $tokenizer->extractFirstElementByAttribute('fw:id', $componentId);
      if ($componentHtml !== null) {
        $this->parseAndStoreUses($componentHtml, $componentId, $session);
      } else {
        $this->parseAndStoreUses($tokenizer, $componentId, $session);
      }
    }
  }

  public function assertDependenciesRegisteredForController(SessionInterface $session, string $controllerClass): void
  {
    $componentIds = $this->registry->getComponentIdsByClass($controllerClass);

    if (empty($componentIds)) {
      return;
    }

    foreach ($componentIds as $componentId) {
      if ($this->registry->getUses($componentId) === null) {
        throw new LogicException(
          "Reactive component {$componentId} is active but has no dependencies registered"
        );
      }
    }
  }

  public function parseSharedGroupsFromHtml(HtmlTokenizer|string $source, SessionInterface $session, ?string $controllerClass = null): void
  {
    $tokenizer = $this->tokenizerFor($source);

    foreach ($tokenizer->findTagIndicesByAttribute('fw:shared') as $index) {
      $containerHtml = $tokenizer->extractElement($index);
      if ($containerHtml === null) {
        continue;
      }

      $containerTokenizer = $this->tokenizerFor($containerHtml);
      $componentIds = array_values($containerTokenizer->collectAttributeValues('fw:id'));
      if ($componentIds === []) {
        continue;
      }

      $groupedByClass = [];

      foreach ($componentIds as $componentId) {
        $data = $this->registry->getComponentData($componentId);
        $componentClass = $data['class'] ?? null;

        if ($componentClass === null && $controllerClass !== null) {
          $session->set("forgewire:{$componentId}:class", $controllerClass);
          $session->set("forgewire:{$componentId}:action", "index");
          $this->registry->register($componentId, $controllerClass, "index");
          $componentClass = $controllerClass;
        }

        if ($componentClass !== null) {
          if ($controllerClass !== null && $componentClass !== $controllerClass) {
            continue;
          }

          if (!isset($groupedByClass[$componentClass])) {
            $groupedByClass[$componentClass] = [];
          }
          if (!in_array($componentId, $groupedByClass[$componentClass], true)) {
            $groupedByClass[$componentClass][] = $componentId;
          }
        }
      }

      foreach ($groupedByClass as $controllerClassKey => $components) {
        $groupKey = "forgewire:shared-group:{$controllerClassKey}:components";
        $existing = $session->get($groupKey, []);
        $merged = array_unique(array_merge($existing, $components));
        $session->set($groupKey, array_values($merged));
      }
    }
  }

  public function discoverSharedGroupFromRegisteredComponents(SessionInterface $session, string $controllerClass): void
  {
    $groupKey = "forgewire:shared-group:{$controllerClass}:components";

    $componentIds = $this->registry->getComponentIdsByClass($controllerClass);

    if (!empty($componentIds)) {
      $existing = $session->get($groupKey, []);
      $merged = array_unique(array_merge($existing, $componentIds));
      $session->set($groupKey, array_values($merged));
    }
  }

  public function initializeSharedGroupIfNeeded(
    string $componentId,
    string $controllerClass,
    SessionInterface $session,
    Request $request,
    string $sharedKey,
    string $currentHtml = ""
  ): void {
    $groupKey = "forgewire:shared-group:{$controllerClass}:components";

    if (!$session->has($groupKey)) {
      return;
    }

    $componentIds = $session->get($groupKey, []);
    if (empty($componentIds)) {
      return;
    }

    $hasUninitialized = false;
    foreach ($componentIds as $id) {
      $data = $this->registry->getComponentData($id);
      if ($data === null || $data['class'] === null) {
        continue;
      }

      if ($data['class'] !== $controllerClass) {
        continue;
      }

      if ($this->registry->getUses($id) === null) {
        $hasUninitialized = true;
        break;
      }
    }

    if (!$hasUninitialized) {
      $initializedKey = "forgewire:shared-group:{$controllerClass}:initialized";
      $session->set($initializedKey, true);
      return;
    }

    foreach ($componentIds as $id) {
      $data = $this->registry->getComponentData($id);
      if ($data === null || $data['class'] === null) {
        continue;
      }

      if ($data['class'] !== $controllerClass) {
        continue;
      }

      if ($this->registry->getUses($id) !== null) {
        continue;
      }

      $componentHtml = null;
      $currentHtmlTokenizer = null;
      if ($currentHtml !== "") {
        $currentHtmlTokenizer = new HtmlTokenizer($currentHtml);
        $componentHtml = $currentHtmlTokenizer->extractFirstElementByAttribute('fw:id', $id);
      }

      if ($componentHtml === null) {
        $instance = $this->container->make($controllerClass);
        $sessionKey = "forgewire:{$id}";
        $this->hydrator->hydrate($instance, [], $session, $sessionKey, $sharedKey);

        $action = $data['action'] ?? $session->get("forgewire:{$id}:action") ?? "index";
        $html = "";

        if (method_exists($instance, $action)) {
          $html = $this->actionDispatcher->call($instance, $action, $request, $session, [], [], false, $id);
        }

        if ($html === "" && method_exists($instance, 'render')) {
          $html = (string) $instance->render();
        }

        if ($html !== "") {
          $htmlTokenizer = new HtmlTokenizer($html);
          $componentHtml = $htmlTokenizer->extractFirstElementByAttribute('fw:id', $id);
          if ($componentHtml === null) {
            $componentHtml = $html;
          }
        }
      }

      if ($componentHtml !== null) {
        $this->parseAndStoreUses($componentHtml, $id, $session);
      }
    }

    $initializedKey = "forgewire:shared-group:{$controllerClass}:initialized";
    $session->set($initializedKey, true);
  }

  public function trackComponentsInHtml(HtmlTokenizer|string $source, SessionInterface $session): void
  {
    $tokenizer = $this->tokenizerFor($source);
    $now = time();
    foreach ($tokenizer->collectAttributeValues('fw:id') as $componentId) {
      $activeKey = "forgewire:active:{$componentId}";
      $session->set($activeKey, $now);
    }
  }
}
