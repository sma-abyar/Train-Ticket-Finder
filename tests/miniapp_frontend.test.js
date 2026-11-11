'use strict';
const assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm'), crypto=require('node:crypto');
const C=require('../miniapp-calendar.js');
class Element{
  constructor(value=''){this.value=value;this.children=[];this.dataset={};this.hidden=false;this.disabled=false;this.checked=false;this.events={};this.attributes={};this.textContent='';this.classList={toggle(){}};}
  append(...children){this.children.push(...children);}replaceChildren(...children){this.children=children;}
  setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this.attributes[k];}
  addEventListener(k,v){this.events[k]=v;}focus(){this.focused=true;}scrollIntoView(){}showModal(){this.open=true;}close(){this.open=false;}
  set innerHTML(_){throw new Error('Unsafe HTML rendering');}
}
function findTree(node,predicate){if(predicate(node))return node;for(const child of node.children){const result=findTree(child,predicate);if(result)return result;}return null;}
function trainInput(ui,number){return findTree(ui.els['train-list'],e=>e.attributes.role==='switch'&&e.attributes['aria-label']?.includes(number));}
async function toggle(ui,number,enabled){const input=trainInput(ui,number);assert.ok(input);input.checked=enabled;return input.onchange();}
const flush=()=>new Promise(r=>setImmediate(r));
async function fixture(auth=true){
  const html=fs.readFileSync(require.resolve('../telegram-app.html'),'utf8'), els={};
  for(const [,id] of html.matchAll(/id="([^"]+)"/g))els[id]=new Element();
  const quotas=['0','1','2'].map(v=>new Element(v));quotas[0].checked=true;
  const types=['compartment_4','compartment_6','seated'].map(v=>new Element(v));
  const modes=['all','except','selected','none'].map(v=>new Element(v));
  const nav=['trips','create','alerts'].map(v=>{const e=new Element();e.dataset.nav=v;return e;});
  const steps=[new Element(),new Element(),new Element()],brand=new Element(),title=new Element();
  const docEvents={};let fakeNow=Date.parse('2026-11-10T20:29:45Z');class FakeDate extends Date{constructor(...args){super(...(args.length?args:[fakeNow]));}static now(){return fakeNow;}}
  const document={visibilityState:'visible',addEventListener(k,v){docEvents[k]=v;},documentElement:new Element(),getElementById:id=>{assert.ok(els[id],id);return els[id];},createElement:()=>new Element(),
    querySelector:s=>{if(s==='.brand-mark')return brand;if(s==='#success-view h1')return title;if(s==='[name=quota]:checked')return quotas.find(i=>i.checked);const control=s.match(/^\[data-train-key="([^"]+)"\]$/);if(control)return findTree(els['train-list'],e=>e.attributes['data-train-key']===control[1]);const match=s.match(/^\[data-step="(\d)"\]$/);if(match)return steps[Number(match[1])-1];throw new Error('Unexpected selector '+s);},
    querySelectorAll:s=>{if(s==='[data-nav]')return nav;if(s==='[name=quota]')return quotas;if(s==='[name=train-type]')return types;if(s==='[name=train-type]:checked')return types.filter(i=>i.checked);if(s==='[name=alert-mode]')return modes;if(s==='[data-quick]')return els['quick-dates'].children;if(s==='.train-control input,[name=alert-mode]')return els['train-list'].children.map(row=>findTree(row,e=>e.attributes.role==='switch')).filter(Boolean).concat(modes);if(s==='#continue,#save-alerts,#refresh-trains')return [els.continue,els['save-alerts'],els['refresh-trains']];throw new Error('Unexpected selector '+s);}};
  const calls=[],session=new Map(),storage={getItem:k=>session.get(k)||null,setItem:(k,v)=>session.set(k,v),removeItem:k=>session.delete(k)};
  let fail=null,waitCreate=null,releaseCreate=null,waitSwitch=null,releaseSwitch=null,waitInitial=null,releaseInitial=null,initialFailure=false,offline=0,ambiguous=false;
  const catalog={trip_id:1,route:'tehran-mashhad',route_title:'تهران به مشهد',date:'1405-08-20',mode:'all',selected:[],excluded:[],revision:0,updated_at:1,options:[
    {key:'a',number:'188',time:'07:45',company:'فدک',wagon:'<img onerror=alert(1)>',capacity:0,price:1000000,missing:false,eligible:false,label:'ناموجود است'},
    {key:'b',number:'189',time:'12:30',company:'رجا',wagon:'زندگی',capacity:4,price:2000000,missing:false,eligible:false,label:'با فیلتر ساعت مطابق نیست',send_error_code:429}]};
  const trip={id:1,route:'tehran-mashhad',route_title:'تهران به مشهد',date:'1405-08-20',count:2,type:0,coupe:false,allow_partial_capacity:false,search_filters:{},mode:'all',revision:0};
  const fetch=async(url,opts)=>{assert.equal(url.searchParams.has('init_data'),false);const b=JSON.parse(opts.body);assert.equal(b.init_data,'signed');calls.push(b);if(offline>0){offline--;throw new TypeError('Failed to fetch');}
    if(fail){const error=fail;fail=null;return {ok:false,status:error.status,json:async()=>({ok:false,error:error.message})};}
    let data;if(b.action==='bootstrap')data={today:'2026-11-10',server_time:Math.floor(fakeNow/1000),user_id:'101',cities:[{code:'tehran',text:'تهران'},{code:'mashhad',text:'مشهد'}],approved:true,trips:[trip]};
    else if(b.action==='create_trip'){if(waitCreate)await waitCreate;data={created:true,trip:{...trip,...b.trip}};}
    else if(b.action==='initial_check'){if(waitInitial)await waitInitial;if(initialFailure){initialFailure=false;throw new TypeError('Initial response lost');}data={state:'ready',source:'cache',checked_at:Math.floor(fakeNow/1000),eligible_count:1,sent_count:1,message:'۱ قطار با شرایط سفرت پیدا شد. پیام بلیط‌ها به ربات ارسال شد.'};}
    else if(b.action==='save'){Object.assign(catalog,{mode:b.mode,selected:b.keys,excluded:b.excluded_keys,revision:catalog.revision+1});data=catalog;}
    else if(b.action==='set_train'){
      if(waitSwitch)await waitSwitch;
      const key=b.train_key,enabled=b.enabled;
      const on=catalog.mode==='all'||catalog.mode==='except'&&!catalog.excluded.includes(key)||catalog.mode==='selected'&&catalog.selected.includes(key);
      if(on!==enabled){
        if(catalog.mode==='all'||catalog.mode==='except'){catalog.excluded=enabled?catalog.excluded.filter(k=>k!==key):[...new Set([...catalog.excluded,key])];catalog.mode=catalog.excluded.length?'except':'all';}
        else{if(catalog.mode==='none')catalog.selected=[];catalog.selected=enabled?[...new Set([...catalog.selected,key])]:catalog.selected.filter(k=>k!==key);catalog.mode=catalog.selected.length?'selected':'none';}
        catalog.revision++;
      }
      data=catalog;if(ambiguous){ambiguous=false;throw new TypeError('Response interrupted');}
    }
    else if(b.action==='delete_trip')data={deleted:true,trip_id:b.trip_id};
    else if(b.action==='diagnostics')data={report_version:1,issues:['worker_never_ran'],cron:null};
    else data=catalog;return {ok:true,status:200,json:async()=>({ok:true,data:structuredClone(data)})};};
  const window={TrainCalendar:C,Telegram:{WebApp:{initData:auth?'signed':'',ready(){},expand(){},enableClosingConfirmation(){},disableClosingConfirmation(){}}},matchMedia:()=>({matches:false}),scrollTo(){},confirm:()=>true,addEventListener(){}};
  vm.runInNewContext(fs.readFileSync(require.resolve('../telegram-app.js'),'utf8'),{window,document,location:{href:'https://example.test/telegram-app.php',search:''},fetch,URL,URLSearchParams,crypto,sessionStorage:storage,localStorage:storage,AbortController,setTimeout,clearTimeout,Uint8Array,Intl,Date:FakeDate,console});
  await flush();return {els,calls,quotas,modes,nav,advance:ms=>{fakeNow+=ms;docEvents.visibilitychange();},serverSelection:keys=>{Object.assign(catalog,{mode:'selected',selected:keys,revision:catalog.revision+1});},offline:()=>{offline=2;},ambiguous:()=>{ambiguous=true;},fail:(status=409)=>{fail={status,message:'Concurrent edit'};},failInitial:()=>{initialFailure=true;},holdInitial:()=>{waitInitial=new Promise(resolve=>{releaseInitial=resolve;});return ()=>releaseInitial();},holdSwitch:()=>{waitSwitch=new Promise(resolve=>{releaseSwitch=resolve;});return ()=>releaseSwitch();},hold:()=>{waitCreate=new Promise(resolve=>{releaseCreate=resolve;});return ()=>releaseCreate();}};
}
const submit=ui=>ui.els['trip-form'].events.submit({preventDefault(){}});
function choose(ui,target,name){ui.els[target].onclick();ui.els['city-list'].children.find(e=>e.textContent===name).onclick();}
(async()=>{
  const noAuth=await fixture(false);assert.equal(noAuth.calls.length,0);assert.match(noAuth.els.notice.textContent,/تلگرام/);
  const ui=await fixture();assert.equal(ui.calls.length,1);assert.equal(ui.els['previous-month'].disabled,true);
  await submit(ui);assert.match(ui.els['form-error'].textContent,/مبدأ/);
  choose(ui,'origin','تهران');choose(ui,'destination','مشهد');ui.els['quick-dates'].children[1].onclick();
  await submit(ui);assert.equal(ui.els['step-2'].hidden,false);ui.els.plus.onclick();ui.els.partial.checked=true;
  await submit(ui);assert.equal(ui.els['step-3'].hidden,false);assert.ok(ui.els['review-details'].children.some(e=>e.textContent==='۲ نفر'));
  ui.els.back.onclick();assert.equal(ui.els.count.textContent,'۲');assert.equal(ui.els.partial.checked,true);await submit(ui);
  // Retry an ambiguous failure uses exactly the same request ID and payload.
  ui.fail();await submit(ui);const failed=ui.calls.at(-1);assert.equal(failed.action,'create_trip');assert.equal(ui.els['step-3'].hidden,false);
  await submit(ui);const retry=ui.calls.filter(c=>c.action==='create_trip').at(-1);assert.equal(retry.request_id,failed.request_id);assert.deepEqual(retry.trip,failed.trip);assert.equal(ui.els['success-view'].hidden,false);
  await flush();assert.equal(ui.calls.at(-1).action,'initial_check');assert.match(ui.els['success-check'].textContent,/به ربات ارسال شد/);assert.equal(ui.els['success-check-time'].hidden,false);
  await ui.els['success-alerts'].onclick();assert.equal(ui.els['alerts-view'].hidden,false);
  const secondCard=ui.els['train-list'].children[1].children[1];assert.ok(secondCard.children.some(e=>e.textContent==='با فیلتر ساعت مطابق نیست'));assert.ok(secondCard.children.some(e=>e.textContent.includes('محدودیت تلگرام')));
  assert.equal(ui.els['save-alerts'].hidden,true,'There is no separate save step for a switch');
  await ui.modes.find(i=>i.value==='selected').onchange();
  assert.equal(trainInput(ui,'188').checked,true,'Switching to current trains preserves enabled trains');
  await toggle(ui,'189',false);
  assert.equal(ui.calls.at(-1).action,'set_train');assert.equal(ui.calls.at(-1).enabled,false);
  assert.equal(trainInput(ui,'189').checked,false);assert.equal(trainInput(ui,'188').checked,true,'Other services stay enabled');
  assert.equal(trainInput(ui,'189').focused,true,'Keyboard focus returns to the same service');
  await ui.modes.find(i=>i.value==='none').onchange();
  assert.equal(trainInput(ui,'188').checked,false);assert.equal(trainInput(ui,'189').checked,false);
  await toggle(ui,'188',true);
  assert.equal(trainInput(ui,'188').attributes.role,'switch');assert.equal(trainInput(ui,'188').checked,true,'A sold-out train can be followed immediately');
  assert.equal(trainInput(ui,'189').checked,false,'Resuming one service cannot resume unrelated paused choices');
  ui.els['train-search'].value='نامربوط';ui.els['train-search'].oninput();assert.equal(ui.els['train-empty'].hidden,false);assert.match(ui.els['alert-count'].textContent,/۱/);
  ui.els['train-search'].value='';ui.els['train-search'].oninput();
  const beforeFilters=ui.calls.length;
  ui.els['filter-stock'].onclick();assert.equal(ui.els['train-list'].children.length,1,'Capacity filter includes a service with seats even if travel preferences exclude it');assert.equal(trainInput(ui,'188'),null);assert.ok(trainInput(ui,'189'));
  ui.els['filter-on'].onclick();assert.equal(ui.els['train-list'].children.length,1);assert.ok(trainInput(ui,'188'),'Followed sold-out trains remain visible in the enabled filter');assert.equal(trainInput(ui,'189'),null);
  assert.equal(ui.calls.length,beforeFilters,'List filters do not change settings or request fresh inventory');
  ui.els['filter-all'].onclick();assert.equal(ui.els['train-list'].children.length,2);assert.equal(ui.els['filter-all'].attributes['aria-pressed'],'true');
  ui.fail();await toggle(ui,'189',true);
  assert.equal(trainInput(ui,'189').checked,false,'Rejected switches return to confirmed server state');
  assert.equal(ui.els['save-alerts'].hidden,false,'Failed changes can be retried');
  await ui.els['save-alerts'].onclick();assert.equal(trainInput(ui,'189').checked,true);assert.equal(ui.els['save-alerts'].hidden,true);
  ui.ambiguous();await toggle(ui,'189',false);
  assert.equal(trainInput(ui,'189').checked,false,'An interrupted response is reconciled with the server');assert.equal(ui.els['save-alerts'].hidden,true);
  ui.offline();await toggle(ui,'189',true);
  assert.equal(trainInput(ui,'189').checked,false,'Offline UI shows the last confirmed state');assert.match(ui.els['alert-count'].textContent,/تأییدشده/);
  await ui.els['save-alerts'].onclick();assert.equal(trainInput(ui,'189').checked,true,'Idempotent retry completes an uncertain change');
  const concurrent=await fixture();await concurrent.els['trip-list'].children[0].children[2].onclick();concurrent.serverSelection(['b']);concurrent.fail();await concurrent.modes.find(i=>i.value==='selected').onchange();
  assert.equal(trainInput(concurrent,'188').checked,false);assert.equal(trainInput(concurrent,'189').checked,true);assert.equal(concurrent.els['save-alerts'].hidden,false,'A conflicting choice set cannot be reported as saved merely because the mode matches');
  const expired=await fixture();await expired.els['trip-list'].children[0].children[2].onclick();expired.fail(401);await toggle(expired,'188',false);
  assert.equal(expired.els['alert-controls'].hidden,true);assert.match(expired.els.notice.textContent,/نشست تلگرام منقضی/);assert.equal(expired.els['save-alerts'].hidden,true);
  const filtered=await fixture();await filtered.els['trip-list'].children[0].children[2].onclick();filtered.els['filter-on'].onclick();await toggle(filtered,'188',false);
  assert.equal(trainInput(filtered,'188'),null);assert.equal(filtered.els['filter-on'].focused,true,'Turning off the currently filtered service preserves keyboard focus on the filter');
  filtered.els['filter-all'].onclick();assert.equal(trainInput(filtered,'188').checked,false,'A hidden disabled service can be found and enabled again');
  const diagnostics=await fixture();await diagnostics.els['trip-list'].children[0].children[2].onclick();await diagnostics.els['notification-diagnostics'].onclick();
  assert.equal(diagnostics.calls.at(-1).action,'diagnostics');assert.equal(diagnostics.els['notification-report'].hidden,false);assert.match(diagnostics.els['notification-report'].value,/هنوز اجرای کرون ثبت نشده/);assert.equal(diagnostics.els['notification-diagnostics'].disabled,false);
  const rapid=await fixture();await rapid.els['trip-list'].children[0].children[2].onclick();const releaseSwitch=rapid.holdSwitch();const toggling=toggle(rapid,'188',false);await flush();await toggle(rapid,'189',false);
  assert.equal(rapid.els.main.inert,false,'The content and live status stay accessible during switch persistence');assert.equal(trainInput(rapid,'189').disabled,true);
  assert.equal(rapid.calls.filter(c=>c.action==='set_train').length,1);assert.equal(trainInput(rapid,'189').checked,true,'Busy switches cannot visually claim a second unsaved change');releaseSwitch();await toggling;assert.equal(trainInput(rapid,'188').checked,false);assert.equal(trainInput(rapid,'189').checked,true);
  const double=await fixture();choose(double,'origin','تهران');choose(double,'destination','مشهد');double.els['quick-dates'].children[1].onclick();await submit(double);await submit(double);
  const releaseInitial=double.holdInitial(),release=double.hold(),pending=submit(double);await flush();await submit(double);assert.equal(double.calls.filter(c=>c.action==='create_trip').length,1);assert.equal(double.els.main.inert,true);release();await pending;assert.equal(double.els.main.inert,false);
  assert.equal(double.els['success-view'].hidden,false);assert.match(double.els['success-check'].textContent,/در حال بررسی/,'A slow initial search cannot block confirmation of the saved trip');
  double.els['success-trips'].onclick();releaseInitial();await flush();assert.equal(double.els['trips-view'].hidden,false,'A late first-check response cannot change the current screen');
  const lostInitial=await fixture();choose(lostInitial,'origin','تهران');choose(lostInitial,'destination','مشهد');lostInitial.els['quick-dates'].children[1].onclick();await submit(lostInitial);await submit(lostInitial);lostInitial.failInitial();await submit(lostInitial);await flush();
  assert.equal(lostInitial.els['success-view'].hidden,false);assert.match(lostInitial.els['success-check'].textContent,/سفر ذخیره شده/);assert.equal(lostInitial.els['success-retry'].hidden,false);
  await lostInitial.els['success-retry'].onclick();assert.equal(lostInitial.calls.filter(c=>c.action==='create_trip').length,1,'Retrying the initial result must never create another trip');assert.match(lostInitial.els['success-check'].textContent,/به ربات ارسال شد/);
  const midnight=await fixture();midnight.els['quick-dates'].children[0].onclick();assert.match(midnight.els['selected-date'].textContent,/۱۹/);midnight.advance(60000);assert.equal(midnight.els['selected-date'].textContent,'انتخاب از تقویم شمسی');assert.match(midnight.els.notice.textContent,/روز عوض/);assert.equal(midnight.els['calendar-days'].children.find(e=>e.dataset.date==='1405-08-19').disabled,true);
  const removal=await fixture(),removeButton=()=>removal.els['trip-list'].children[0].children[3].children[1];
  removeButton().onclick();assert.equal(removal.els['delete-dialog'].open,true);assert.match(removal.els['delete-trip-summary'].textContent,/تهران به مشهد/);
  removal.els['cancel-delete'].onclick();assert.equal(removal.calls.filter(c=>c.action==='delete_trip').length,0);
  removeButton().onclick();removal.fail();await removal.els['confirm-delete'].onclick();assert.ok(removal.els['trip-list'].children[0].children.length>0,'Failed removal keeps the trip');assert.equal(removal.els['delete-dialog'].open,false);
  removeButton().onclick();const deleting=removal.els['confirm-delete'].onclick();await removal.els['confirm-delete'].onclick();await deleting;
  assert.equal(removal.calls.filter(c=>c.action==='delete_trip').length,2,'One failed attempt and one confirmed success; double clicks do not resend');assert.match(removal.els['trip-list'].children[0].textContent,/هنوز سفری/);
  console.log('PASS Unified UI: trip entry and deletion, immediate switches, sold-out services, idempotent retry, interrupted responses, offline recovery and concurrent settings');
})().catch(e=>{console.error(e);process.exitCode=1;});
