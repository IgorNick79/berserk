<div class="piles {{side}}">
    <div class="battle-side-slot battle-terrain" aria-label="{{terrain_label}}">
        <img src="/assets/images/battle/terrain_empty.webp" width="56" height="56" alt="" draggable="false">
    </div>
    <div class="battle-side-slot battle-grave">
        <span class="piles-label">Кладбище</span>
        <span class="piles-count">{{grave_count}}</span>
        <a class="pile-open" href="{{grave_url}}" aria-label="Подробнее: кладбище">Подробнее</a>
    </div>
    <div class="battle-side-slot battle-exile">
        <span class="piles-label">Изгнание</span>
        <span class="piles-count">{{exile_count}}</span>
        <a class="pile-open" href="{{exile_url}}" aria-label="Подробнее: изгнание">Подробнее</a>
    </div>
</div>
