(()=>{
 'use strict';const form=document.querySelector('#create-wizard');if(!form)return;
 form.addEventListener('input',event=>{if(event.target.matches('[data-lucktest-cost]'))form.querySelector('[data-lucktest-cost-output]').textContent=event.target.value+' кр.';});
 form.addEventListener('change',event=>{if(event.target.matches('[data-handicap-toggle]'))form.querySelector('[data-handicap-fields]').hidden=!event.target.checked;});
 form.addEventListener('input',event=>{if(event.target.matches('[data-handicap-range]'))form.querySelector('[data-handicap-output='+event.target.dataset.handicapRange+']').textContent=event.target.value;});
 const pages=[...form.querySelectorAll('[data-page]')],tabs=[...form.querySelectorAll('.wizard-tabs [data-step]')],scroll=form.querySelector('.wizard-scroll'),error=form.querySelector('[data-wizard-error]');let step=0,busy=false,checking=false;
 const configuring=form.dataset.configure==='1';
 if(configuring)form.querySelector('[name=access][value='+form.dataset.access+']').checked=true;
 const names={opponent:'Решает противник',sealed:'Sealed',draft:'Grid Draft',glimpse:'Glimpse Draft',solomon:'Solomon Draft',lucktest:'LuckTest',system:'Системные колоды'};
 const mode=()=>form.querySelector('[name=setup_mode]:checked').value;
 const closed=()=>form.querySelector('[name=access]:checked').value==='closed';
 function updateSummary(){
  const summary=form.querySelector('[data-wizard-summary]');
  if(mode()==='opponent'){summary.textContent='Решает противник · '+(closed()?'Закрытая партия':'Открытая партия');return;}
  summary.textContent=names[mode()]+' · '+(closed()?(configuring?'Закрытая партия':'Закрытая партия для '+form.querySelector('[name=invited_login]').value):'Открытая партия')+' · '+window.BerserkRoom.timeLabel({turn_time:{mode:form.querySelector('[name=turn_time_mode]:checked').value,fixed_seconds:Number(form.querySelector('[name=turn_fixed_seconds]').value),creature_seconds:Number(form.querySelector('[name=turn_creature_seconds]').value)}});
  const enabled=form.querySelector('[data-handicap-toggle]').checked;
  const values=['gold','silver'].map(key=>enabled?Number(form.querySelector('[name=handicap_'+key+']').value):0);
  if(!values.some(value=>value>0)){summary.append(' · Без форы');return;}
  summary.append(' · ');const group=document.createElement('span');group.className='wizard-handicap-summary';group.append('Фора');
  values.forEach((value,index)=>{if(value<=0)return;const part=document.createElement('span');part.className='wizard-handicap-currency';const icon=document.createElement('img');icon.src='assets/images/card/'+(index===0?'elite':'ordinary')+'.png';icon.alt=index===0?'Золото':'Серебро';part.append(icon,document.createTextNode(String(value)));group.append(part);});summary.append(group);
 }
 function update(){const chosen=mode(),delegated=chosen==='opponent',last=delegated?1:2;
  tabs[2].hidden=delegated;form.querySelector('[data-wizard-progress]').textContent='Шаг '+(step+1)+' из '+(delegated?2:3);form.querySelector('[data-next]').hidden=step===last;form.querySelector('[data-create]').hidden=step!==last;form.querySelector('[data-create]').textContent=configuring?'Сохранить и начать игру':'Создать игру';
  form.querySelector('[name=access][value=open]').nextElementSibling.textContent=delegated?'Открытая игра':'Открытая партия';form.querySelector('[name=access][value=closed]').nextElementSibling.textContent=delegated?'Под замком':'🔒 Закрытая партия';
  form.querySelectorAll('[data-turn-time-fields]').forEach(block=>block.hidden=block.dataset.turnTimeFields!==form.querySelector('[name=turn_time_mode]:checked').value);
  const access=form.querySelector('[data-wizard-access]');access.hidden=configuring;access.disabled=configuring;const gameOptions=form.querySelector('[data-wizard-game-options]');gameOptions.hidden=delegated;gameOptions.disabled=delegated;form.querySelectorAll('[data-mode]').forEach(f=>{f.hidden=f.dataset.mode!==chosen;f.disabled=f.hidden;});const invite=form.querySelector('.wizard-invite'),input=form.querySelector('[name=invited_login]');invite.hidden=configuring||!closed();input.required=!configuring&&closed();input.disabled=configuring||!closed();input.setCustomValidity('');
  form.querySelector('[data-mode-title]').textContent='Настройки режима · '+names[chosen];updateSummary();
 }
 function show(next){step=next;pages.forEach((p,i)=>p.hidden=i!==step);tabs.forEach((t,i)=>t.setAttribute('aria-current',i===step?'step':'false'));form.querySelector('[data-wizard-progress]').textContent='Шаг '+(step+1)+' из 3';form.querySelector('[data-back]').textContent=step?'Назад':'В лобби';form.querySelector('[data-next]').hidden=step===2;form.querySelector('[data-create]').hidden=step!==2;form.querySelector('[data-next]').textContent=step===0?'Далее: общие настройки':'Далее: настройки режима';scroll.scrollTop=0;update();}
 function invalid(page){return [...pages[page].querySelectorAll('input,select,textarea')].find(i=>!i.disabled&&!i.closest('fieldset:disabled')&&!i.checkValidity());}
 async function move(next){if(busy||checking)return;if(mode()==='opponent'&&next>1)return;error.textContent='';if(next>step){for(let i=0;i<next;i++){const field=invalid(i);if(field){show(i);field.reportValidity();field.focus();return;}}
  if(next===2&&closed()&&!configuring){checking=true;try{const response=await fetch('lobby.php?api=1&check_invite=1&login='+encodeURIComponent(form.querySelector('[name=invited_login]').value),{credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok)throw Error(data.error||'Не удалось проверить приглашение.');}catch(e){show(1);error.textContent=e.message;error.focus();return;}finally{checking=false;}}
 }show(next);tabs[next].focus({preventScroll:true});}
 tabs.forEach(t=>t.addEventListener('click',()=>move(Number(t.dataset.step))));form.querySelector('[data-next]').addEventListener('click',()=>move(step+1));form.querySelector('[data-back]').addEventListener('click',()=>{if(!busy){if(step)move(step-1);else location.assign('lobby.php');}});
 form.addEventListener('change',update);form.addEventListener('input',updateSummary);
 form.addEventListener('submit',async event=>{event.preventDefault();if(busy||checking)return;if(step<(mode()==='opponent'?1:2)){move(step+1);return;}for(let i=0;i<3;i++){const field=invalid(i);if(field){show(i);field.reportValidity();field.focus();return;}}
  busy=true;error.textContent='';const submit=form.querySelector('[data-create]');submit.disabled=true;tabs.forEach(t=>t.disabled=true);try{const params=new URLSearchParams(new FormData(form));const fields=document.getElementById('wizard-fields-'+mode());if(fields)new FormData(fields).forEach((v,k)=>params.append(k,v));const endpoint=new URL(form.getAttribute('action'),location.href);endpoint.searchParams.set('api','1');const response=await fetch(endpoint,{method:'POST',body:params,credentials:'same-origin',cache:'no-store'});const data=await response.json();if(!response.ok)throw Error(data.error||'Не удалось создать игру.');location.assign(data.url);}catch(e){error.textContent=e.message;error.focus();busy=false;submit.disabled=false;tabs.forEach(t=>t.disabled=false);}
 });
 form.querySelector('[data-withdraw-configuration]')?.addEventListener('click',async()=>{if(busy)return;busy=true;try{const endpoint=new URL(form.getAttribute('action'),location.href);endpoint.searchParams.set('api','1');const params=new URLSearchParams({action:'withdraw',csrf:form.querySelector('[name=csrf]').value,version:form.querySelector('[name=version]').value,candidate_request_id:form.querySelector('[name=candidate_request_id]').value});const response=await fetch(endpoint,{method:'POST',body:params,credentials:'same-origin'});const data=await response.json();if(!response.ok)throw Error(data.error||'Не удалось отменить участие.');location.assign(data.url);}catch(e){error.textContent=e.message;}finally{busy=false;}});
 update();show(0);
})();

(function () {
    function update() {
        document.querySelectorAll('[data-glimpse-progressive]').forEach(function (field) {
            var form = field.closest('form') || field.closest('[data-mode]');
            var selected = form && form.querySelector('[name="draft_burn_mode"]:checked');
            field.hidden = !selected || selected.value !== 'progressive';
        });
    }
    document.addEventListener('change', update); update();
}());
