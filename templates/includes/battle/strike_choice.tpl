<p>{{prompt}}</p>
<form method="get" class="choice-form">
    <input type="hidden" name="{{role_param}}" value="">
    <input type="hidden" name="game" value="{{game_id}}">
    <input type="hidden" name="cmd" value="choose_strike_mode">
    <label class="choice-item">
        <input type="radio" name="mode" value="normal" checked>
        <span>Обычный: атакующий {{atk_normal}}, защитник {{def_normal}}</span>
    </label>
    <label class="choice-item">
        <input type="radio" name="mode" value="decrease">
        <span>Уменьшить: атакующий {{atk_dec}}, защитник {{def_dec}}</span>
    </label>
    <button type="submit" class="button">Подтвердить</button>
</form>