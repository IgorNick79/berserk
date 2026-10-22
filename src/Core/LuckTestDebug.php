<?php
declare(strict_types=1);
namespace Berserk\Core;

use Berserk\Auth\DebugAccess;
use Berserk\Core\Prepare\LuckTestScenario;

/** Private preparation input; no payload field can supply a permission or an account identity. */
final class LuckTestDebug
{
    public static function hasInput(Command $cmd): bool
    {
        return $cmd->type === 'set_lucktest_debug' || array_key_exists('lucktest_scenario', $cmd->payload) || array_key_exists('debug_roll', $cmd->payload);
    }

    public static function configure(GameState $state, string $actor, Command $cmd): void
    {
        if (!self::hasInput($cmd)) return;
        if ($state->mode !== GameSettings::MODE_LUCKTEST || $state->status !== 'settings' || $state->cards !== []
            || !in_array($cmd->type, ['confirm_settings','set_lucktest_debug'], true)
            || !isset($state->players[$actor]) || !DebugAccess::allowsId($state->players[$actor]->userId)) {
            throw new \DomainException('Debug недоступен для этого аккаунта, режима или стадии');
        }
        $id = $state->players[$actor]->userId;
        if (array_key_exists('lucktest_scenario', $cmd->payload)) {
            if ($actor !== 'host') throw new \DomainException('Сценарий LuckTest задаёт только хост');
            // confirm_settings is parsed by PrepareProcessor; validate its effective K/quick flag in scenario().
            $confirming = $cmd->type === 'confirm_settings';
            $entries = LuckTestScenario::parse($cmd->payload['lucktest_scenario'], $confirming ? 10 : $state->settings->lucktest['card_count']);
            if (!$confirming && $entries !== [] && $state->settings->lucktest['quick_game'] !== true) throw new \DomainException('Сценарий LuckTest доступен только в быстрой игре');
            if ($entries === []) unset($state->lucktestDebug['scenario'], $state->lucktestDebug['scenario_account_id'], $state->lucktestDebug['bindings']);
            else { $state->lucktestDebug['scenario'] = $entries; $state->lucktestDebug['scenario_account_id'] = $id; }
        }
        if (array_key_exists('debug_roll', $cmd->payload)) {
            $raw = self::rollInput($cmd->payload['debug_roll']);
            if ($raw === 'off') unset($state->lucktestDebug['rolls'][(string)$id]);
            else $state->lucktestDebug['rolls'][(string)$id] = ['game_id'=>$state->gameId,'account_id'=>$id,'raw'=>$raw,'state'=>null];
        }
    }

    public static function rollInput(mixed $raw): string
    {
        if (!is_string($raw) || strlen($raw) > 1024) throw new \DomainException('Debug: неверная очередь бросков');
        $raw = trim($raw);
        if ($raw === '') return 'off';
        if (!preg_match('/^(?:off|[1-6]\*|[1-6](?:\s*,\s*[1-6])*)$/D', $raw)) throw new \DomainException('Debug: используйте 6,1,4, 6* или off');
        return $raw;
    }

    public static function scenario(GameState $state, GameSettings $settings): array
    {
        $entries = $state->lucktestDebug['scenario'] ?? [];
        if ($entries === []) return [];
        if (($state->lucktestDebug['scenario_account_id'] ?? null) !== $state->hostId || !DebugAccess::allowsId($state->hostId)) {
            throw new \DomainException('Сценарий LuckTest: разрешение хоста отозвано');
        }
        if ($settings->lucktest['quick_game'] !== true) throw new \DomainException('Сценарий LuckTest доступен только в быстрой игре');
        return LuckTestScenario::parse(json_encode(['cards'=>$entries], JSON_THROW_ON_ERROR), $settings->lucktest['card_count']);
    }
}
