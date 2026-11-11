(function () {
  'use strict';
  const C=window.TrainCalendar, tg=window.Telegram?.WebApp, $=id=>document.getElementById(id);
  const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
  const en=s=>String(s).replace(/[۰-۹٠-٩]/g,c=>String(c.charCodeAt(0)>1775?c.charCodeAt(0)-1776:c.charCodeAt(0)-1632));
  const norm=s=>en(s).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s‌]/g,'').toLowerCase();
  const months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  let boot, bootAt=0, today, todayDate, month, year, step=1, view='create', count=1, cityTarget, busy=false, editTrip=null, createdTrip=null, deletingTrip=null;
  let initialCheckRun=0;
  let alertsReturn='trips', draftFields={};
  const draftFieldIds=['time-from','time-to','min-price','max-price','companies'];
  let draft={origin:'',destination:'',date:'',count:1,type:0,coupe:false,allow_partial_capacity:false,search_filters:{}}, requestId='', lockedPayload=null;
  let catalog=null, mode='all', selected=new Set(), excluded=new Set(), alertDirty=false, preferencesDirty=false, pendingAlertOperation=null, alertSaveStatus='saved', alertSyncUnknown=false;
  let trainFilter='all';
  const modeLabel=trip=>trip.mode==='except'&&Number.isInteger(trip.excluded_count)?fa(trip.excluded_count)+' قطار خاموش'
    :trip.mode==='selected'&&Number.isInteger(trip.selected_count)?fa(trip.selected_count)+' قطار انتخابی'
    :({all:'همه روشن',except:'چند قطار خاموش',selected:'قطارهای انتخابی',none:'هشدار خاموش'}[trip.mode]||'همه روشن');
  let theme=tg?.colorScheme||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
  try{theme=localStorage.getItem('hamsafar-theme')||theme;}catch(_){}if(!['light','dark'].includes(theme))theme='light';
  function applyTheme(){document.documentElement.dataset.theme=theme;tg?.setHeaderColor?.(theme==='dark'?'#202329':'#ffffff');tg?.setBackgroundColor?.(theme==='dark'?'#202329':'#ffffff');}
  applyTheme();$('theme-toggle').onclick=()=>{theme=theme==='dark'?'light':'dark';applyTheme();try{localStorage.setItem('hamsafar-theme',theme);}catch(_){}};
  function notice(text,error=false){$('notice').textContent=text;$('notice').className='notice'+(error?' error':'');$('notice').hidden=!text;}
  function formError(text){$('form-error').textContent=text;$('form-error').hidden=!text;if(text)$('form-error').scrollIntoView({block:'center'});}
  function setBusy(value,scope='main'){busy=value;$('main').inert=value&&scope==='main';$('main').setAttribute('aria-busy',String(value));document.querySelectorAll('#continue,#save-alerts,#refresh-trains').forEach(b=>b.disabled=value);document.querySelectorAll('.train-control input,[name=alert-mode]').forEach(input=>input.disabled=value);document.querySelectorAll('[data-nav]').forEach(b=>b.disabled=value);}
  async function api(action,extra={}){
    if(!tg?.initData)throw new Error('برای ورود امن، مینی‌اپ را از دکمهٔ داخل ربات باز کن.');
    const controller=new AbortController(), timer=setTimeout(()=>controller.abort(),40000);
    try{
      const response=await fetch(new URL('telegram-app.php?api=1',location.href),{method:'POST',headers:{'Content-Type':'application/json'},cache:'no-store',credentials:'same-origin',signal:controller.signal,body:JSON.stringify({action,init_data:tg.initData,...extra})});
      const result=await response.json();
      if(!response.ok||!result.ok){const error=new Error(result.error||'ارتباط برقرار نشد؛ دوباره تلاش کن.');error.status=response.status;throw error;}
      return result.data;
    }catch(error){if(error.name==='AbortError')throw new Error('پاسخ سرور طول کشید؛ دوباره تلاش کن. اطلاعاتت محفوظ است.');throw error;}finally{clearTimeout(timer);}
  }
  function cityName(code){return boot.cities.find(c=>c.code===code)?.text||'انتخاب شهر';}
  function dateText(value){if(!/^\d{4}-\d{2}-\d{2}$/.test(value))return fa(value);const [y,m,d]=value.split('-').map(Number);return `${fa(d)} ${months[m-1]} ${fa(y)}`;}
  function routeText(trip=draft){if(trip.route)return trip.route_title;return `${cityName(trip.origin)} به ${cityName(trip.destination)}`;}
  function storageKey(){return 'hamsafar-draft-v1-'+boot.user_id;}
  function persist(){if(editTrip)return;try{sessionStorage.setItem(storageKey(),JSON.stringify({draft,requestId,lockedPayload,fields:draftFields}));}catch(_){} }
  function restore(){try{const state=JSON.parse(sessionStorage.getItem(storageKey()));if(state?.draft&&typeof state.draft==='object'){
    const t=state.draft;if(boot.cities.some(c=>c.code===t.origin))draft.origin=t.origin;if(boot.cities.some(c=>c.code===t.destination))draft.destination=t.destination;
    if(typeof t.date==='string'&&t.date>=today&&/^\d{4}-\d{2}-\d{2}$/.test(t.date))draft.date=t.date;
    if(Number.isInteger(t.count)&&t.count>=1&&t.count<=12)draft.count=t.count;if([0,1,2].includes(t.type))draft.type=t.type;
    draft.coupe=t.coupe===true;draft.allow_partial_capacity=t.allow_partial_capacity===true;if(t.search_filters&&typeof t.search_filters==='object')draft.search_filters=t.search_filters;
    if(typeof state.requestId==='string')requestId=state.requestId;if(typeof state.lockedPayload==='string')lockedPayload=state.lockedPayload;
    for(const id of draftFieldIds){if(typeof state.fields?.[id]==='string'&&state.fields[id].length<=300)draftFields[id]=state.fields[id];}
  }}catch(_){} }
  function rememberDraftFields(){
    if(editTrip)return;
    draftFields=Object.fromEntries(draftFieldIds.map(id=>[id,$(id).value]));
    Object.assign(draft,{count,type:Number(document.querySelector('[name=quota]:checked').value),coupe:$('coupe').checked,allow_partial_capacity:$('partial').checked});
    try{draft.search_filters=readFilters();}catch(_){}persist();
  }
  function fillDraftFields(){fillFields(draft);for(const [id,value] of Object.entries(draftFields))$(id).value=value;}
  function syncBack(){
    const label=view==='create'&&!editTrip&&step>1?'مرحلهٔ قبل':'بازگشت به سفرها';
    $('page-navigation').hidden=!boot||!['create','success'].includes(view);
    $('page-back-label').textContent=label;$('back').textContent=label;$('back').hidden=false;
    $('alerts-back').textContent=alertsReturn==='success'?'بازگشت به نتیجهٔ ثبت':'بازگشت به سفرها';
    const hasDialog=['city-dialog','date-dialog','delete-dialog'].some(id=>$(id).open);
    if(boot&&(view!=='trips'||hasDialog))tg?.BackButton?.show?.();else tg?.BackButton?.hide?.();
  }
  function closeDialog(id){
    if(busy)return;
    $(id).close();if(id==='delete-dialog')deletingTrip=null;
    $(id==='city-dialog'?cityTarget:id==='date-dialog'?'open-calendar':'trips-title')?.focus();syncBack();
  }
  function closeDiagnostics(){
    $('notification-report').hidden=true;$('diagnostics-hint').hidden=true;$('close-diagnostics').hidden=true;
    $('notification-diagnostics').focus();
  }
  function goBack(){
    if(!boot||busy)return;
    const dialog=['delete-dialog','date-dialog','city-dialog'].find(id=>$(id).open);
    if(dialog){closeDialog(dialog);return;}
    if(view==='alerts'&&!$('notification-report').hidden){closeDiagnostics();return;}
    if(view==='create'&&!editTrip&&step>1){if(step===2)rememberDraftFields();setStep(step-1);return;}
    if(view==='alerts'){showView(alertsReturn);return;}
    if(view==='create'||view==='success')showView('trips');
  }
  function markClose(){if(tg?.enableClosingConfirmation){alertDirty||preferencesDirty?tg.enableClosingConfirmation():tg.disableClosingConfirmation();}}
  function mayLeave(){return !alertDirty||window.confirm('ثبت تغییر آخر تأیید نشده است. خارج می‌شوی؟');}
  function showView(next){
    if(busy)return false;if(view==='alerts'&&next!=='alerts'&&!mayLeave())return false;
    if(view==='create'&&editTrip&&preferencesDirty&&!window.confirm('ترجیحات سفر ذخیره نشده است. بدون ذخیره خارج می‌شوی؟'))return false;
    if(view==='create'&&next!=='create'&&!editTrip&&step===2)rememberDraftFields();
    if(view==='create'&&next!=='create')editTrip=null;
    preferencesDirty=false;
    alertDirty=false;pendingAlertOperation=null;alertSaveStatus='saved';markClose();view=next;document.documentElement.dataset.view=next;notice('');
    ['create','trips','alerts','success'].forEach(v=>$(v+'-view').hidden=v!==next);
    document.querySelectorAll('[data-nav]').forEach(b=>{b.classList.toggle('active',b.dataset.nav===next);b.setAttribute('aria-current',b.dataset.nav===next?'page':'false');});
    syncBack();window.scrollTo({top:0});const title=$(next+'-title')||document.querySelector('#success-view h1');title?.focus({preventScroll:true});return true;
  }
  function setStep(value){step=value;[1,2,3].forEach(n=>{$('step-'+n).hidden=n!==step;const item=document.querySelector(`[data-step="${n}"]`);item.classList.toggle('active',n<=step);if(n===step)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
    syncBack();$('continue').textContent=editTrip?'ذخیرهٔ ترجیحات':step===1?'ادامه':step===2?'مرور سفر':'ثبت سفر';
    $('create-title').textContent=editTrip?'تنظیمات سفر':step===1?'سفر جدید':step===2?'ترجیحات سفر':'مرور و ثبت';
    $('create-intro').textContent=editTrip?'تنظیمات برای همین مسیر و تاریخ اعمال می‌شوند.':step===1?'مسیر و تاریخ سفر را مشخص کن.':step===2?'شرایط بلیط مناسب سفرت را مشخص کن.':'جزئیات را بررسی و سفر را ثبت کن.';
    $('route-strip').textContent=`${editTrip?routeText(editTrip):routeText()} · ${dateText(editTrip?.date||draft.date)}`;
    formError('');if(step===3)renderReview();window.scrollTo({top:0});
  }
  function renderCalendar(){
    $('month-title').textContent=`${months[month-1]} ${fa(year)}`;$('calendar-days').replaceChildren();
    const days=C.month(year,month), first=days[0];for(let i=0;i<first.weekday;i++)$('calendar-days').append(document.createElement('span'));
    days.forEach(d=>{const button=document.createElement('button');button.type='button';button.textContent=fa(d.day);button.dataset.date=d.key;
      button.disabled=d.key<today;button.classList.toggle('selected',draft.date===d.key);button.classList.toggle('today',today===d.key);
      button.setAttribute('aria-label',dateText(d.key)+(today===d.key?'، امروز':''));button.setAttribute('aria-pressed',String(draft.date===d.key));
      button.onclick=()=>{draft.date=d.key;persist();renderCalendar();closeDialog('date-dialog');};$('calendar-days').append(button);});
    $('previous-month').disabled=year<Number(today.slice(0,4))||(year===Number(today.slice(0,4))&&month<=Number(today.slice(5,7)));
    $('next-month').disabled=year>=Number(today.slice(0,4))+2;
    $('date-caption').textContent=draft.date?'تاریخ حرکت · '+dateText(draft.date):'روز حرکت را انتخاب کن';
    $('selected-date').textContent=draft.date?dateText(draft.date):'انتخاب از تقویم شمسی';
    document.querySelectorAll('[data-quick]').forEach(b=>b.classList.toggle('chosen',b.dataset.quick===draft.date));
  }
  function renderQuick(){$('quick-dates').replaceChildren();[0,1,7].forEach((offset,i)=>{const date=C.key(C.parts(new Date(todayDate.getTime()+offset*C.dayMs))),button=document.createElement('button');button.type='button';button.dataset.quick=date;button.textContent=['امروز','فردا','هفتهٔ بعد'][i];button.onclick=()=>{syncToday();draft.date=C.key(C.parts(new Date(todayDate.getTime()+offset*C.dayMs)));[year,month]=draft.date.split('-').map(Number);persist();renderCalendar();};$('quick-dates').append(button);});}
  function syncToday(){
    if(!boot||!Number.isInteger(boot.server_time))return;
    const now=new Date(boot.server_time*1000+Math.max(0,Date.now()-bootAt));
    const p=Object.fromEntries(new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(now).map(x=>[x.type,x.value]));
    const date=new Date(`${p.year}-${p.month}-${p.day}T12:00:00Z`),key=C.key(C.parts(date));if(key===today)return;
    today=key;todayDate=date;if(draft.date&&draft.date<today){draft.date='';persist();notice('روز عوض شده است؛ تاریخ حرکت را دوباره انتخاب کن.');}
    if(`${year}-${String(month).padStart(2,'0')}`<today.slice(0,7))[year,month]=today.split('-').map(Number);
    renderQuick();renderCalendar();
  }
  function changeMonth(delta){month+=delta;if(month===13){month=1;year++;}if(month===0){month=12;year--;}renderCalendar();}
  function renderCities(){const query=norm($('city-search').value),other=draft[cityTarget==='origin'?'destination':'origin'];$('city-list').replaceChildren();
    const cities=boot.cities.filter(c=>norm(c.text).includes(query));cities.forEach(city=>{const b=document.createElement('button');b.type='button';b.textContent=city.text+(city.code===other?' · '+(cityTarget==='origin'?'مقصد فعلی':'مبدأ فعلی'):'');b.disabled=city.code===other;b.onclick=()=>{draft[cityTarget]=city.code;persist();renderStations();closeDialog('city-dialog');};$('city-list').append(b);});
    if(!cities.length){const p=document.createElement('p');p.className='empty';p.textContent='این شهر در فهرست ایستگاه‌های فعلی نیست.';$('city-list').append(p);}}
  function openCity(target){cityTarget=target;$('city-title').textContent=target==='origin'?'انتخاب مبدأ':'انتخاب مقصد';$('city-search').value='';renderCities();$('city-dialog').showModal();syncBack();$('city-search').focus();}
  function renderStations(){$('origin-name').textContent=draft.origin?cityName(draft.origin):'انتخاب شهر مبدأ';$('destination-name').textContent=draft.destination?cityName(draft.destination):'انتخاب شهر مقصد';}
  function renderCount(){$('count').textContent=fa(count);$('minus').disabled=count<=1||!!editTrip;$('plus').disabled=count>=12||!!editTrip;}
  function fillFields(trip){count=trip.count;renderCount();$('partial').checked=trip.allow_partial_capacity;$('coupe').checked=trip.coupe;
    document.querySelectorAll('[name=quota]').forEach(i=>{i.checked=Number(i.value)===trip.type;i.disabled=!!editTrip;});$('coupe').disabled=!!editTrip;
    const f=trip.search_filters||{};document.querySelectorAll('[name=train-type]').forEach(i=>i.checked=(f.train_types||[]).includes(i.value));
    $('time-from').value=f.departure_from||'';$('time-to').value=f.departure_to||'';$('min-price').value=f.min_price===undefined?'':fa(f.min_price);$('max-price').value=f.max_price===undefined?'':fa(f.max_price);$('companies').value=(f.companies||[]).join('، ');
    $('advanced').open=Object.keys(f).length>0;
  }
  function readFilters(){const f={},types=[...document.querySelectorAll('[name=train-type]:checked')].map(i=>i.value);if(types.length)f.train_types=types;
    const from=$('time-from').value,to=$('time-to').value;if(from||to){if(!from||!to)throw new Error('ابتدا و انتهای بازهٔ ساعت را مشخص کن.');f.departure_from=from;f.departure_to=to;}
    for(const [id,key] of [['min-price','min_price'],['max-price','max_price']]){const text=en($(id).value).trim();if(text){if(!/^\d{1,12}$/.test(text))throw new Error('قیمت را به تومان و فقط با عدد وارد کن.');f[key]=Number(text);}}
    if(f.min_price!==undefined&&f.max_price!==undefined&&f.min_price>f.max_price)throw new Error('حداقل قیمت نباید از حداکثر بیشتر باشد.');
    const companies=$('companies').value.split(/[،,\n]/).map(s=>s.trim()).filter(Boolean);if(companies.length>12)throw new Error('حداکثر ۱۲ شرکت انتخاب کن.');if(companies.length)f.companies=companies;return f;
  }
  function readPreferences(){return {count,type:Number(document.querySelector('[name=quota]:checked').value),coupe:$('coupe').checked,allow_partial_capacity:$('partial').checked,search_filters:readFilters()};}
  function renderReview(){const filters=draft.search_filters,rows=[['تاریخ حرکت',dateText(draft.date)],['صندلی مورد نیاز',fa(draft.count)+' نفر'],['سهمیه',['عادی','ویژه برادران','ویژه خواهران'][draft.type]],['کوپهٔ دربست',draft.coupe?'بله':'خیر'],['ظرفیت کمتر',draft.allow_partial_capacity?'اطلاع داده شود':'فقط ظرفیت کافی'],['هشدار قطارها','همهٔ قطارها']];
    if(filters.departure_from)rows.push(['ساعت حرکت',fa(filters.departure_from)+' تا '+fa(filters.departure_to)]);if(filters.min_price!==undefined||filters.max_price!==undefined)rows.push(['قیمت · تومان',`${filters.min_price===undefined?'۰':fa(filters.min_price)} تا ${filters.max_price===undefined?'نامحدود':fa(filters.max_price)}`]);if(filters.train_types?.length)rows.push(['نوع قطار',filters.train_types.map(t=>({compartment_4:'۴ نفره',compartment_6:'۶ نفره',seated:'سالنی'}[t])).join('، ')]);if(filters.companies?.length)rows.push(['شرکت',filters.companies.join('، ')]);
    $('review-route').textContent=routeText();$('review-details').replaceChildren();rows.forEach(([a,b])=>{const dt=document.createElement('dt'),dd=document.createElement('dd');dt.textContent=a;dd.textContent=b;$('review-details').append(dt,dd);});}
  function freshCreate(){if(!showView('create'))return;editTrip=null;fillDraftFields();renderStations();setStep(1);}
  function renderTrips(){const list=$('trip-list');list.replaceChildren();$('approval').hidden=boot.approved;
    if(!boot.trips.length){const p=document.createElement('p');p.className='empty';p.textContent='هنوز سفری ثبت نکرده‌ای. مسیر بعدی را انتخاب کن تا بلیطش را پیدا کنیم.';list.append(p);return;}
    boot.trips.forEach(trip=>{const card=document.createElement('article');card.className='trip-card';const top=document.createElement('div');top.className='trip-top';const title=document.createElement('h2');title.textContent=routeText(trip);const pill=document.createElement('span');pill.className='pill'+(trip.mode==='none'?' off':'');pill.textContent=modeLabel(trip);top.append(title,pill);
      const meta=document.createElement('div');meta.className='trip-meta';const date=document.createElement('span'),seats=document.createElement('span');date.textContent=dateText(trip.date);seats.textContent=fa(trip.count)+' صندلی';meta.append(date,seats);
      const alerts=document.createElement('button');alerts.type='button';alerts.className='text-button';alerts.textContent='هشدار قطارها';alerts.onclick=()=>openAlerts(trip.id);
      const prefs=document.createElement('button');prefs.type='button';prefs.className='text-button trip-preferences';prefs.textContent='ترجیحات و فیلترها';prefs.onclick=()=>{if(!showView('create'))return;editTrip=trip;fillFields(trip);setStep(2);};
      const remove=document.createElement('button');remove.type='button';remove.className='text-button delete-trip';remove.textContent='حذف';remove.setAttribute('aria-label','حذف سفر '+routeText(trip)+' '+dateText(trip.date));remove.onclick=()=>{if(busy)return;deletingTrip=trip;$('delete-trip-summary').textContent=routeText(trip)+' · '+dateText(trip.date);$('delete-dialog').showModal();syncBack();$('cancel-delete').focus();};
      const actions=document.createElement('div');actions.className='trip-actions';actions.append(prefs,remove);card.append(top,meta,alerts,actions);list.append(card);});}
  async function deleteTrip(){
    if(busy||!deletingTrip)return;const trip=deletingTrip;setBusy(true);$('confirm-delete').disabled=true;$('cancel-delete').disabled=true;
    try{await api('delete_trip',{trip_id:trip.id,revision:trip.revision});boot.trips=boot.trips.filter(t=>t.id!==trip.id);renderTrips();notice('سفر حذف شد و هشدارهایش متوقف شدند.');}
    catch(error){if(error.status===404||error.status===409){try{const fresh=await api('bootstrap');boot.trips=fresh.trips;renderTrips();}catch(_){}}notice(error.message,true);}
    finally{$('delete-dialog').close();deletingTrip=null;setBusy(false);syncBack();$('confirm-delete').disabled=false;$('cancel-delete').disabled=false;$('trips-title').focus();}
  }
  $('confirm-delete').onclick=deleteTrip;$('cancel-delete').onclick=()=>closeDialog('delete-dialog');$('delete-dialog').addEventListener('cancel',event=>{if(busy)event.preventDefault();else deletingTrip=null;});
  ['city-dialog','date-dialog','delete-dialog'].forEach(id=>$(id).addEventListener('close',syncBack));
  async function submit(event){event.preventDefault();if(busy)return;syncToday();formError('');try{
    if(!editTrip&&step>1&&(!draft.date||draft.date<today)){setStep(1);throw new Error('روز حرکت را دوباره انتخاب کن؛ تاریخ قبلی گذشته است.');}
    if(step===1){if(!draft.origin){$('origin').focus();throw new Error('ابتدا شهر مبدأ را انتخاب کن.');}if(!draft.destination||draft.origin===draft.destination){$('destination').focus();throw new Error('یک مقصد متفاوت انتخاب کن.');}if(!draft.date||draft.date<today){$('open-calendar').focus();throw new Error('روز حرکت را از امروز به بعد انتخاب کن.');}setStep(2);return;}
    if(step===2&&!editTrip){Object.assign(draft,readPreferences());persist();setStep(3);return;}
    if(editTrip){const prefs=readPreferences();setBusy(true);const saved=await api('update_preferences',{trip_id:editTrip.id,revision:editTrip.revision,allow_partial_capacity:prefs.allow_partial_capacity,search_filters:prefs.search_filters});boot.trips=boot.trips.map(t=>t.id===saved.id?saved:t);preferencesDirty=false;setBusy(false);renderTrips();showView('trips');notice('ترجیحات سفر ذخیره شد.');return;}
    const payload=JSON.stringify(draft);if(!requestId||lockedPayload!==payload){requestId=[...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');lockedPayload=payload;persist();}
    setBusy(true);const result=await api('create_trip',{request_id:requestId,trip:draft});createdTrip=result.trip;
    boot.trips=[result.trip,...boot.trips.filter(t=>t.id!==result.trip.id)];try{sessionStorage.removeItem(storageKey());}catch(_){}
    requestId='';lockedPayload=null;draftFields={};draft={origin:'',destination:'',date:'',count:1,type:0,coupe:false,allow_partial_capacity:false,search_filters:{}};
    renderTrips();setBusy(false);showView('success');$('success-summary').textContent=routeText(createdTrip)+' · '+dateText(createdTrip.date);$('success-approval').hidden=boot.approved;
    document.querySelector('#success-view h1').textContent=result.created?'سفر ثبت شد':'این سفر از قبل ثبت شده است';
    $('success-mode').textContent=createdTrip.mode==='none'?'هشدار این سفر خاموش است.':createdTrip.mode==='all'?'هشدار همهٔ قطارهای این سفر فعال است.':'انتخاب‌های قبلی هشدار این سفر حفظ شدند.';
    void startInitialCheck(createdTrip.id);tg?.HapticFeedback?.notificationOccurred('success');
  }catch(error){formError(error.message);if(error.status===409&&editTrip){notice('تنظیمات هم‌زمان تغییر کرده؛ اطلاعاتت را نگه داشتیم. برای نسخهٔ تازه از سفرهای من دوباره باز کن.',true);}}finally{setBusy(false);}}
  async function startInitialCheck(tripId){
    const run=++initialCheckRun, current=()=>run===initialCheckRun&&createdTrip?.id===tripId;
    $('success-check').textContent='سفر ذخیره شد؛ در حال بررسی بلیط‌های همین مسیر و روز…';
    $('success-check-time').hidden=true;$('success-retry').hidden=true;
    try{
      for(let attempt=0;attempt<12;attempt++){
        const result=await api('initial_check',{trip_id:tripId});
        if(!current())return;
        $('success-check').textContent=result.message;
        if(result.checked_at){$('success-check-time').textContent='آخرین بررسی موجودی: '+new Date(Number(result.checked_at)*1000).toLocaleString('fa-IR');$('success-check-time').hidden=false;}
        if(result.state!=='checking')return;
        if(view!=='success')return;
        if(attempt===11){$('success-check').textContent='سفر ذخیره شده و بررسی ادامه دارد؛ نتیجهٔ تازه به ربات ارسال می‌شود.';$('success-retry').hidden=false;return;}
        await new Promise(resolve=>setTimeout(resolve,3000));
      }
    }catch(error){
      if(!current())return;
      $('success-check').textContent='سفر ذخیره شده است؛ نتیجهٔ بررسی اولیه دریافت نشد. پایش خودکار ادامه دارد.';
      $('success-retry').hidden=error.status===401||error.status===404;
    }
  }
  function trainIsOn(key) {
    return mode === 'all' || (mode === 'except' && !excluded.has(key)) || (mode === 'selected' && selected.has(key));
  }
  function updateAlertCount() {
    alertDirty = pendingAlertOperation !== null && (alertSaveStatus === 'saving' || alertSyncUnknown);
    markClose();
    $('except-row').hidden = !excluded.size && mode !== 'except';
    document.querySelectorAll('[name=alert-mode]').forEach(input => { input.checked = input.value === mode; });
    const onCount = catalog ? catalog.options.filter(item => trainIsOn(item.key)).length : 0;
    $('alert-count').textContent = alertSaveStatus === 'saving' ? 'در حال ثبت تغییر…'
      : alertSaveStatus === 'error' ? (alertSyncUnknown ? 'آخرین وضعیت تأییدشده نمایش داده می‌شود.' : 'تغییر ثبت نشد؛ وضعیت فعلی نمایش داده می‌شود.')
      : mode === 'all' ? 'هشدار همهٔ قطارها روشن است.' : fa(onCount) + ' قطار روشن';
    $('alert-feedback').dataset.status = alertSaveStatus;
    $('policy-summary').textContent = mode === 'all' || mode === 'except' ? 'قطارهای جدید روشن' : 'قطارهای جدید خاموش';
    $('save-alerts').hidden = alertSaveStatus !== 'error' || !pendingAlertOperation;
  }
  function renderTrains() {
    const list = $('train-list');
    list.replaceChildren();
    const query = norm($('train-search').value);
    const trip = boot.trips.find(item => item.id === catalog.trip_id);
    const inStock=item=>item.capacity>0&&!item.missing;
    const counts={all:catalog.options.length,on:catalog.options.filter(item=>trainIsOn(item.key)).length,stock:catalog.options.filter(inStock).length};
    for(const [key,label] of [['all','همه'],['on','هشدار روشن'],['stock','دارای ظرفیت']]){
      $('filter-'+key).textContent=label+' '+fa(counts[key]);
      $('filter-'+key).setAttribute('aria-pressed',String(trainFilter===key));
    }
    const items = catalog.options.filter(item => norm([item.number,item.time,item.company,item.wagon].join(' ')).includes(query)
      &&(trainFilter==='all'||trainFilter==='on'&&trainIsOn(item.key)||trainFilter==='stock'&&inStock(item)));
    $('train-empty').hidden = items.length > 0;
    items.forEach(item => {
      const row = document.createElement('article'); row.className = 'train-card';
      const control = document.createElement('label'); control.className = 'train-control';
      const input = document.createElement('input'); input.type = 'checkbox';
      input.disabled = busy;
      input.setAttribute('role','switch');
      input.setAttribute('data-train-key',item.key);
      input.setAttribute('aria-label','هشدار قطار ' + item.number + ' ساعت ' + item.time);
      input.checked = trainIsOn(item.key);
      input.onchange = () => {
        if (busy) { input.checked = trainIsOn(item.key); return; }
        return persistAlertChange({kind:'train',key:item.key,enabled:input.checked});
      };
      const track = document.createElement('span'); track.className = 'switch-track'; track.setAttribute('aria-hidden','true');
      const state = document.createElement('span'); state.className = 'switch-label'; state.setAttribute('aria-hidden','true');
      const pending = pendingAlertOperation?.kind === 'train' && pendingAlertOperation.key === item.key;
      state.textContent = pending && alertSaveStatus === 'saving' ? 'در حال ثبت' : pending && alertSyncUnknown ? 'تأیید نشد' : input.checked ? 'روشن' : 'خاموش';
      const controlTitle=document.createElement('span');controlTitle.className='switch-title';controlTitle.textContent='هشدار این قطار';
      control.append(input,track,state,controlTitle);
      const body = document.createElement('div'); body.className = 'train-body';
      const top = document.createElement('div'); top.className = 'train-top';
      const time = document.createElement('strong'); time.textContent = fa(item.time || '—');
      const stock = document.createElement('span'); stock.className = 'status' + (item.capacity > 0 && !item.missing && item.eligible !== false ? ' available' : '');
      stock.textContent = item.missing ? 'در فهرست نیست' : item.capacity > 0 ? fa(item.capacity) + ' صندلی' : 'ناموجود';
      top.append(time,stock);
      const name = document.createElement('div'); name.className = 'train-name'; name.textContent = item.wagon || 'قطار ' + fa(item.number);
      const meta = document.createElement('div'); meta.className = 'train-meta';
      const company = item.company && !norm(item.wagon || '').includes(norm(item.company)) ? item.company : '';
      meta.textContent = [company,'قطار ' + fa(item.number)].filter(Boolean).join('، ');
      body.append(top,name,meta);
      const price=document.createElement('div');price.className='train-price';
      const amount=document.createElement('strong'),unit=document.createElement('span');
      const hasPrice=item.price!==null&&item.price!==undefined&&Number.isFinite(Number(item.price));
      amount.textContent=hasPrice?Number(item.price).toLocaleString('fa-IR'):'—';unit.textContent=hasPrice?'تومان / نفر':'قیمت اعلام نشده';price.append(amount,unit);body.append(price);
      if (item.label && item.eligible === false && !['sold_out','not_followed'].includes(item.reason)) {
        const reason = document.createElement('p'); reason.className = 'notification-reason';
        reason.textContent = item.reason === 'capacity' && trip ? 'حداقل ' + fa(trip.count) + ' صندلی لازم است.' : fa(item.label); body.append(reason);
      }
      if (item.send_error_code) {
        const error = document.createElement('p'); error.className = 'notification-reason delivery-error';
        error.textContent = item.send_error_code === 429 ? 'ارسال در انتظار رفع محدودیت تلگرام است.'
          : item.send_error_code === 403 ? 'تلگرام اجازهٔ ارسال نمی‌دهد؛ ربات را باز کن و /start بزن.' : 'آخرین ارسال ناموفق بود؛ دوباره بررسی می‌شود.';
        body.append(error);
      }
      row.append(control,body); list.append(row);
    });
    updateAlertCount();
  }
  function setCatalog(next) {
    catalog = next; mode = next.mode; selected = new Set(next.selected); excluded = new Set(next.excluded || []);
    const trip = boot.trips.find(item => item.id === next.trip_id);
    const route = document.createElement('span'); route.className = 'route-name'; route.textContent = trip?.route_title || next.route_title;
    const detail = document.createElement('span'); detail.className = 'route-detail';
    detail.textContent=trip?[fa(trip.count)+' صندلی',['عادی','ویژه برادران','ویژه خواهران'][trip.type],trip.coupe?'کوپهٔ دربست':'',trip.allow_partial_capacity?'ظرفیت کمتر هم مجاز':'فقط ظرفیت کافی'].filter(Boolean).join('، '):'انتخاب هشدار برای این مسیر';
    const date=document.createElement('span');date.className='journey-date';date.setAttribute('aria-label',dateText(next.date));
    const parts=next.date.split('-').map(Number),day=document.createElement('strong'),monthName=document.createElement('span');day.textContent=fa(parts[2]);monthName.textContent=months[parts[1]-1]+' '+fa(parts[0]);date.append(day,monthName);
    $('alert-trip').replaceChildren(route,detail,date);
    const diagnostics = [next.updated_at ? 'دریافت موجودی: ' + new Date(next.updated_at * 1000).toLocaleString('fa-IR') + (next.stale ? '؛ ظرفیت ممکن است تغییر کرده باشد.' : '') : 'موجودی هنوز دریافت نشده است.'];
    diagnostics.push(next.last_poll ? 'بررسی ارسال: ' + new Date(Number(next.last_poll.checked_at) * 1000).toLocaleString('fa-IR') : 'هنوز بررسی ارسال برای این سفر ثبت نشده است.');
    if (next.worker?.error) diagnostics.push('آخرین بررسی خودکار با خطا پایان یافته است.');
    if (next.last_poll && Number(next.last_poll.inventory_ok) === 0) diagnostics.push('دریافت از فروشنده ناموفق بود؛ موجودی نمایش‌داده‌شده از حافظه است.');
    const lastSent = Math.max(0,...next.options.map(item => Number(item.last_sent_at) || 0));
    if (lastSent) diagnostics.push('آخرین پذیرش پیام توسط تلگرام: ' + new Date(lastSent * 1000).toLocaleString('fa-IR'));
    diagnostics.push('هشدار با موجودشدن قطار یا رسیدن به ظرفیت کافی ارسال می‌شود؛ وضعیت ثابت در هیچ حالت هشدار تکرار نمی‌شود.');
    diagnostics.push('به‌روزرسانی فهرست فقط موجودی را دریافت می‌کند؛ ارسال پیام در بررسی خودکار انجام می‌شود.');
    $('catalog-time').textContent = diagnostics.join('\n');
    $('alert-controls').hidden = false;
    renderTrains();
  }
  function applyAlertOperation(operation) {
    if (operation.kind === 'mode') {
      const enabled = catalog.options.filter(item => trainIsOn(item.key)).map(item => item.key);
      mode = operation.mode;
      if (mode === 'all') { excluded.clear(); selected.clear(); }
      else if (mode === 'selected') { selected = new Set(enabled); excluded.clear(); if (!selected.size) mode = 'none'; }
      else if (mode === 'none') { selected.clear(); excluded.clear(); }
      return;
    }
    if (mode === 'all' || mode === 'except') {
      operation.enabled ? excluded.delete(operation.key) : excluded.add(operation.key);
      mode = excluded.size ? 'except' : 'all';
    } else {
      if (mode === 'none') selected.clear();
      operation.enabled ? selected.add(operation.key) : selected.delete(operation.key);
      mode = selected.size ? 'selected' : 'none';
    }
  }
  function operationApplied(data,operation,target) {
    if (operation.kind === 'mode') {
      if (data.mode !== target.mode) return false;
      if (target.mode === 'selected') return JSON.stringify([...data.selected].sort()) === JSON.stringify([...target.selected].sort());
      if (target.mode === 'except') return JSON.stringify([...(data.excluded || [])].sort()) === JSON.stringify([...target.excluded].sort());
      return true;
    }
    const enabled = data.mode === 'all' || (data.mode === 'except' && !(data.excluded || []).includes(operation.key)) || (data.mode === 'selected' && data.selected.includes(operation.key));
    return enabled === operation.enabled;
  }
  async function persistAlertChange(operation) {
    if (busy || !catalog) return;
    pendingAlertOperation = operation; alertSaveStatus = 'saving'; alertSyncUnknown = false;
    applyAlertOperation(operation); renderTrains(); setBusy(true,'alerts'); notice('');
    const target = {mode,selected:[...selected],excluded:[...excluded]};
    try {
      const extra = operation.kind === 'train'
        ? {trip_id:catalog.trip_id,train_key:operation.key,enabled:operation.enabled}
        : {trip_id:catalog.trip_id,revision:catalog.revision,mode,keys:[...selected],excluded_keys:[...excluded]};
      const next = await api(operation.kind === 'train' ? 'set_train' : 'save',extra);
      pendingAlertOperation = null; alertSaveStatus = 'saved'; setCatalog(next);
      const trip = boot.trips.find(item => item.id === next.trip_id);
      if (trip) { trip.mode = next.mode; trip.revision = next.revision; trip.selected_count = next.selected.length; trip.excluded_count = (next.excluded || []).length; }
      renderTrips(); tg?.HapticFeedback?.selectionChanged?.();
    } catch (error) {
      alertSaveStatus = 'error';
      if (error.status === 401 || error.status === 404) {
        pendingAlertOperation = null; alertSyncUnknown = false; setCatalog(catalog); $('alert-controls').hidden = true;
        if (error.status === 404) { boot.trips = boot.trips.filter(item => item.id !== catalog.trip_id); renderTrips(); }
        notice(error.status === 401 ? 'نشست تلگرام منقضی شده است. مینی‌اپ را ببند و از دکمهٔ ربات دوباره باز کن.' : 'این سفر دیگر فعال نیست. از فهرست سفرها مسیر دیگری را انتخاب کن.',true);
        return;
      }
      try {
        const actual = await api('load',{trip_id:catalog.trip_id});
        if (operationApplied(actual,operation,target)) { pendingAlertOperation = null; alertSaveStatus = 'saved'; }
        else if (operation.kind === 'train' && !actual.options.some(item => item.key === operation.key)) pendingAlertOperation = null;
        setCatalog(actual);
        const trip = boot.trips.find(item => item.id === actual.trip_id);
        if (trip) { trip.mode = actual.mode; trip.revision = actual.revision; trip.selected_count = actual.selected.length; trip.excluded_count = (actual.excluded || []).length; renderTrips(); }
      } catch (_) {
        alertSyncUnknown = true; setCatalog(catalog);
      }
      if (alertSaveStatus === 'error') notice(alertSyncUnknown ? 'ارتباط برقرار نشد. وضعیت قبلی نمایش داده می‌شود؛ ثبت تغییر را دوباره بررسی کن.' : error.message,true);
    } finally {
      setBusy(false); updateAlertCount();
      if (operation.kind === 'train') (document.querySelector('[data-train-key="' + operation.key + '"]')||$('filter-'+trainFilter)).focus({preventScroll:true});
    }
  }
  async function openAlerts(id) {
    if (busy || (alertDirty && !mayLeave())) return;
    alertDirty = false; pendingAlertOperation = null; alertSaveStatus = 'saved'; alertSyncUnknown = false;trainFilter='all';$('train-search').value='';
    const returnView=view==='alerts'?alertsReturn:view==='success'?'success':'trips';
    if (!showView('alerts')) return;
    alertsReturn=returnView;syncBack();
    $('notification-report').hidden=true;$('diagnostics-hint').hidden=true;$('close-diagnostics').hidden=true;$('notification-report').value='';
    $('alert-controls').hidden = true; $('alert-trip').textContent = 'در حال دریافت قطارها…'; setBusy(true);
    try { setCatalog(await api('load',{trip_id:id})); }
    catch (error) { notice(error.message + ' سفر را دوباره از فهرست باز کن.',true); }
    finally { setBusy(false); }
  }
  function saveAlerts() { if (pendingAlertOperation) return persistAlertChange(pendingAlertOperation); }
  async function showNotificationDiagnostics() {
    if(busy||!catalog)return;
    const button=$('notification-diagnostics'),id=catalog.trip_id;button.disabled=true;
    try {
      const report=await api('diagnostics',{trip_id:id});
      if(catalog.trip_id!==id)return;
      const labels={account_pending:'حساب هنوز تأیید نشده است.',trip_expired:'تاریخ سفر گذشته است.',alerts_disabled:'هشدار این سفر خاموش است.',
        worker_never_ran:'هنوز اجرای کرون ثبت نشده است؛ فرمان و زمان‌بندی کرون را در پنل هاست بررسی کن.',worker_failed:'اجرای کرون خطا داشته است؛ مرحله و نسخهٔ PHP در گزارش آمده است.',
        cron_php_unsupported:'نسخهٔ PHP کرون باید حداقل ۸ باشد.',cron_sqlite_missing:'افزونهٔ SQLite در PHP کرون فعال نیست.',cron_curl_missing:'افزونهٔ cURL در PHP کرون فعال نیست.',
        worker_stalled:'اجرای کرون شروع شده ولی بیش از ۵ دقیقه پایانش ثبت نشده است.',worker_running:'کرون در حال اجراست.',worker_not_recent:'بیش از ۵ دقیقه از آخرین پایان کرون گذشته است.',
        inventory_failed:'آخرین دریافت موجودی توسط کرون ناموفق بوده است.',telegram_send_failed:'آخرین ارسال به تلگرام ناموفق بوده؛ کد خطا در گزارش آمده است.',
        trip_processing_failed:'بررسی این سفر با خطا پایان یافته؛ نوع خطا در گزارش آمده است و سفرهای دیگر ادامه دارند.',
        unchanged_selected_trains:'وضعیت قطارهای انتخابی ثابت بوده؛ هشدار تکراری ارسال نشده است.'};
      const messages=(report.issues||[]).map(issue=>labels[issue]||issue);
      $('notification-report').value=(messages.length?messages.join('\n'):'در گزارش ذخیره‌شده، مانع مشخصی ثبت نشده است.')+'\n\n'+JSON.stringify(report,null,2);
      $('notification-report').hidden=false;$('diagnostics-hint').hidden=false;$('close-diagnostics').hidden=false;$('notification-report').focus();
    } catch(error) { notice(error.message,true); }
    finally { button.disabled=false; }
  }
  async function refreshCatalog() {
    if (busy || !catalog || (alertDirty && !mayLeave())) return;
    setBusy(true);
    try { pendingAlertOperation = null; alertSaveStatus = 'saved'; alertSyncUnknown = false; setCatalog(await api('refresh',{trip_id:catalog.trip_id})); notice('فهرست به‌روز شد.'); }
    catch (error) { notice(error.message,true); }
    finally { setBusy(false); }
  }
  $('trip-form').addEventListener('submit',submit);$('back').onclick=goBack;$('page-back').onclick=goBack;$('edit-route').onclick=()=>{if(!busy)setStep(1);};
  tg?.BackButton?.onClick?.(goBack);
  $('origin').onclick=()=>openCity('origin');$('destination').onclick=()=>openCity('destination');$('swap').onclick=()=>{[draft.origin,draft.destination]=[draft.destination,draft.origin];persist();renderStations();};$('city-search').oninput=renderCities;$('close-city').onclick=()=>closeDialog('city-dialog');$('city-back').onclick=()=>closeDialog('city-dialog');$('city-dialog').addEventListener('click',e=>{if(e.target===$('city-dialog')){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)closeDialog('city-dialog');}});
  $('open-calendar').onclick=()=>{syncToday();$('date-dialog').showModal();syncBack();};$('close-calendar').onclick=()=>closeDialog('date-dialog');$('calendar-back').onclick=()=>closeDialog('date-dialog');
  $('previous-month').onclick=()=>changeMonth(-1);$('next-month').onclick=()=>changeMonth(1);$('minus').onclick=()=>{if(count>1){count--;renderCount();if(!editTrip){draft.count=count;persist();}}};$('plus').onclick=()=>{if(count<12){count++;renderCount();if(!editTrip){draft.count=count;persist();}}};
  $('step-2').addEventListener('input',()=>{if(editTrip){preferencesDirty=true;markClose();return;}rememberDraftFields();});
  $('new-trip').onclick=freshCreate;$('success-trips').onclick=()=>showView('trips');$('success-alerts').onclick=()=>openAlerts(createdTrip.id);$('alerts-back').onclick=()=>showView(alertsReturn);$('close-diagnostics').onclick=closeDiagnostics;$('save-alerts').onclick=saveAlerts;$('refresh-trains').onclick=refreshCatalog;$('train-search').oninput=()=>catalog&&renderTrains();
  $('success-retry').onclick=()=>createdTrip&&startInitialCheck(createdTrip.id);
  ['all','on','stock'].forEach(key=>$('filter-'+key).onclick=()=>{if(!catalog||busy)return;trainFilter=key;renderTrains();});
  $('notification-diagnostics').onclick=showNotificationDiagnostics;
  document.querySelectorAll('[name=alert-mode]').forEach(input=>input.onchange=()=>{if(busy)return;return persistAlertChange({kind:'mode',mode:input.value});});
  document.querySelectorAll('[data-nav]').forEach(b=>b.onclick=()=>{if(!boot||busy)return;const next=b.dataset.nav;if(next==='create')freshCreate();else if(next==='alerts'){if(!boot.trips.length){showView('trips');notice('اول یک سفر ثبت کن؛ بعد قطارهایش را انتخاب کن.');}else{showView('trips');notice('سفر موردنظر را برای تنظیم هشدار انتخاب کن.');}}else showView(next);});
  document.querySelector('.brand-mark').onclick=e=>{e.preventDefault();if(boot)freshCreate();};
  window.addEventListener('beforeunload',e=>{if(alertDirty||preferencesDirty){e.preventDefault();e.returnValue='';}});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&boot)syncToday();});
  $('retry-start').onclick=init;
  async function init(){tg?.ready();tg?.expand();$('retry-start').hidden=true;$('loading').hidden=false;notice('');try{
    if(!tg?.initData)throw new Error('این صفحه را از دکمهٔ «ثبت سفر» یا «سفرهای من» داخل ربات تلگرام باز کن تا ورودت تأیید شود.');
    boot=await api('bootstrap');bootAt=Date.now();todayDate=new Date(boot.today+'T12:00:00Z');today=C.key(C.parts(todayDate));restore();[year,month]=(draft.date||today).split('-').map(Number);renderStations();renderQuick();renderCalendar();fillDraftFields();renderTrips();setStep(1);$('main').hidden=false;$('loading').hidden=true;
    const params=new URLSearchParams(location.search),id=Number(params.get('trip_id'));if(id>0&&Number.isSafeInteger(id))await openAlerts(id);else if(params.get('view')==='trips'||params.get('view')==='alerts')showView('trips');else showView('create');
  }catch(error){$('loading').hidden=true;$('retry-start').hidden=!tg?.initData;notice(error.message,true);}}
  init();
})();
