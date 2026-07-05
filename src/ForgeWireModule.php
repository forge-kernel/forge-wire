<?php

declare(strict_types=1);

namespace Modules\ForgeWire;

use Forge\Core\Config\Config;
use Forge\Core\DI\Container;
use Forge\Core\Module\Attributes\Compatibility;
use Forge\Core\Module\Attributes\ConfigDefaults;
use Forge\Core\Module\Attributes\Module;
use Forge\Core\Module\Attributes\PostInstall;
use Forge\Core\Module\Attributes\PostUninstall;
use Forge\Core\Module\Attributes\Repository;
use Forge\Core\Module\Attributes\Requires;
use Forge\Core\ResetManager;
use Modules\ForgeRouter\Events\RouterHookAttribute;
use Modules\ForgeRouter\Events\RouterHookName;
use Modules\ForgeRouter\Http\Request;
use Modules\ForgeRouter\Http\Response;
use Modules\ForgeWire\Response\ForgeWireResponse;
use Forge\CLI\Traits\OutputHelper;
use Forge\Traits\InjectsAssets;

#[Module(
    name: "ForgeWire",
    version: "2.7.10",
    description: "A reactive controller rendering protocol for PHP",
    order: 99,
    author: 'Forge Team',
    license: 'MIT',
    type: 'reactive',
    tags: ['wire', 'reactive', 'rendering']
)]
#[Compatibility(framework: ">=6.0.23", php: ">=8.3")]
#[Requires(module: "forge-router")]
#[Repository(type: "git", url: "https://github.com/forge-kernel/kernel-module-registry")]
#[ConfigDefaults(defaults: [
    'forge_wire' => [
        'use_minified' => true,
        'stale_threshold' => 200,
        'component_ttl_seconds' => 1800,
    ]
])]
#[PostInstall(command: 'asset:link', args: ['--type=module', '--module=forge-wire'])]
#[PostUninstall(command: 'asset:unlink', args: ['--type=module', '--module=forge-wire'])]
final class ForgeWireModule
{
    use OutputHelper;
    use InjectsAssets;

    public function register(Container $container): void
    {
        $this->setupConfigDefaults($container);

        ResetManager::onBefore([ForgeWireResponse::class, 'clearAll']);
    }

    private function setupConfigDefaults(Container $container): void
    {
        /** @var Config $config */
        $config = $container->get(Config::class);
        $forgeWireConfig = $config->get('forge_wire');
        if (!$forgeWireConfig || !array_key_exists('use_minified', $forgeWireConfig)) {
            $config->set('forge_wire.use_minified', env('FORGE_WIRE_USE_MINIFIED', true));
        }
        $config->set('forge_wire.component_ttl_seconds', env('FORGEWIRE_COMPONENT_TTL_SECONDS', 1800));
    }

    #[RouterHookAttribute(RouterHookName::AFTER_REQUEST)]
    public function onAfterRequest(Request $request, Response $response): void
    {
        $this->registerWireAssets();
        $this->injectAssets($response);
    }

    private function registerWireAssets(): void
    {
        $css = '<style>[fw\:id] [fw\:loading] { display: none; } [fw\:id][fw\:loading] [fw\:loading], [fw\:id].fw-loading [fw\:loading] { display: block !important; }</style>';

        $config = Container::getInstance()->make(Config::class);
        $useMinified = $config->get('forge_wire.use_minified', true);
        $jsFile = $useMinified ? 'forgewire.min.js' : 'forgewire.js';
        $assetHtml = '<script src="/assets/modules/forge-wire/js/' . $jsFile . '" async></script>';

        $this->registerAsset(assetHtml: $css, beforeTag: '</head>');
        $this->registerAsset(assetHtml: $assetHtml, beforeTag: '</body>');
    }
}
