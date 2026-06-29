<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Traits;

use Modules\ForgeWire\Response\ForgeWireResponse;
use Modules\ForgeRouter\Http\Request;

trait WithWireResponse
{
    protected ?string $__fw_id = null;
    protected ?ForgeWireResponse $__responseContext = null;
    protected array $__computedCache = [];

    public function isWireRequest(Request $request): bool
    {
        return $request->hasHeader('X-ForgeWire');
    }

    public function isReactive(): bool
    {
        $ref = new \ReflectionClass($this);
        return !empty($ref->getAttributes(\Modules\ForgeWire\Attributes\Reactive::class));
    }

    protected function getResponseContext(): ?ForgeWireResponse
    {
        if ($this->__responseContext !== null) {
            return $this->__responseContext;
        }

        $ref = new \ReflectionClass($this);
        $reactiveAttr = $ref->getAttributes(\Modules\ForgeWire\Attributes\Reactive::class);
        if (empty($reactiveAttr)) {
            return null;
        }

        $id = $this->getComponentId();
        if ($id === null) {
            return null;
        }

        return ForgeWireResponse::getContext($id);
    }

    protected function getComponentId(): ?string
    {
        return $this->__fw_id;
    }

    protected function cacheComputed(string $method, callable $callback): mixed
    {
        if (array_key_exists($method, $this->__computedCache)) {
            return $this->__computedCache[$method];
        }
        $this->__computedCache[$method] = $callback();
        return $this->__computedCache[$method];
    }

    public function clearComputedCache(): void
    {
        $this->__computedCache = [];
    }

    public function redirect(string $url, int $delay = 0): void
    {
        $context = $this->getResponseContext();
        if ($context === null) {
            throw new \RuntimeException('redirect() can only be called from within a ForgeWire action');
        }
        $context->setRedirect($url, $delay);
    }

    public function flash(string $type, string $message): void
    {
        $context = $this->getResponseContext();
        if ($context === null) {
            throw new \RuntimeException('flash() can only be called from within a ForgeWire action');
        }
        $context->addFlash($type, $message);
    }

    public function dispatch(string $event, array $data = []): void
    {
        $context = $this->getResponseContext();
        if ($context === null) {
            throw new \RuntimeException('dispatch() can only be called from within a ForgeWire action');
        }
        $context->addEvent($event, $data);
    }
}
