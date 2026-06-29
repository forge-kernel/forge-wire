<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Middlewares;

use Modules\ForgeRouter\Http\Middleware;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeRouter\Http\Response;
use Modules\ForgeWire\Services\ComponentRegistry;
use Forge\Core\Session\SessionInterface;
use Modules\ForgeWire\Traits\WireHelper;

final class ForgeWireCleanMiddleware extends Middleware
{
  use WireHelper;

    public function __construct(
        private SessionInterface $session,
        ComponentRegistry $registry,
    ) {
        $this->setComponentRegistry($registry);
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        $content = $response->getContent();
        $componentIdsInResponse = $this->trackComponentsInResponse($content);
        $this->cleanupStaleComponents($componentIdsInResponse);

        return $response;
    }
}
