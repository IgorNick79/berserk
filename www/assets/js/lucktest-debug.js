(function () {
    'use strict';
    function update(form) {
        var checkbox=form.querySelector('input[type="checkbox"][name="lucktest_quick_game"]'), group=form.querySelector('[data-lucktest-scenario]');
        if(!checkbox || !group)return;
        group.hidden=!checkbox.checked;
        group.querySelector('textarea').disabled=!checkbox.checked;
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
