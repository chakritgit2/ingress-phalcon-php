<?php

namespace App\Tasks;

use Phalcon\Cli\Task;

/**
 * Invoked as: php app/console.php bot status|enable|disable
 * CLI counterpart to the devops-only toggle on /ingress, for pausing
 * KubernetesTask's scheduled work without web access.
 */
class BotTask extends Task
{
    public function statusAction(): void
    {
        $killSwitch = $this->settingsService->isEnvKillSwitchActive();
        $enabled = $this->settingsService->isBotEnabled();

        echo $enabled ? "bot: enabled\n" : "bot: disabled\n";

        if ($killSwitch) {
            echo "(forced off by BOT_ENABLED env var -- DB toggle has no effect until it's unset)\n";
        }
    }

    public function enableAction(): void
    {
        if ($this->settingsService->isEnvKillSwitchActive()) {
            fwrite(STDERR, "error: BOT_ENABLED env var forces the bot off -- unset it before enabling via the DB toggle\n");
            exit(1);
        }

        $this->settingsService->setBotEnabled(true, 'system:cli');
        echo "bot enabled\n";
    }

    public function disableAction(): void
    {
        $this->settingsService->setBotEnabled(false, 'system:cli');
        echo "bot disabled\n";
    }
}
