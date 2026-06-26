<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Middlewares;

use App\Modules\ForgeRouter\Http\Middleware;
use App\Modules\ForgeRouter\Http\Request;
use App\Modules\ForgeRouter\Http\Response;
use App\Modules\ForgeWire\Services\ComponentRegistry;
use Forge\Core\Session\SessionInterface;
use Forge\Traits\WireHelper;

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
