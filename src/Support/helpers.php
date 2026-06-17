<?php

declare(strict_types=1);

if (!function_exists('fw_id')) {
    function fw_id(string $id): string
    {
        $idAttr = 'fw:id="' . htmlspecialchars($id) . '"';
        $checksumAttr = '';

        $modulePath = BASE_PATH . '/modules/ForgeWire/src/ForgeWireModule.php';
        if (!is_file($modulePath) || !class_exists('\App\Modules\ForgeWire\ForgeWireModule')) {
            return $idAttr;
        }

        try {
            $router = \App\Modules\ForgeRouter\Routing\Router::getInstance();
            $route = $router->getCurrentRoute();
            if ($route && isset($route['controller'])) {
                $container = \Forge\Core\DI\Container::getInstance();
                $serviceClass = '\App\Modules\ForgeWire\Services\ComponentIdentityService';
                $servicePath = BASE_PATH . '/modules/ForgeWire/src/Services/ComponentIdentityService.php';

                if (is_file($servicePath) && class_exists($serviceClass)) {
                    $routePath = $route['path'] ?? ($route['pattern'] ?? '');
                    $isWire = ($route['controller'] === \App\Modules\ForgeWire\Controllers\WireController::class || $routePath === '/__wire');

                    if (!$isWire) {
                        $identityService = $container->make($serviceClass);
                        $checksumAttr = $identityService->getFingerprint($id, $route['controller'], $route['method'] ?? 'index');
                    }
                }
            }
        } catch (\Throwable $e) {
        }
        return $idAttr . $checksumAttr;
    }
}
