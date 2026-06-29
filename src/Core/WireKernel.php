<?php

namespace Modules\ForgeWire\Core;

use Modules\ForgeWire\Attributes\Reactive;
use Modules\ForgeWire\Core\Html\HtmlTokenizer;
use Modules\ForgeWire\Security\Checksum;
use Modules\ForgeWire\Response\ForgeWireResponse;
use Modules\ForgeWire\Services\ActionDispatcher;
use Modules\ForgeWire\Services\ComponentCleanupService;
use Modules\ForgeWire\Services\ComponentRegistry;
use Modules\ForgeWire\Services\DependencyTracker;
use Modules\ForgeWire\Services\SharedStateManager;
use Forge\Core\Debug\Metrics;
use Forge\Core\DI\Container;
use Modules\ForgeRouter\Http\Request;
use Forge\Exceptions\ValidationException;
use Forge\Core\Session\SessionInterface;
use Forge\Core\Validation\ValidationDefinition;
use Forge\Core\Validation\Validator;
use ReflectionClass;
use RuntimeException;

final class WireKernel
{
  private static array $reflCache = [];

  public function __construct(
    private Container $container,
    private Hydrator $hydrator,
    private Checksum $checksum,
    private ComponentCleanupService $cleanupService,
    private ActionDispatcher $actionDispatcher,
    private SharedStateManager $sharedStateManager,
    private DependencyTracker $dependencyTracker,
    private ComponentRegistry $registry,
  ) {
  }

  private function tokenizerFor(HtmlTokenizer|string $source): HtmlTokenizer
  {
    return $source instanceof HtmlTokenizer ? $source : new HtmlTokenizer($source);
  }

  public function process(array $p, Request $request, SessionInterface $session): array
  {
    Metrics::start('forgewire_kernel');
    try {
      $id = (string) ($p["id"] ?? "");
    $class = (string) ($p["controller"] ?? $session->get("forgewire:{$id}:class") ?? "");
    $action = $p["action"] ?? null;
    $args = is_array($p["args"] ?? []) ? $p["args"] : [];
    $dirty = (array) ($p["dirty"] ?? []);
    $depends = is_array($p["depends"] ?? null) ? $p["depends"] : null;

    $sessionKey = "forgewire:{$id}";
    $sharedKey = "forgewire:shared:{$class}";
    $ctx = [
      "class" => $class,
      "path" => (string) ($p["fingerprint"]["path"] ?? "/"),
      "depends" => $depends ?? [],
    ];

    if ($class === "" || !class_exists($class)) {
      return ["ignored" => true, "id" => $id];
    }

    if (!isset(self::$reflCache[$class])) {
      $refl = new ReflectionClass($class);
      self::$reflCache[$class] = !empty($refl->getAttributes(Reactive::class));
    }

    if (!self::$reflCache[$class]) {
      return ["ignored" => true, "id" => $id];
    }

    if ($this->cleanupService->isStale($id, $session)) {
      $this->cleanupService->remove($id, $session);
      return ["ignored" => true, "id" => $id];
    }

    $this->registry->register($id, $class, $action ?? $session->get("forgewire:{$id}:action") ?? 'index', $ctx['path']);

    if ($depends !== null) {
      $session->set("forgewire:{$id}:uses", $depends);
      $this->registry->setUses($id, $depends);
    }

    $state = $session->get($sessionKey, []);
    $hasState = !empty($state);

    if ($action !== null) {
      $ctx["action"] = $action;
      $ctx["args"] = $args;
    }

    $requestKey = $this->getRequestKey($id, $action, $args, $p["checksum"] ?? '');
    $processingKey = "forgewire:processing:{$requestKey}";
    $processedKey = "forgewire:processed:{$requestKey}";

    if ($session->has($processingKey)) {
      $processingTime = $session->get($processingKey);
      if (time() - $processingTime < 5) {
        return ["ignored" => true, "id" => $id];
      }
    }

    if ($session->has($processedKey)) {
      $processedTime = $session->get($processedKey);
      if (time() - $processedTime < 2) {
        // TODO: Analyze this behaviour in production to check if we add it back or not
        //return ["ignored" => true, "id" => $id];
      }
    }

    $session->set($processingKey, time());

    try {
      $this->checksum->verify(
        $p["checksum"] ?? null,
        $sessionKey,
        $session,
        $ctx,
      );

      $instance = $this->container->make($class);

      try {
        $reflection = new ReflectionClass($instance);
        if ($reflection->hasProperty('__fw_id')) {
          $prop = $reflection->getProperty('__fw_id');
          $prop->setAccessible(true);
          $prop->setValue($instance, $id);
        }
      } catch (\ReflectionException $e) {
      }

      $isSubmit =
        $action !== null
        && $action !== 'input'
        && $this->actionDispatcher->isSubmit($class, $action);

      if ($session->has($processedKey)) {
        $processedTime = $session->get($processedKey);
        if (time() - $processedTime < 2) {
          if ($action === 'input' || $isSubmit) {
            return ["ignored" => true, "id" => $id];
          }
        }
      }

      if (!$isSubmit) {
        $dirty = $this->filterDirty($dirty, $session, $sessionKey, $class);
      }

      $shouldValidateState =
        $action === 'input'
        || $isSubmit;

      if ($shouldValidateState) {
        $errors = $this->validateReactiveState(
          $instance,
          $dirty,
          $class,
          $isSubmit,
          $id,
          $session
        );

        if ($errors !== []) {
          $stateCtx = $ctx;
          unset($stateCtx['action'], $stateCtx['args']);
          return [
            "html" => "",
            "state" => null,
            "checksum" => $this->checksum->sign($sessionKey, $session, $stateCtx),
            "events" => [],
            "redirect" => null,
            "flash" => [],
            "errors" => $errors,
          ];
        }
      }

      $sharedBag = $session->get($sharedKey, []);
      $sharedStatesBefore = $this->sharedStateManager->getSharedStatesFromSession($sharedBag, $class);

      $this->hydrator->hydrate($instance, $dirty, $session, $sessionKey, $sharedKey);

      $responseContext = new ForgeWireResponse();
      ForgeWireResponse::setContext($id, $responseContext);

      try {
        $refl = new ReflectionClass($instance);
        if ($refl->hasProperty('__responseContext')) {
          $prop = $refl->getProperty('__responseContext');
          $prop->setAccessible(true);
          $prop->setValue($instance, $responseContext);
        }
      } catch (\ReflectionException $e) {
      }

      $html = "";

      Metrics::start('forgewire_render');
      if ($action === "input" && !method_exists($instance, "input")) {
        $action = $session->get("forgewire:{$id}:action") ?? "index";
      }

      if ($action) {
        $html = $this->actionDispatcher->call($instance, $action, $request, $session, $args, $dirty, true, $id);
      }

      if ($html === "") {
        $renderAction = $session->get("forgewire:{$id}:action") ?? "index";
        if (method_exists($instance, $renderAction)) {
          $html = $this->actionDispatcher->call($instance, $renderAction, $request, $session, $args, $dirty, false, $id);
        }
      }

      if ($html === "" && method_exists($instance, 'render')) {
        $html = (string) $instance->render();
      }
      Metrics::stop('forgewire_render');

      $redirect = $responseContext->getRedirect();
      $flashes = $responseContext->getFlashes();
      $events = $responseContext->getEvents();
      ForgeWireResponse::clearContext($id);

      $htmlTokenizer = new HtmlTokenizer($html);

      $this->dependencyTracker->parseSharedGroupsFromHtml($htmlTokenizer, $session, $class);
      $this->dependencyTracker->discoverSharedGroupFromRegisteredComponents($session, $class);
      $this->dependencyTracker->initializeSharedGroupIfNeeded($id, $class, $session, $request, $sharedKey, $html);
      $this->dependencyTracker->parseAndStoreUsesForAllComponents($htmlTokenizer, $session, $class);
      $this->dependencyTracker->discoverAndStoreUsesForRegisteredComponents($htmlTokenizer, $session, $class);
      $this->dependencyTracker->assertDependenciesRegisteredForController($session, $class);

      $this->dependencyTracker->trackComponentsInHtml($htmlTokenizer, $session);

      $componentHtml = $htmlTokenizer->extractFirstElementByAttribute('fw:id', $id);
      if ($componentHtml === null) {
        $componentHtml = $html;
      }

      $componentTokenizer = new HtmlTokenizer($componentHtml);
      $this->storeExpectedActions($componentTokenizer, $id, $session, $sessionKey);

      $state = $this->hydrator->dehydrate($instance, $session, $sessionKey, $sharedKey);

      $stateCtx = $ctx;
      unset($stateCtx['action'], $stateCtx['args']);
      $sig = $this->checksum->sign($sessionKey, $session, $stateCtx);

      $sharedStatesAfter = $this->sharedStateManager->getSharedStates($instance, $class);
      $sharedStateChanges = $this->sharedStateManager->getSharedStateChanges($sharedStatesBefore, $sharedStatesAfter);

      $affectedComponents = [];
      $updates = [];
      if (!empty($sharedStateChanges)) {
        Metrics::start('forgewire_shared_updates');
        $affectedComponents = $this->sharedStateManager->findAffectedComponents($sharedStateChanges, $session, $class, $id);

        foreach ($affectedComponents as $component) {
          if ($component['id'] === $id) {
            continue;
          }

          $update = $this->renderAffectedComponent(
            $component['id'],
            $component['class'],
            $request,
            $session,
            $sharedKey
          );

          if ($update !== null) {
            $updates[] = $update;
          }
        }
        Metrics::stop('forgewire_shared_updates');
      }

      $eventData = [];
      foreach ($events as $event) {
        $eventData[] = [
          'name' => $event['name'],
          'data' => $event['data'],
        ];
      }

      $result = [
        "html" => $componentHtml,
        "state" => $state,
        "checksum" => $sig,
        "events" => $eventData,
        "redirect" => $redirect,
        "flash" => $flashes,
        "updates" => $updates,
      ];

      $session->set($processedKey, time());
      $session->remove($processingKey);

      return $result;
    } catch (\RuntimeException $e) {
      if (str_contains($e->getMessage(), 'checksum mismatch') || str_contains($e->getMessage(), 'Fingerprint mismatch')) {
        if ($session->has($processedKey)) {
          $processedTime = $session->get($processedKey);
          if (time() - $processedTime < 2) {
            $session->remove($processingKey);
            return ["ignored" => true, "id" => $id];
          }
        }
      }
      $session->remove($processingKey);
      throw $e;
    }
    } finally {
      Metrics::stop('forgewire_kernel');
    }
  }

  private function getRequestKey(string $id, ?string $action, array $args, string $checksum): string
  {
    $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return md5("{$id}:{$action}:{$argsJson}:{$checksum}");
  }

  private function extractActionsFromHtml(HtmlTokenizer|string $source, string $componentId): array
  {
    $actions = [];

    $html = $source instanceof HtmlTokenizer ? '' : $source;
    if ($source instanceof HtmlTokenizer && $source->tokens() === []) {
      return $actions;
    }
    if ($html === '' && !$source instanceof HtmlTokenizer) {
      return $actions;
    }

    $tokenizer = $this->tokenizerFor($source);
    $actionAttributes = [
      'fw:click',
      'fw:click.optimistic',
      'fw:submit',
      'fw:submit.optimistic',
      'fw:action',
    ];

    foreach ($tokenizer->tokens() as $token) {
      if (!$this->isActionTagToken($token)) {
        continue;
      }

      $actionName = null;
      foreach ($actionAttributes as $attr) {
        if (array_key_exists($attr, $token['attributes'])) {
          $actionName = trim((string) $token['attributes'][$attr]);
          break;
        }
      }

      // fw:keydown.<key> is dynamic, so check for any prefix.
      if ($actionName === null || $actionName === '') {
        foreach ($token['attributes'] as $attr => $value) {
          if (str_starts_with($attr, 'fw:keydown.')) {
            $actionName = trim((string) $value);
            break;
          }
        }
      }

      if (empty($actionName)) {
        continue;
      }

      $args = [];
      foreach ($token['attributes'] as $attr => $value) {
        if (!str_starts_with($attr, 'fw:param-')) {
          continue;
        }
        $paramName = strtolower(substr($attr, strlen('fw:param-')));
        if ($paramName !== '') {
          $args[$paramName] = (string) $value;
        }
      }

      $actions[] = [
        'action' => $actionName,
        'args' => $args,
      ];
    }

    return $actions;
  }

  private function isActionTagToken(array $token): bool
  {
    if (!in_array($token['type'], ['open', 'self-closing'], true)) {
      return false;
    }
    foreach ($token['attributes'] as $attr => $_) {
      if (
        $attr === 'fw:click' ||
        $attr === 'fw:click.optimistic' ||
        $attr === 'fw:submit' ||
        $attr === 'fw:submit.optimistic' ||
        $attr === 'fw:action' ||
        str_starts_with($attr, 'fw:keydown.')
      ) {
        return true;
      }
    }
    return false;
  }

  private function storeExpectedActions(HtmlTokenizer|string $source, string $componentId, SessionInterface $session, string $sessionKey): void
  {
    $actions = $this->extractActionsFromHtml($source, $componentId);
    $registryKey = $sessionKey . ':actions:list';

    $previous = (array) $session->get($registryKey, []);
    foreach ($previous as $signature) {
      $session->remove($sessionKey . ':actions:' . $signature);
    }

    $signatures = [];
    foreach ($actions as $actionData) {
      $signature = $this->checksum->computeActionSignature($actionData['action'], $actionData['args']);
      $session->set($sessionKey . ':actions:' . $signature, true);
      $signatures[] = $signature;
    }

    $session->set($registryKey, $signatures);
  }

  private function hasAnyExpectedActions(string $sessionKey, SessionInterface $session): bool
  {
    $registryKey = $sessionKey . ':actions:list';
    $signatures = $session->get($registryKey, []);
    return !empty($signatures);
  }

  private function renderAffectedComponent(
    string $componentId,
    string $controllerClass,
    Request $request,
    SessionInterface $session,
    string $sharedKey
  ): ?array {
    $sessionKey = "forgewire:{$componentId}";

    $fp = (array) $session->get($sessionKey . ':fp', []);
    $storedPath = (string) ($fp['path'] ?? $request->getPath());

    $ctx = [
      "class" => $controllerClass,
      "path" => $storedPath,
    ];

    if (!$session->has($sessionKey) && !$session->has("forgewire:{$componentId}:class")) {
      return null;
    }

    if ($this->cleanupService->isStale($componentId, $session)) {
      $this->cleanupService->remove($componentId, $session);
      return null;
    }

    $instance = $this->container->make($controllerClass);
    $this->hydrator->hydrate($instance, [], $session, $sessionKey, $sharedKey);

    $action = $session->get("forgewire:{$componentId}:action") ?? "index";

    $html = "";
    if (method_exists($instance, $action)) {
      $html = $this->actionDispatcher->call($instance, $action, $request, $session, [], [], false, $componentId);
    }

    if ($html === "" && method_exists($instance, 'render')) {
      $html = (string) $instance->render();
    }

    if ($html === "") {
      return null;
    }

    $htmlTokenizer = new HtmlTokenizer($html);
    $componentHtml = $htmlTokenizer->extractFirstElementByAttribute('fw:id', $componentId);

    if ($componentHtml === null) {
      return null;
    }

    $componentTokenizer = new HtmlTokenizer($componentHtml);
    $this->dependencyTracker->parseAndStoreUses($componentTokenizer, $componentId, $session);
    $this->storeExpectedActions($componentTokenizer, $componentId, $session, $sessionKey);

    $targetElements = $componentTokenizer->extractElementsByAttribute('fw:target');

    if (!empty($targetElements)) {
      $componentHtml = '<div>' . implode('', $targetElements) . '</div>';
    }

    $state = $this->hydrator->dehydrate($instance, $session, $sessionKey, $sharedKey);
    $checksum = $this->checksum->sign($sessionKey, $session, $ctx);

    return [
      "id" => $componentId,
      "html" => $componentHtml,
      "state" => $state,
      "checksum" => $checksum,
    ];
  }

  private function validateReactiveState(
    object $instance,
    array $dirty,
    string $class,
    bool $isSubmit,
    string $id,
    SessionInterface $session
  ): array {
    $recipe = Hydrator::getRecipe($class);

    $data = [];
    $rules = [];
    $messages = [];

    foreach ($recipe as $prop => $cfg) {
      if (
        ($cfg['kind'] ?? null) !== 'state'
        || !isset($cfg['validate'])
      ) {
        continue;
      }

      if (!array_key_exists($prop, $dirty)) {
        continue;
      }

      if (!$cfg['public']) {
        continue;
      }

      $value = $dirty[$prop];

      $data[$prop] = $value;
      $rules[$prop] = $cfg['validate']['rules'];

      if (!empty($cfg['validate']['messages'])) {
        $messages[$prop] = $cfg['validate']['messages'];
      }
    }

    if ($data === []) {
      return [];
    }

    $flatMessages = [];

    foreach ($messages as $field => $fieldMessages) {
      foreach ($fieldMessages as $rule => $message) {
        $flatMessages["{$field}.{$rule}"] = $message;
      }
    }

    try {
      (new Validator(
        new ValidationDefinition($data, $rules, $flatMessages),
        onlyPresent: !$isSubmit
      ))->validate();

      return [];
    } catch (ValidationException $e) {
      return $e->errors();
    }
  }

  private function filterDirty(
    array $dirty,
    SessionInterface $session,
    string $sessionKey,
    string $class
  ): array {
    $stateBag = $session->get($sessionKey, []);
    $filtered = [];

    $recipe = Hydrator::getRecipe($class);

    foreach ($dirty as $key => $value) {
      if (isset($recipe[$key]) && !$recipe[$key]['public']) {
        continue;
      }

      if (!array_key_exists($key, $stateBag)) {
        $filtered[$key] = $value;
        continue;
      }

      if ($stateBag[$key] !== $value) {
        $filtered[$key] = $value;
      }
    }

    return $filtered;
  }

  private function actionTouchesValidatedState(
    string $class,
    ?string $action,
    array $dirty
  ): bool {
    if ($action === null) {
      return false;
    }

    $recipe = Hydrator::getRecipe($class);

    foreach ($dirty as $prop => $_) {
      if (
        isset($recipe[$prop]) &&
        ($recipe[$prop]['kind'] ?? null) === 'state' &&
        isset($recipe[$prop]['validate'])
      ) {
        return true;
      }
    }

    return false;
  }

  }

