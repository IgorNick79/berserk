<?php
declare(strict_types=1);

namespace Berserk\Core\Prepare;

use Berserk\Core\GameSettings;

/** Bounded random search over catalog snapshots. No recruitment rules or state mutations. */
final class LuckTestGenerator
{
    private const ATTEMPTS = 64;
    private const REPLACEMENTS_PER_CARD = 16;

    public function __construct(private ?\Closure $randomInt = null) {}

    /** @return array{host:array,player:array} Grouped deckCards snapshots, ready for materialization. */
    public function generate(array $catalog, GameSettings $settings, array $fixed = []): array
    {
        $error = $settings->validateLuckTestSettings();
        if ($error !== null) throw new \InvalidArgumentException($error);
        [$catalog, $ordinary, $special] = $this->catalogGroups($catalog);

        $count = $settings->lucktest['card_count'];
        $target = $count * $settings->lucktest['average_cost'];
        // null: unrestricted. false/true: BOTH armies must have the same special-presence bit.
        $categories = [null];
        if ($settings->lucktest['balanced']) {
            $categories = [];
            if ($ordinary !== []) $categories[] = false;
            if ($special !== []) $categories[] = true;
        }
        foreach (['host','player'] as $owner) {
            $fixed[$owner] ??= [];
            if (count($fixed[$owner]) > $count) throw new \DomainException('Сценарий LuckTest: обязательных карт больше K');
        }
        $best = null;
        $bestScore = INF;
        foreach ($categories as $category) {
            $possible = true;
            foreach (['host','player'] as $owner) {
                $has = self::hasSpecial($fixed[$owner]);
                if (($category === false && $has) || ($category === true && !$has && count($fixed[$owner]) === $count)) $possible = false;
            }
            if (!$possible) continue;
            $host = $this->search($catalog, $ordinary, $special, $count, $target, $category, $fixed['host']);
            $player = $this->search($catalog, $ordinary, $special, $count, $target, $category, $fixed['player']);
            if ($settings->lucktest['balanced'] && !self::isBalanced($host, $player)) {
                throw new \RuntimeException('Не удалось сбалансировать LuckTest');
            }
            $score = abs($this->cost($host) - $target) + abs($this->cost($player) - $target);
            if ($best === null || $score < $bestScore || ($score === $bestScore && $this->random(0, 1) === 1)) {
                $best = ['host' => $this->group($host), 'player' => $this->group($player)];
                $bestScore = $score;
            }
        }
        if ($best === null) throw new \DomainException('Сценарий LuckTest: балансировка обязательных карт невозможна');
        return $best;
    }

    /** Random reserve order is the creation/instanceId order consumed by ProphecyProcessor. */
    public function generateReserve(array $catalog, int $count, array $fixed = []): array
    {
        if ($count < 0 || $count > GameSettings::MIN_DECK_SIZE) throw new \InvalidArgumentException('Неверный размер резерва LuckTest');
        [$catalog] = $this->catalogGroups($catalog);
        if (count($fixed)>$count) throw new \DomainException('Обязательных карт больше размера резерва LuckTest');
        $result = array_map(static function(array $card): array { $card['count']=1; return $card; }, $fixed);
        for ($i = count($result); $i < $count; $i++) {
            $card = $this->pick($catalog);
            $card['count'] = 1;
            $result[] = $card;
        }
        return $result;
    }

    private function catalogGroups(array $catalog): array
    {
        if ($catalog === []) throw new \RuntimeException('Пустой каталог LuckTest');
        $catalog = array_values($catalog);
        $special = [];
        $ordinary = [];
        $seen = [];
        foreach ($catalog as $card) {
            if (!is_array($card) || !is_string($card['ukid'] ?? null) || $card['ukid'] === ''
                || isset($seen[$card['ukid']]) || !is_int($card['price'] ?? null) || $card['price'] < 0
                || !is_string($card['type'] ?? null) || $card['type'] === '' || !is_array($card['prop'] ?? null)
                || (isset($card['prop']['actions']) && !is_array($card['prop']['actions']))) {
                throw new \RuntimeException('Некорректная карта LuckTest');
            }
            $seen[$card['ukid']] = true;
            if (self::isSpecial($card)) $special[] = $card;
            else $ordinary[] = $card;
        }

        return [$catalog, $ordinary, $special];
    }

    public static function isSpecial(array $card): bool
    {
        if (($card['type'] ?? null) === 'fly') return true;
        foreach (($card['prop']['actions'] ?? []) as $action) {
            if (is_array($action) && in_array($action['type'] ?? null, ['shot', 'throw', 'discharge'], true)) return true;
        }
        return false;
    }

    public static function isBalanced(array $host, array $player): bool
    {
        return self::hasSpecial($host) === self::hasSpecial($player);
    }

    private static function hasSpecial(array $cards): bool
    {
        foreach ($cards as $card) if (self::isSpecial($card)) return true;
        return false;
    }

    private function search(array $catalog, array $ordinary, array $special, int $count, float $target, ?bool $category, array $fixed = []): array
    {
        $pool = $category === false ? $ordinary : $catalog;
        $best = [];
        $bestError = INF;
        for ($attempt = 0; $attempt < self::ATTEMPTS; $attempt++) {
            $cards = $fixed;
            for ($i = count($fixed); $i < $count; $i++) $cards[] = $this->pick($pool);
            if ($category === true && !self::hasSpecial($cards)) $cards[$this->random(count($fixed), $count - 1)] = $this->pick($special);
            $total = $this->cost($cards);
            // Random one-card improvements retain repeats and never require exact budget equality.
            for ($i = count($fixed); $i < $count; $i++) {
                for ($j = 0; $j < self::REPLACEMENTS_PER_CARD; $j++) {
                    $replacement = $this->pick($pool);
                    $previous = $cards[$i];
                    $nextTotal = $total - $previous['price'] + $replacement['price'];
                    if (abs($nextTotal - $target) > abs($total - $target)) continue;
                    $cards[$i] = $replacement;
                    if ($category === true && !self::hasSpecial($cards)) {
                        $cards[$i] = $previous;
                        continue;
                    }
                    $total = $nextTotal;
                }
            }
            $error = abs($total - $target);
            if ($best === [] || $error < $bestError || ($error === $bestError && $this->random(0, 1) === 1)) {
                $best = $cards;
                $bestError = $error;
            }
        }
        return $best;
    }

    private function cost(array $cards): int { return array_sum(array_column($cards, 'price')); }
    private function pick(array $cards): array { return $cards[$this->random(0, count($cards) - 1)]; }
    private function random(int $min, int $max): int
    {
        return $this->randomInt !== null ? ($this->randomInt)($min, $max) : random_int($min, $max);
    }

    private function group(array $cards): array
    {
        $result = [];
        foreach ($cards as $card) {
            $key = $card['ukid'];
            if (!isset($result[$key])) {
                $result[$key] = $card;
                $result[$key]['count'] = 0;
            }
            $result[$key]['count']++;
        }
        return array_values($result);
    }
}
