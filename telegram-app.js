(function () {
  'use strict';
  const C=window.TrainCalendar, tg=window.Telegram?.WebApp, $=id=>document.getElementById(id);
  const fa=n=>String(n).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
  const en=s=>String(s).replace(/[۰-۹٠-٩]/g,c=>String(c.charCodeAt(0)>1775?c.charCodeAt(0)-1776:c.charCodeAt(0)-1632));
  const norm=s=>en(s).replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s‌]/g,'').toLowerCase();
  const months=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  let boot, bootAt=0, today, todayDate, month, year, step=1, view='create', count=1, cityTarget, busy=false, editTrip=null, createdTrip=null;
  let draft={origin:'',destination:'',date:'',count:1,type:0,coupe:false,allow_partial_capacity:false,search_filters:{}}, requestId='', lockedPayload=null;
  let catalog=null, mode='all', selected=new Set(), excluded=new Set(), savedAlerts='', alertDirty=false, preferencesDirty=false;
  const canonical=()=>JSON.stringify([mode,[...selected].sort(),[...excluded].sort()]);
  const modeLabel=m=>({all:'همهٔ قطارها',except:'به‌جز حذف‌شده‌ها',selected:'قطارهای انتخابی',none:'هشدار خاموش'}[m]||'همهٔ قطارها');
  let theme=tg?.colorScheme||(window.matchMedia('(prefers-color-scheme: dark)').matches?'dark':'light');
  try{theme=localStorage.getItem('hamsafar-theme')||theme;}catch(_){}if(!['light','dark'].includes(theme))theme='light';
  function applyTheme(){document.documentElement.dataset.theme=theme;tg?.setHeaderColor?.(theme==='dark'?'#0c1d22':'#f5f4ed');tg?.setBackgroundColor?.(theme==='dark'?'#0c1d22':'#f5f4ed');}
  applyTheme();$('theme-toggle').onclick=()=>{theme=theme==='dark'?'light':'dark';applyTheme();try{localStorage.setItem('hamsafar-theme',theme);}catch(_){}};
  function notice(text,error=false){$('notice').textContent=text;$('notice').className='notice'+(error?' error':'');$('notice').hidden=!text;}
  function formError(text){$('form-error').textContent=text;$('form-error').hidden=!text;if(text)$('form-error').scrollIntoView({block:'center'});}
  function setBusy(value){busy=value;$('main').inert=value;$('main').setAttribute('aria-busy',String(value));document.querySelectorAll('#continue,#save-alerts,#refresh-trains').forEach(b=>b.disabled=value);document.querySelectorAll('[data-nav]').forEach(b=>b.disabled=value);}
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
  function persist(){if(editTrip)return;try{sessionStorage.setItem(storageKey(),JSON.stringify({draft,requestId,lockedPayload}));}catch(_){} }
  function restore(){try{const state=JSON.parse(sessionStorage.getItem(storageKey()));if(state?.draft&&typeof state.draft==='object'){
    const t=state.draft;if(boot.cities.some(c=>c.code===t.origin))draft.origin=t.origin;if(boot.cities.some(c=>c.code===t.destination))draft.destination=t.destination;
    if(typeof t.date==='string'&&t.date>=today&&/^\d{4}-\d{2}-\d{2}$/.test(t.date))draft.date=t.date;
    if(Number.isInteger(t.count)&&t.count>=1&&t.count<=12)draft.count=t.count;if([0,1,2].includes(t.type))draft.type=t.type;
    draft.coupe=t.coupe===true;draft.allow_partial_capacity=t.allow_partial_capacity===true;if(t.search_filters&&typeof t.search_filters==='object')draft.search_filters=t.search_filters;
    if(typeof state.requestId==='string')requestId=state.requestId;if(typeof state.lockedPayload==='string')lockedPayload=state.lockedPayload;
  }}catch(_){} }
  function markClose(){if(tg?.enableClosingConfirmation){alertDirty||preferencesDirty?tg.enableClosingConfirmation():tg.disableClosingConfirmation();}}
  function mayLeave(){return !alertDirty||window.confirm('تغییرات هشدار ذخیره نشده است. بدون ذخیره خارج می‌شوی؟');}
  function showView(next){
    if(busy)return false;if(view==='alerts'&&next!=='alerts'&&!mayLeave())return false;
    if(view==='create'&&editTrip&&preferencesDirty&&!window.confirm('ترجیحات سفر ذخیره نشده است. بدون ذخیره خارج می‌شوی؟'))return false;
    preferencesDirty=false;
    alertDirty=false;markClose();view=next;notice('');
    ['create','trips','alerts','success'].forEach(v=>$(v+'-view').hidden=v!==next);
    document.querySelectorAll('[data-nav]').forEach(b=>{b.classList.toggle('active',b.dataset.nav===next);b.setAttribute('aria-current',b.dataset.nav===next?'page':'false');});
    window.scrollTo({top:0});const title=$(next+'-title')||document.querySelector('#success-view h1');title?.focus({preventScroll:true});return true;
  }
  function setStep(value){step=value;[1,2,3].forEach(n=>{$('step-'+n).hidden=n!==step;const item=document.querySelector(`[data-step="${n}"]`);item.classList.toggle('active',n<=step);if(n===step)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
    $('back').hidden=step===1||!!editTrip;$('continue').textContent=editTrip?'ذخیرهٔ ترجیحات':step===1?'ادامه · ترجیحات سفر ←':step===2?'ادامه · مرور سفر ←':'ثبت سفر و شروع پایش';
    $('create-title').textContent=editTrip?'ترجیحات این سفر':step===1?'کجا می‌ریم؟':step===2?'سفر به سلیقهٔ تو':'یک نگاه، تا شروع';
    $('route-strip').textContent=`${editTrip?routeText(editTrip):routeText()} · ${dateText(editTrip?.date||draft.date)}`;
    formError('');if(step===3)renderReview();window.scrollTo({top:0});
  }
  function renderCalendar(){
    $('month-title').textContent=`${months[month-1]} ${fa(year)}`;$('calendar-days').replaceChildren();
    const days=C.month(year,month), first=days[0];for(let i=0;i<first.weekday;i++)$('calendar-days').append(document.createElement('span'));
    days.forEach(d=>{const button=document.createElement('button');button.type='button';button.textContent=fa(d.day);button.dataset.date=d.key;
      button.disabled=d.key<today;button.classList.toggle('selected',draft.date===d.key);button.classList.toggle('today',today===d.key);
      button.setAttribute('aria-label',dateText(d.key)+(today===d.key?'، امروز':''));button.setAttribute('aria-pressed',String(draft.date===d.key));
      button.onclick=()=>{draft.date=d.key;persist();renderCalendar();$('date-dialog').close();$('open-calendar').focus();};$('calendar-days').append(button);});
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
    const cities=boot.cities.filter(c=>norm(c.text).includes(query));cities.forEach(city=>{const b=document.createElement('button');b.type='button';b.textContent=city.text+(city.code===other?' · '+(cityTarget==='origin'?'مقصد فعلی':'مبدأ فعلی'):'');b.disabled=city.code===other;b.onclick=()=>{draft[cityTarget]=city.code;persist();renderStations();$('city-dialog').close();$(cityTarget).focus();};$('city-list').append(b);});
    if(!cities.length){const p=document.createElement('p');p.className='empty';p.textContent='این شهر در فهرست ایستگاه‌های فعلی نیست.';$('city-list').append(p);}}
  function openCity(target){cityTarget=target;$('city-title').textContent=target==='origin'?'از کدام شهر؟':'به کدام شهر؟';$('city-search').value='';renderCities();$('city-dialog').showModal();$('city-search').focus();}
  function renderStations(){$('origin-name').textContent=draft.origin?cityName(draft.origin):'شهر حرکت را انتخاب کن';$('destination-name').textContent=draft.destination?cityName(draft.destination):'کجا می‌خوای بری؟';}
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
  function freshCreate(){if(!showView('create'))return;editTrip=null;fillFields(draft);renderStations();setStep(1);}
  function renderTrips(){const list=$('trip-list');list.replaceChildren();$('approval').hidden=boot.approved;
    if(!boot.trips.length){const p=document.createElement('p');p.className='empty';p.textContent='هنوز سفری ثبت نکرده‌ای. مسیر بعدی را انتخاب کن تا بلیطش را پیدا کنیم.';list.append(p);return;}
    boot.trips.forEach(trip=>{const card=document.createElement('article');card.className='trip-card';const top=document.createElement('div');top.className='trip-top';const title=document.createElement('h2');title.textContent=routeText(trip);const pill=document.createElement('span');pill.className='pill';pill.textContent=modeLabel(trip.mode);top.append(title,pill);
      const meta=document.createElement('div');meta.className='trip-meta';const date=document.createElement('span'),seats=document.createElement('span');date.textContent=dateText(trip.date);seats.textContent=fa(trip.count)+' صندلی';meta.append(date,seats);
      const alerts=document.createElement('button');alerts.type='button';alerts.className='text-button';alerts.textContent='انتخاب قطارها و تنظیم هشدار ←';alerts.onclick=()=>openAlerts(trip.id);
      const prefs=document.createElement('button');prefs.type='button';prefs.className='text-button';prefs.textContent='ظرفیت، ساعت و فیلترهای سفر ←';prefs.onclick=()=>{if(!showView('create'))return;editTrip=trip;fillFields(trip);setStep(2);};card.append(top,meta,alerts,prefs);list.append(card);});}
  async function submit(event){event.preventDefault();if(busy)return;syncToday();formError('');try{
    if(!editTrip&&step>1&&(!draft.date||draft.date<today)){setStep(1);throw new Error('روز حرکت را دوباره انتخاب کن؛ تاریخ قبلی گذشته است.');}
    if(step===1){if(!draft.origin){$('origin').focus();throw new Error('ابتدا شهر مبدأ را انتخاب کن.');}if(!draft.destination||draft.origin===draft.destination){$('destination').focus();throw new Error('یک مقصد متفاوت انتخاب کن.');}if(!draft.date||draft.date<today){$('open-calendar').focus();throw new Error('روز حرکت را از امروز به بعد انتخاب کن.');}setStep(2);return;}
    if(step===2&&!editTrip){Object.assign(draft,readPreferences());persist();setStep(3);return;}
    if(editTrip){const prefs=readPreferences();setBusy(true);const saved=await api('update_preferences',{trip_id:editTrip.id,revision:editTrip.revision,allow_partial_capacity:prefs.allow_partial_capacity,search_filters:prefs.search_filters});boot.trips=boot.trips.map(t=>t.id===saved.id?saved:t);editTrip=null;preferencesDirty=false;setBusy(false);renderTrips();showView('trips');notice('ترجیحات سفر ذخیره شد.');return;}
    const payload=JSON.stringify(draft);if(!requestId||lockedPayload!==payload){requestId=[...crypto.getRandomValues(new Uint8Array(16))].map(n=>n.toString(16).padStart(2,'0')).join('');lockedPayload=payload;persist();}
    setBusy(true);const result=await api('create_trip',{request_id:requestId,trip:draft});createdTrip=result.trip;
    boot.trips=[result.trip,...boot.trips.filter(t=>t.id!==result.trip.id)];try{sessionStorage.removeItem(storageKey());}catch(_){}
    requestId='';lockedPayload=null;draft={origin:'',destination:'',date:'',count:1,type:0,coupe:false,allow_partial_capacity:false,search_filters:{}};
    renderTrips();setBusy(false);showView('success');$('success-summary').textContent=routeText(createdTrip)+' · '+dateText(createdTrip.date);$('success-approval').hidden=boot.approved;tg?.HapticFeedback?.notificationOccurred('success');
  }catch(error){formError(error.message);if(error.status===409&&editTrip){notice('تنظیمات هم‌زمان تغییر کرده؛ اطلاعاتت را نگه داشتیم. برای نسخهٔ تازه از سفرهای من دوباره باز کن.',true);}}finally{setBusy(false);}}
  function updateAlertCount(){alertDirty=canonical()!==savedAlerts;markClose();$('except-row').hidden=!excluded.size&&mode!=='except';document.querySelectorAll('[name=alert-mode]').forEach(i=>i.checked=i.value===mode);$('alert-count').textContent=(mode==='all'?'همهٔ قطارها فعال‌اند':mode==='except'?fa(excluded.size)+' قطار خاموش':mode==='none'?'هشدار این سفر خاموش است':fa(selected.size)+' قطار انتخابی')+(alertDirty?' · ذخیره نشده':' · ذخیره‌شده');}
  function renderTrains(){const list=$('train-list');list.replaceChildren();const query=norm($('train-search').value),items=catalog.options.filter(t=>norm([t.number,t.time,t.company,t.wagon].join(' ')).includes(query));
    $('train-empty').hidden=items.length>0;items.forEach(t=>{const label=document.createElement('label');label.className='train-card';const check=document.createElement('input');check.type='checkbox';check.checked=mode==='all'||mode==='except'&&!excluded.has(t.key)||mode==='selected'&&selected.has(t.key);check.setAttribute('aria-label','هشدار قطار '+t.number+' ساعت '+t.time);check.onchange=()=>{if(mode==='all'||mode==='except'){check.checked?excluded.delete(t.key):excluded.add(t.key);mode=excluded.size?'except':'all';}else{check.checked?selected.add(t.key):selected.delete(t.key);mode='selected';}updateAlertCount();};
      const body=document.createElement('div');body.className='train-body';const top=document.createElement('div');top.className='train-top';const time=document.createElement('strong');time.textContent=fa(t.time||'—');const status=document.createElement('span');status.className='status'+(t.capacity>0&&!t.missing?' available':'');status.textContent=t.missing?'در فهرست فعلی نیست':t.capacity>0?fa(t.capacity)+' صندلی':'ناموجود';top.append(time,status);
      const name=document.createElement('div');name.className='train-name';name.textContent=t.wagon||'قطار '+fa(t.number);const meta=document.createElement('div');meta.className='train-meta';meta.textContent=[t.company,'شمارهٔ '+fa(t.number),t.price!=null?Number(t.price).toLocaleString('fa-IR')+' تومان':''].filter(Boolean).join(' · ');body.append(top,name,meta);label.append(check,body);list.append(label);});updateAlertCount();}
  function setCatalog(next){catalog=next;mode=next.mode;selected=new Set(next.selected);excluded=new Set(next.excluded||[]);savedAlerts=canonical();$('alert-trip').textContent=(boot.trips.find(t=>t.id===next.trip_id)?.route_title||next.route_title)+' · '+dateText(next.date);$('catalog-time').textContent=next.updated_at?'آخرین بررسی: '+new Date(next.updated_at*1000).toLocaleString('fa-IR')+(next.stale?' · وضعیت ظرفیت ممکن است تغییر کرده باشد.':''):'فهرست هنوز آماده نیست؛ کمی بعد تازه کن.';$('alert-controls').hidden=false;renderTrains();}
  async function openAlerts(id){if(busy)return;if(alertDirty&&!mayLeave())return;alertDirty=false;if(!showView('alerts'))return;$('alert-controls').hidden=true;$('alert-trip').textContent='در حال دریافت قطارهای سفر…';notice('');setBusy(true);try{setCatalog(await api('load',{trip_id:id}));}catch(error){notice(error.message+' برای تلاش دوباره، سفر را از «سفرهای من» باز کن.',true);}finally{setBusy(false);}}
  async function saveAlerts(){if(busy||!catalog)return;setBusy(true);try{const next=await api('save',{trip_id:catalog.trip_id,revision:catalog.revision,mode,keys:[...selected],excluded_keys:[...excluded]});setCatalog(next);const trip=boot.trips.find(t=>t.id===next.trip_id);if(trip){trip.mode=next.mode;trip.revision=next.revision;}renderTrips();notice('انتخاب قطارها ذخیره شد.');}catch(error){notice(error.message+(error.status===409?' انتخابت حفظ شد؛ ابتدا فهرست تازه را دریافت کن.':''),true);}finally{setBusy(false);}}
  async function refreshCatalog(){if(busy||!catalog)return;if(alertDirty&&!window.confirm('برای دریافت نسخهٔ تازه، تغییرات ذخیره‌نشده کنار گذاشته شود؟'))return;setBusy(true);try{setCatalog(await api('refresh',{trip_id:catalog.trip_id}));notice('فهرست به‌روز شد.');}catch(error){notice(error.message,true);}finally{setBusy(false);}}
  $('trip-form').addEventListener('submit',submit);$('back').onclick=()=>{if(!busy){if(step===2){try{Object.assign(draft,readPreferences());persist();}catch(_){} }setStep(step-1);}};$('edit-route').onclick=()=>setStep(1);
  $('origin').onclick=()=>openCity('origin');$('destination').onclick=()=>openCity('destination');$('swap').onclick=()=>{[draft.origin,draft.destination]=[draft.destination,draft.origin];persist();renderStations();};$('city-search').oninput=renderCities;$('close-city').onclick=()=>$('city-dialog').close();$('city-dialog').addEventListener('click',e=>{if(e.target===$('city-dialog')){const r=e.target.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)e.target.close();}});
  $('open-calendar').onclick=()=>{syncToday();$('date-dialog').showModal();};$('close-calendar').onclick=()=>$('date-dialog').close();
  $('previous-month').onclick=()=>changeMonth(-1);$('next-month').onclick=()=>changeMonth(1);$('minus').onclick=()=>{if(count>1){count--;renderCount();if(!editTrip){draft.count=count;persist();}}};$('plus').onclick=()=>{if(count<12){count++;renderCount();if(!editTrip){draft.count=count;persist();}}};
  $('step-2').addEventListener('input',()=>{if(editTrip){preferencesDirty=true;markClose();return;}try{Object.assign(draft,readPreferences());persist();}catch(_){} });
  $('new-trip').onclick=freshCreate;$('success-trips').onclick=()=>showView('trips');$('success-alerts').onclick=()=>openAlerts(createdTrip.id);$('alerts-back').onclick=()=>showView('trips');$('save-alerts').onclick=saveAlerts;$('refresh-trains').onclick=refreshCatalog;$('train-search').oninput=()=>catalog&&renderTrains();
  document.querySelectorAll('[name=alert-mode]').forEach(i=>i.onchange=()=>{mode=i.value;if(mode==='all')excluded.clear();renderTrains();});
  document.querySelectorAll('[data-nav]').forEach(b=>b.onclick=()=>{if(!boot||busy)return;const next=b.dataset.nav;if(next==='create')freshCreate();else if(next==='alerts'){if(!boot.trips.length){showView('trips');notice('اول یک سفر ثبت کن؛ بعد قطارهایش را انتخاب کن.');}else{showView('trips');notice('سفر موردنظر را برای تنظیم هشدار انتخاب کن.');}}else showView(next);});
  document.querySelector('.brand-mark').onclick=e=>{e.preventDefault();if(boot)freshCreate();};
  window.addEventListener('beforeunload',e=>{if(alertDirty||preferencesDirty){e.preventDefault();e.returnValue='';}});
  document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible'&&boot)syncToday();});
  $('retry-start').onclick=init;
  async function init(){tg?.ready();tg?.expand();$('retry-start').hidden=true;$('loading').hidden=false;notice('');try{
    if(!tg?.initData)throw new Error('این صفحه را از دکمهٔ «ثبت سفر» یا «سفرهای من» داخل ربات تلگرام باز کن تا ورودت تأیید شود.');
    boot=await api('bootstrap');bootAt=Date.now();todayDate=new Date(boot.today+'T12:00:00Z');today=C.key(C.parts(todayDate));restore();[year,month]=(draft.date||today).split('-').map(Number);renderStations();renderQuick();renderCalendar();fillFields(draft);renderTrips();setStep(1);$('main').hidden=false;$('loading').hidden=true;
    const params=new URLSearchParams(location.search),id=Number(params.get('trip_id'));if(id>0&&Number.isSafeInteger(id))await openAlerts(id);else if(params.get('view')==='trips'||params.get('view')==='alerts')showView('trips');else showView('create');
  }catch(error){$('loading').hidden=true;$('retry-start').hidden=!tg?.initData;notice(error.message,true);}}
  init();
})();
