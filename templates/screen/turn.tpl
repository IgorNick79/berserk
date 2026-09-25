{{message}}
<h1>Бросок кубика</h1>

<div class="dice-block">
    <div class="dice-column">
        <div class="label">Ты</div>
        <div class="dice dice-{{my_dice}}">{{my_dice}}</div>
    </div>
    <div class="dice-column">
        <div class="label">Оппонент</div>
        <div class="dice dice-{{opp_dice}}">{{opp_dice}}</div>
    </div>
</div>

<p><b>{{first_text}}</b></p>

{{confirm_html}}