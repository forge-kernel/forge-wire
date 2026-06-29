<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Services;

use Modules\ForgeWire\Attributes\Action;
use Modules\ForgeWire\Security\Checksum;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeRouter\Http\Response;
use Forge\Core\Session\SessionInterface;
use ReflectionMethod;
use ReflectionNamedType;

final class ActionDispatcher
{
  private static array $actionCache = [];

  public function __construct(
    private Checksum $checksum,
  ) {
  }

  public function call(
    object $instance,
    string $action,
    Request $request,
    SessionInterface $session,
    array $args,
    array $dirty,
    bool $isExplicitAction,
    string $id
  ): string {
    $class = $instance::class;
    $cacheKey = "{$class}::{$action}";

    if (!isset(self::$actionCache[$cacheKey])) {
      if (!method_exists($instance, $action)) {
        self::$actionCache[$cacheKey] = false;
        return "";
      }

      $rm = new ReflectionMethod($instance, $action);
      if (!$rm->isPublic()) {
        throw new \RuntimeException("Action method must be public: {$action}");
      }

      $isAction = !empty($rm->getAttributes(Action::class));
      $params = [];
      foreach ($rm->getParameters() as $param) {
        $typeName = null;
        if ($param->hasType()) {
          $type = $param->getType();
          if ($type instanceof ReflectionNamedType) {
            $typeName = ltrim($type->getName(), '\\');
          }
        }
        $params[] = [
          'name' => $param->getName(),
          'type' => $typeName,
        ];
      }

      self::$actionCache[$cacheKey] = [
        'rm' => $rm,
        'isAction' => $isAction,
        'params' => $params,
      ];
    }

    $meta = self::$actionCache[$cacheKey];
    if ($meta === false) {
      return "";
    }

    /** @var ReflectionMethod $rm */
    $rm = $meta['rm'];

    if ($isExplicitAction) {
      $originalAction = $session->get("forgewire:{$id}:action") ?? "index";
      if ($action !== $originalAction && !$meta['isAction']) {
        throw new \RuntimeException("Action not allowed: {$action}. Must be marked with #[Action].");
      }

      $sessionKey = "forgewire:{$id}";
      if ($this->hasAnyExpectedActions($sessionKey, $session)) {
        if (!$this->checksum->isExpectedAction($sessionKey, $session, $action, $args)) {
          throw new \RuntimeException("ForgeWire action signature mismatch: {$action}.");
        }
      }
    }

    $methodArgs = [];
    foreach ($meta['params'] as $i => $pMeta) {
      $name = $pMeta['name'];
      $typeName = $pMeta['type'];
      $v = null;

      if ($typeName !== null) {
        if ($typeName === ltrim(Request::class, '\\'))
          $v = $request;
        elseif ($typeName === ltrim(SessionInterface::class, '\\'))
          $v = $session;
      }

      if ($v === null) {
        if (is_array($args)) {
          $v = $args[$name] ?? $args[$i] ?? $dirty[$name] ?? null;

          if ($v === null) {
            $v = $this->findCaseInsensitiveParam($args, $name) ?? $dirty[$name] ?? null;
          }
        } else {
          $v = $dirty[$name] ?? null;
        }

        if ($typeName !== null && $v !== null) {
          if ($typeName === "int" && is_string($v))
            $v = (int) $v;
          elseif ($typeName === "float" && is_string($v))
            $v = (float) $v;
          elseif ($typeName === "bool" && is_string($v))
            $v = filter_var($v, FILTER_VALIDATE_BOOLEAN);
          elseif ($typeName === "string" && !is_string($v))
            $v = (string) $v;
        }
      }
      $methodArgs[] = $v;
    }

    $res = $rm->invokeArgs($instance, $methodArgs);
    if ($res instanceof Response) {
      return $res->getContent();
    }
    return (string) $res;
  }

  public function isSubmit(string $class, string $action): bool
  {
    $rm = new ReflectionMethod($class, $action);

    foreach ($rm->getAttributes(Action::class) as $attr) {
      $instance = $attr->newInstance();
      return $instance->submit ?? false;
    }

    return false;
  }

  private function findCaseInsensitiveParam(array $args, string $name): mixed
  {
    foreach ($args as $key => $value) {
      if (is_string($key) && strcasecmp($key, $name) === 0) {
        return $value;
      }
    }
    return null;
  }

  private function hasAnyExpectedActions(string $sessionKey, SessionInterface $session): bool
  {
    $registryKey = $sessionKey . ':actions:list';
    $signatures = $session->get($registryKey, []);
    return !empty($signatures);
  }
}
