<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Middlewares;

use Modules\ForgeRouter\Http\Middleware;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeRouter\Http\Response;
use Modules\ForgeRouter\Middleware\Attributes\RegisterMiddleware;
use Modules\ForgeWire\Core\Html\HtmlTokenizer;
use Modules\ForgeWire\Services\ComponentIdentityService;
use Modules\ForgeWire\Services\ComponentRegistry;
use Forge\Core\Session\SessionInterface;
use Modules\ForgeWire\Traits\WireHelper;

#[RegisterMiddleware(group: 'web', order: 100)]
final class ForgeWireMiddleware extends Middleware
{
  use WireHelper;

    public function __construct(
        private SessionInterface $session,
        private ComponentIdentityService $identityService,
        ComponentRegistry $registry,
    ) {
        $this->setComponentRegistry($registry);
    }

    public function handle(Request $request, callable $next): Response
    {
        $response = $next($request);

        if ($request->hasHeader('X-ForgeWire')) {
            return $this->extractLayoutIslands($response);
        }

        $route = $request->getAttribute('_route');
        $html = $response->getContent();

        $this->injectComponentChecksums($html, $route);
        $componentIdsInResponse = $this->trackComponentsInResponse($html);
        $this->cleanupStaleComponents($componentIdsInResponse);

        $response->setContent($html);

        return $response;
    }

    private function injectComponentChecksums(string &$html, ?array $route): void
    {
        if ($route === null) {
            return;
        }

        $controllerClass = $route['controller'] ?? '';
        $method = $route['method'] ?? 'index';

        if ($controllerClass === '' || !$this->identityService->isReactive($controllerClass)) {
            return;
        }

        $currentPath = parse_url(($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        $tokenizer = new HtmlTokenizer($html);

        // Collect modifications and apply in reverse order (end to start)
        // so byte positions remain valid during replacement.
        $modifications = [];

        foreach ($tokenizer->findTagIndicesByAttribute('fw:id') as $index) {
            if ($tokenizer->getAttribute($index, 'fw:checksum') !== null) {
                continue;
            }

            $range = $tokenizer->getElementByteRange($index);
            if ($range === null) {
                continue;
            }

            $dependsAttr = $tokenizer->getAttribute($index, 'fw:depends');
            $depends = $dependsAttr !== null && $dependsAttr !== ''
                ? array_map('trim', explode(',', $dependsAttr))
                : [];

            $sig = $this->identityService->getFingerprint(
                (string) $tokenizer->getAttribute($index, 'fw:id'),
                $controllerClass,
                $currentPath,
                $method,
                $depends
            );

            if ($sig === '') {
                continue;
            }

            $modifiedElement = $tokenizer->injectAttribute($index, 'fw:checksum', $sig);
            if ($modifiedElement === null) {
                continue;
            }

            $modifications[] = [
                'start' => $range['start'],
                'end' => $range['end'],
                'html' => $modifiedElement,
            ];
        }

        // Apply from end to preserve positions
        usort($modifications, fn(array $a, array $b): int => $b['start'] <=> $a['start']);

        foreach ($modifications as $mod) {
            $html = substr($html, 0, $mod['start']) . $mod['html'] . substr($html, $mod['end']);
        }
    }

    private function extractLayoutIslands(Response $response): Response
    {
        $tokenizer = new HtmlTokenizer($response->getContent());
        $islands = [];

        foreach ($tokenizer->findTagIndicesByAttribute('fw:id') as $index) {
            $island = $tokenizer->extractElement($index);
            if ($island !== null) {
                $islands[] = $island;
            }
        }

        if (!empty($islands)) {
            return new Response(implode("\n", $islands), $response->getStatusCode(), $response->getHeaders());
        }

        return $response;
    }
}
