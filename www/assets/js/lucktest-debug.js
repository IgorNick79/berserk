(function () {
    'use strict';
    function update(form) {
        var checkbox=form.querySelector('input[type="checkbox"][name="lucktest_quick_game"]'), group=form.querySelector('[data-lucktest-scenario]');
        var start=form.querySelector('input[name=lucktest_start_stage]:checked');
        if(!group || (!checkbox && !start))return;
        var enabled=start ? start.value==='battle' : checkbox.checked;
        group.hidden=!enabled;
        group.querySelector('textarea').disabled=!enabled;
    }
    document.querySelectorAll('form').forEach(function(form){update(form);form.addEventListener('change',function(){update(form);});});
    document.addEventListener('click',async function(event){
        var button=event.target.closest('[data-debug-copy]');if(!button)return;
        event.preventDefault();var block=button.closest('.debug-code'),text=block.querySelector('pre code').textContent,status=block.querySelector('[role="status"]');
        try{
            if(navigator.clipboard && window.isSecureContext)await navigator.clipboard.writeText(text);
            else{
                var input=document.createElement('textarea');input.value=text;input.style.cssText='position:fixed;left:-10000px;top:0';document.body.appendChild(input);input.select();
                try{if(!document.execCommand('copy'))throw Error('copy');}finally{input.remove();button.focus({preventScroll:true});}
            }
            status.textContent='Скопировано';
        }catch(error){status.textContent='Не удалось скопировать. Выделите пример и скопируйте вручную.';}
    });
}());
