<?php

declare(strict_types=1);

namespace App\Modules\ForgeWire\Commands;

use App\Modules\ForgeWire\Services\ComponentCleanupService;
use Forge\CLI\Attributes\Cli;
use Forge\CLI\Command;
use Forge\CLI\Traits\OutputHelper;
use Forge\Core\Session\SessionInterface;

#[Cli(
    command: 'forgewire:cleanup',
    description: 'Remove stale ForgeWire component sessions',
    usage: 'forgewire:cleanup',
    examples: [
        'forgewire:cleanup'
    ]
)]
final class CleanupCommand extends Command
{
    use OutputHelper;

    public function __construct(
        private ComponentCleanupService $cleanupService,
        private SessionInterface $session
    ) {
    }

    public function execute(array $args): int
    {
        $ttl = $this->cleanupService->getTtlSeconds();

        if ($ttl <= 0) {
            $this->warning('Lazy cleanup is disabled (forgewire.component_ttl_seconds <= 0).');
            return 0;
        }

        $this->info('Cleaning up stale ForgeWire components (TTL: ' . $ttl . 's)...');

        $removed = $this->cleanupService->removeStale($this->session);

        if ($removed === 0) {
            $this->info('No stale components found.');
        } else {
            $this->success("Removed {$removed} stale component(s).");
        }

        return 0;
    }
}
