"""Reproducible inventory, not a claim that lexical references prove branch coverage.

Reads the frozen SELECT-only first-set fixture and source/test files. No DB access.
Run from repository root. JSON retains each definition and distinguishes an
addressed B.5 scenario from a test which merely mentions the same card.
"""
import collections
import functools
import hashlib
import json
import pathlib
import re

ROOT = pathlib.Path(__file__).resolve().parents[1]
FIXTURE = ROOT / 'tools/complex-s1-fixture.json'
cards = json.loads(FIXTURE.read_text(encoding='utf-8'))
assert {d['ukid'] for d in cards} == {f's1_{n}' for n in range(1, 201)}
sources = {str(p.relative_to(ROOT)).replace('\\', '/'): p.read_text(encoding='utf-8-sig')
           for p in (ROOT / 'src/Core').rglob('*.php')}
tests = {str(p.relative_to(ROOT)).replace('\\', '/'): p.read_text(encoding='utf-8-sig')
         for p in (ROOT / 'tools').glob('test_*.php')}

# Reviewed observer routes. No automatic copying of arbitrary ActionResolution.
ROUTES = {
    'health': ('общий результат', 'DamageResolver / CardHealing / TurnPhaseProcessor',
               'combat.wounds, combat.death, card.healed, health.*'),
    'move': ('общий результат', 'MovementResolver / MovementEffectResolver', 'movement.completed'),
    'zone': ('общий результат', 'ZoneManager + смысловой processor', 'zone.changed, card.transformed, cell_marker.changed'),
    'status': ('общий результат', 'Recorder::statusCheckpoint после native effect',
               'modifier.changed, marker.changed, characteristic.changed, status.*, capability.*, coins.*'),
    'attack': ('общий результат', 'ActionResolver / SingleRoll / StrikeResolver / CardRoll',
               'attack.declared, dice.*, attack.result / combat.*; health.*'),
    'rule': ('правило/условие', 'CardStats / LegalActions и native processors',
             'фактический результат действия; отдельное событие неизменного свойства не нужно'),
    'prep': ('подготовка', 'Engine / setup processors',
             'расстановка и исходные свойства намеренно вне текстового журнала'),
    'chain': ('собственный + общий', 'ValhallaProcessor / MatriarchOrderProcessor / ProphecyProcessor / OchiKronga',
              'chain.*, prophecy.*, series_end.started; общие результаты'),
    'partial': ('требует адресной проверки', 'см. lexical_handler_refs; результат может идти через общий writer',
                'покрытие конкретной ветви не доказано'),
}
ROOT_GROUPS = {
    'health': 'regeneration deadeat deadeat_hp_bonus vampire vampire_offset on_strike_self_heal on_heal_instead_open on_heal_instead_wound first_card_heal on_poison_damage',
    'move': 'on_move on_move_half force_opponent_directional_move on_move_heal_adjacent on_teleport_adjacent_bonus',
    'zone': 'incarnation tremor_on_graveyard on_ally_incarnation on_incarnation_token prophecy_transform',
    'status': 'on_hit_gain on_hit_marker lose_coins_on_move on_counter_threshold on_ally_strike_miss on_strike_ally_shield line_death_next_strike_bonus open_on_opponent_turn next_six_on_enemy_death_or_ally_incarnation',
    'attack': 'auto on_jump_roll ranged_block_roll turn_end_discharge on_discharge_self adjacent_move_wound_cheap',
    'chain': 'attack_series prophecy prophecy_block prophecy_charge prophecy_reorder prophecy_summon strike_prophecy ragnar_first_strike_unanswer ragnar_valhalla_double on_ally_action_sequence_open',
    'prep': 'start start_pre_battle pathfinder_rearrange hp_base hp_max_override save_coins coins',
    'rule': 'ability zoo direct damage_reduction zom strike unanswer ova ovz zoal zov zor zot zoz armor row_extreme has_line copy_target_strike block_weak_strike no_close_after_attack ranged_intercept defend_while_closed deal execute clumsy column_range_aura combat_choose_two aura_plains_ranged attacks_per_turn strike_consecutive strike_targets_unique movement_direction_bonus has_armor inherit_line_bonuses coin_block coins_receive_deny check can_attack_flying air_intercept airin_trigger strike_range_line fly_defend forced_strike attack_redirect line_strike_aura defender can_move_diagonal first_strike_jump block_strike_answer closed_strike_value horizontal_strike_reduction strike_reduction ignore',
    'partial': 'on_any_death on_death on_successful_strike on_successful_hit on_wounding_strike on_ranged_neighbor_wounded on_become_defender turn_start opponent_turn_start opponent_turn_end pre_turn_start',
}
ROOT_ROUTE = {k: g for g, values in ROOT_GROUPS.items() for k in values.split()}
ACTION_GROUPS = {
    'attack': 'shot throw uchr discharge multi_discharge magic tap cast paired_ranged strangle row_spell damage_ranged bomb_shot particle self_wound_strike damage_poisoned sand_claws',
    'health': 'heal wound_transfer blood_tap life_gift destroy_self_and_target execute',
    'move': 'jump teleport_target',
    'zone': 'revive dive become_fly',
    'status': 'grant_prop poison_boost poison_target give_coin steal_coin grant_modifier place_cell_marker mark_opponent_row steal_strike apply_delayed_marker dverg_gift',
    'chain': 'grezy_prophecy',
    'partial': 'impact blood_storm freeze_moves dissonance',
}
ACTION_ROUTE = {k: g for g, values in ACTION_GROUPS.items() for k in values.split()}

# Exact B.5 assertions; these are branch-specific, unlike lexical test references.
ADDRESSED = {
    ('s1_136', 'attack_series'): 'Hydra uncertified series end; Hydra two battles then voluntary end',
    ('s1_42', 'valhalla'): 'Ragnar double Valhalla s1_42; Ragnar cancel remaining after first',
    ('s1_45', 'valhalla'): 'Ragnar double Valhalla s1_45',
    ('s1_59', 'valhalla'): 'Ragnar double Valhalla s1_59',
    ('s1_62', 'valhalla'): 'Ragnar double Valhalla s1_62',
    ('s1_63', 'valhalla'): 'Ragnar own Valhalla passive',
    ('s1_63', 'ragnar_valhalla_double'): 'four real Valhalla definitions; remainder cancellation',
    ('s1_54', 'prophecy'): 'prophecy view frozen private 0/1; public summon',
    ('s1_104', 'prophecy_reorder'): 'Machinator actual reorder',
    ('s1_105', 'prophecy_summon'): 'prophecy viewed hidden deck then public summon',
    ('s1_122', 'prophecy_block'): 'prophecy block private shown card',
    ('s1_127', 'prophecy_charge'): 'prophecy charges',
    ('s1_28', 'actions'): 'Matriarch completed combat chain; uncommitted choice cancel',
    ('s1_102', 'on_strike_self_heal'): 'Kele native self heal',
    ('s1_178', 'on_ally_strike_miss'): 'Alvalind native miss threshold counter',
    ('s1_178', 'on_counter_threshold'): 'Alvalind native miss threshold counter',
    ('s1_91', 'on_move_heal_adjacent'): 'Wild healer movement then actual healing',
    ('s1_183', 'on_any_death'): 'Forrendor actual death choice accepted; optional decline',
    ('s1_124', 'on_any_death'): 'Seeder poison death target continuation',
    ('s1_93', 'on_ally_action_sequence_open'): 'Ochi three different forest resolutions (native ActionResolution hook; not three full HTTP actions)',
    ('s1_146', 'actions'): 'Blood storm actual hp bonus before choice',
    ('s1_57', 'actions'): 'Cryomancer actual limit and expiration',
    ('s1_161', 'incarnation'): 'Skeleton Worm actual return tunnel wound continuation',
    ('s1_161', 'tremor_on_graveyard'): 'Skeleton Worm return/consume existing tunnel; creation tested by B.3 and tremor regression',
    ('s1_118', 'instants'): 'Hypnosis nested turn instant forced combat',
}


def ability_rows(prop):
    for key, value in prop.items():
        if isinstance(value, list) and key in {'actions', 'instants', 'valhalla', 'auto', 'strike_effects', 'on_death', 'on_any_death', 'turn_start', 'opponent_turn_start', 'opponent_turn_end', 'pre_turn_start'}:
            for i, item in enumerate(value):
                yield f'{key}[{i}]', key, item
        else:
            yield key, key, value


@functools.lru_cache(maxsize=None)
def refs(token):
    needle = re.compile(r"['\"]" + re.escape(token) + r"['\"]")
    found = []
    for path, text in sources.items():
        if '/BattleHistory/' in path:
            continue
        hits = [i + 1 for i, line in enumerate(text.splitlines()) if needle.search(line)]
        if hits:
            found.append({'file': path, 'lines': hits})
    return found


inventory = []
for card in sorted(cards, key=lambda d: int(d['ukid'].split('_')[1])):
    prop = json.loads(card['prop'] or '{}')
    abilities = []
    for path, root, definition in ability_rows(prop):
        kind = definition.get('type', '') if isinstance(definition, dict) else ''
        group = ('chain' if root == 'valhalla' else
                 ACTION_ROUTE.get(kind, 'partial') if root == 'actions' else
                 'status' if root == 'instants' else ROOT_ROUTE.get(root, 'partial'))
        if root == 'actions' and isinstance(definition, dict) and definition.get('effect') == 'matriarch_order':
            group = 'chain'
        addressed = ADDRESSED.get((card['ukid'], root))
        # Do not equate another action on this card with testing every action.
        if root == 'instants' and kind != '' and isinstance(definition, dict) and definition.get('key') != 'hypnosis':
            addressed = None
        labels = ROUTES[group]
        abilities.append({
            'path': path, 'definition': definition, 'route': group,
            'coverage_kind': labels[0], 'observer_route': labels[1], 'expected_events': labels[2],
            'lexical_handler_refs': refs(kind if root == 'actions' and kind else root),
            'addressed_b5_scenario': addressed,
            'test_status': 'addressed_b5' if addressed else 'branch_not_proven_by_this_inventory',
            'limitations': ('Числовая дельта постоянно вычисляемой ауры не пересчитывается Recorder; результат действия остаётся общим.' if group == 'rule' else
                            'Подготовка/неизменный исходный параметр не требуют текстового события.' if group == 'prep' else
                            'Есть адресная проверка указанного сценария; не доказаны все комбинации целей, эффектов и скрытой информации.' if addressed else
                            'Наличие общего writer или ссылки на тест не доказывает прохождение именно этой ветви. Нужна адресная проверка.'),
        })
    inventory.append({'ukid': card['ukid'], 'name': card['name'], 'abilities': abilities,
                      'test_reference_candidates': [p for p, t in tests.items() if card['ukid'] in t]})
counts = collections.Counter(a['route'] for c in inventory for a in c['abilities'])
data = {'cards': len(inventory), 'ability_entries': sum(counts.values()),
        'fixture_sha256': hashlib.sha256(FIXTURE.read_bytes()).hexdigest(),
        'method': 'Reviewed route groups + lexical native handler references. Only explicitly mapped B.5 scenarios are branch evidence. Test references are candidates, never proof.',
        'route_counts': dict(counts), 'inventory': inventory}
(ROOT / 'docs/battle-stage4d3b5-coverage.json').write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n', encoding='utf-8')
lines = ['# Первый сет: инвентаризация Battle Journal B.5', '',
         f"200 определений; {data['ability_entries']} записей свойств/способностей. Fixture SHA256 `{data['fixture_sha256']}`.", '',
         'Каждый элемент actions/instants/valhalla/списка триггеров перечислен отдельно. Остальные свойства представлены целиком. Правила и исходные параметры тоже включены, чтобы ничего не потерять; это не число уникальных игровых способностей.', '',
         'JSON содержит точные определения, native ссылки file/line, путь observer, ожидаемые типы, адресные B.5 тесты и ограничения. Lexical-ссылка на тест/handler не означает проверку ветви. `branch_not_proven_by_this_inventory` — открытая задача адресного покрытия, а не доказанный дефект.', '',
         'Маршруты: ' + '; '.join(f'{k}: {v}' for k, v in sorted(counts.items())) + '.', '',
         '| Карта | Свойство/способность | Маршрут и ожидаемая запись | Адресный B.5 тест |',
         '|---|---|---|---|']
for card in inventory:
    for a in card['abilities']:
        detail = a['definition']
        label = detail.get('name', detail.get('key', detail.get('type', ''))) if isinstance(detail, dict) else ''
        lines.append(f"| {card['ukid']} {card['name']} | `{a['path']}` {label} | {a['coverage_kind']}: {a['expected_events']} | {a['addressed_b5_scenario'] or 'Не доказан; см. JSON и регрессии'} |")
(ROOT / 'docs/battle-stage4d3b5-coverage.md').write_text('\n'.join(lines) + '\n', encoding='utf-8')
print(json.dumps({'cards': len(inventory), 'entries': data['ability_entries'], 'route_counts': dict(counts)}))
