<?php

namespace App\Services;

use App\Models\Settings;

/**
 * DB-backed toggles, currently just the ingress "bot" (KubernetesTask) kill
 * switch. `BOT_ENABLED` in the environment is a hard override on top of the
 * DB value — set only at the infra level (.env / k8s ConfigMap), it always
 * wins so the bot can't be re-enabled from the UI/CLI without also changing
 * the deployment's env.
 */
class SettingsService
{
    private const BOT_ENABLED_KEY = 'bot_enabled';

    private AuditLogService $auditLogService;

    public function __construct(AuditLogService $auditLogService)
    {
        $this->auditLogService = $auditLogService;
    }

    public function isEnvKillSwitchActive(): bool
    {
        $value = getenv('BOT_ENABLED');
        return $value !== false && in_array(strtolower($value), ['0', 'false', 'off', 'no'], true);
    }

    public function isBotEnabled(): bool
    {
        if ($this->isEnvKillSwitchActive()) {
            return false;
        }

        $row = $this->findSetting(self::BOT_ENABLED_KEY);

        return $row === null || $row->setting_value === '1';
    }

    public function setBotEnabled(bool $enabled, string $actorLabel, ?int $actorUserId = null): void
    {
        $row = $this->findSetting(self::BOT_ENABLED_KEY);

        if ($row === null) {
            $row = new Settings();
            $row->setting_key = self::BOT_ENABLED_KEY;
        }

        $row->setting_value = $enabled ? '1' : '0';
        $row->save();

        $this->auditLogService->log($enabled ? 'bot_enabled' : 'bot_disabled', $actorLabel, [
            'actor_user_id' => $actorUserId,
        ]);
    }

    private function findSetting(string $key): ?Settings
    {
        return Settings::findFirst([
            'conditions' => 'setting_key = :key:',
            'bind' => ['key' => $key],
        ]);
    }
}
