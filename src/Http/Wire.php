<?php

declare(strict_types=1);

namespace Modules\ForgeWire\Http;

use Modules\ForgeLogger\Services\ForgeLoggerService;
use Modules\ForgeWire\Core\WireKernel;
use Modules\ForgeRouter\Http\Attributes\UseMiddleware;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeRouter\Http\Response;
use Modules\ForgeRouter\Attributes\Routable;
use Modules\ForgeRouter\Routing\Endpoint;
use Forge\Core\Debug\Metrics;
use Forge\Core\Session\SessionInterface;
use Modules\ForgeRouter\Traits\ResponseHelper;
use Modules\ForgeView\Traits\ViewHelper;

#[Routable]
#[UseMiddleware("web")]
final class Wire
{
    use ResponseHelper;
    use ViewHelper;

    public function __construct(
        private WireKernel $kernel,
        private SessionInterface $session,
        private ForgeLoggerService $logger
    ) {
    }

    #[Endpoint("/__wire", method: 'POST')]
    public function handle(Request $request): Response
    {
        if (!$request->hasHeader('X-ForgeWire')) {
            return $this->jsonResponse([
                'error' => [
                    'message' => 'Invalid ForgeWire request.',
                ],
            ], 400);
        }

        $payload = $request->json();
        $componentId = $payload['id'] ?? null;

        try {
            if ($componentId) {
                $this->trackActiveComponent($componentId);
            }

            Metrics::start('forgewire_request');
            $result = $this->kernel->process($payload, $request, $this->session);
            Metrics::stop('forgewire_request');

            return $this->jsonResponse($result);
        } catch (\RuntimeException $e) {
            $isChecksumMismatch = str_contains($e->getMessage(), 'checksum mismatch')
                || str_contains($e->getMessage(), 'Fingerprint mismatch')
                || str_contains($e->getMessage(), 'signature mismatch')
                || str_contains($e->getMessage(), 'dependencies have changed');

            if ($isChecksumMismatch && $componentId !== null) {
                $requestKey = $this->getRequestKey($payload);
                $processingKey = "forgewire:processing:{$requestKey}";

                if ($this->session->has($processingKey)) {
                    $processingTime = $this->session->get($processingKey);
                    if (time() - $processingTime < 5) {
                        return $this->jsonResponse(["ignored" => true, "id" => $componentId]);
                    }
                }
            }

            $this->logger->debug('ForgeWire error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $isDebug = env('APP_DEBUG', false);
            $statusCode = $isChecksumMismatch ? 400 : 500;

            $errorResponse = [
                'error' => [
                    'message' => $isDebug && !$isChecksumMismatch ? $e->getMessage() : 'An error occurred processing your request.',
                    'type' => get_class($e),
                ],
            ];

            if ($isDebug && !$isChecksumMismatch) {
                $errorResponse['error']['file'] = $e->getFile();
                $errorResponse['error']['line'] = $e->getLine();
            }

            return $this->jsonResponse($errorResponse, $statusCode);
        } catch (\Throwable $e) {
            $this->logger->debug('ForgeWire error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $isDebug = env('APP_DEBUG', false);

            $errorResponse = [
                'error' => [
                    'message' => $isDebug ? $e->getMessage() : 'An error occurred processing your request.',
                    'type' => get_class($e),
                ],
            ];

            if ($isDebug) {
                $errorResponse['error']['file'] = $e->getFile();
                $errorResponse['error']['line'] = $e->getLine();
            }

            return $this->jsonResponse($errorResponse, 500);
        }
    }

    private function getRequestKey(array $payload): string
    {
        $id = $payload['id'] ?? '';
        $action = $payload['action'] ?? null;
        $args = $payload['args'] ?? [];
        $checksum = $payload['checksum'] ?? '';

        $argsJson = json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return md5("{$id}:{$action}:{$argsJson}:{$checksum}");
    }

    /**
     * Track that a component is currently active
     */
    private function trackActiveComponent(string $componentId): void
    {
        $activeKey = "forgewire:active:{$componentId}";
        $this->session->set($activeKey, time());
    }
}
