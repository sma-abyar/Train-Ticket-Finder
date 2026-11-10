'use strict';
const assert=require('node:assert/strict'), fs=require('node:fs'), vm=require('node:vm'), crypto=require('node:crypto');
const C=require('../miniapp-calendar.js');
class Element{
  constructor(value=''){this.value=value;this.children=[];this.dataset={};this.hidden=false;this.disabled=false;this.checked=false;this.events={};this.attributes={};this.textContent='';this.classList={toggle(){}};}
  append(...children){this.children.push(...children);}replaceChildren(...children){this.children=children;}
  setAttribute(k,v){this.attributes[k]=v;}removeAttribute(k){delete this.attributes[k];}
  addEventListener(k,v){this.events[k]=v;}focus(){}scrollIntoView(){}showModal(){this.open=true;}close(){this.open=false;}
  set innerHTML(_){throw new Error('Unsafe HTML rendering');}
}
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
    querySelector:s=>{if(s==='.brand-mark')return brand;if(s==='#success-view h1')return title;if(s==='[name=quota]:checked')return quotas.find(i=>i.checked);const match=s.match(/^\[data-step="(\d)"\]$/);if(match)return steps[Number(match[1])-1];throw new Error('Unexpected selector '+s);},
    querySelectorAll:s=>{if(s==='[data-nav]')return nav;if(s==='[name=quota]')return quotas;if(s==='[name=train-type]')return types;if(s==='[name=train-type]:checked')return types.filter(i=>i.checked);if(s==='[name=alert-mode]')return modes;if(s==='[data-quick]')return els['quick-dates'].children;if(s==='#continue,#save-alerts,#refresh-trains')return [els.continue,els['save-alerts'],els['refresh-trains']];throw new Error('Unexpected selector '+s);}};
  const calls=[],session=new Map(),storage={getItem:k=>session.get(k)||null,setItem:(k,v)=>session.set(k,v),removeItem:k=>session.delete(k)};
  let fail=null,waitCreate=null,releaseCreate=null;
  const catalog={trip_id:1,route:'tehran-mashhad',route_title:'تهران به مشهد',date:'1405-08-20',mode:'all',selected:[],excluded:[],revision:0,updated_at:1,options:[
    {key:'a',number:'188',time:'07:45',company:'فدک',wagon:'<img onerror=alert(1)>',capacity:0,price:1000000,missing:false,eligible:false,label:'ناموجود است'},
    {key:'b',number:'189',time:'12:30',company:'رجا',wagon:'زندگی',capacity:4,price:2000000,missing:false,eligible:false,label:'با فیلتر ساعت مطابق نیست',send_error_code:429}]};
  const trip={id:1,route:'tehran-mashhad',route_title:'تهران به مشهد',date:'1405-08-20',count:2,type:0,coupe:false,allow_partial_capacity:false,search_filters:{},mode:'all',revision:0};
  const fetch=async(url,opts)=>{assert.equal(url.searchParams.has('init_data'),false);const b=JSON.parse(opts.body);assert.equal(b.init_data,'signed');calls.push(b);
    if(fail){const error=fail;fail=null;return {ok:false,status:409,json:async()=>({ok:false,error})};}
    let data;if(b.action==='bootstrap')data={today:'2026-11-10',server_time:Math.floor(fakeNow/1000),user_id:'101',cities:[{code:'tehran',text:'تهران'},{code:'mashhad',text:'مشهد'}],approved:true,trips:[trip]};
    else if(b.action==='create_trip'){if(waitCreate)await waitCreate;data={created:true,trip:{...trip,...b.trip}};}
    else if(b.action==='save'){Object.assign(catalog,{mode:b.mode,selected:b.keys,excluded:b.excluded_keys,revision:catalog.revision+1});data=catalog;}
    else if(b.action==='delete_trip')data={deleted:true,trip_id:b.trip_id};
    else data=catalog;return {ok:true,status:200,json:async()=>({ok:true,data:structuredClone(data)})};};
  const window={TrainCalendar:C,Telegram:{WebApp:{initData:auth?'signed':'',ready(){},expand(){},enableClosingConfirmation(){},disableClosingConfirmation(){}}},matchMedia:()=>({matches:false}),scrollTo(){},confirm:()=>true,addEventListener(){}};
  vm.runInNewContext(fs.readFileSync(require.resolve('../telegram-app.js'),'utf8'),{window,document,location:{href:'https://example.test/telegram-app.php',search:''},fetch,URL,URLSearchParams,crypto,sessionStorage:storage,localStorage:storage,AbortController,setTimeout,clearTimeout,Uint8Array,Intl,Date:FakeDate,console});
  await flush();return {els,calls,quotas,modes,nav,advance:ms=>{fakeNow+=ms;docEvents.visibilitychange();},fail:()=>{fail='Concurrent edit';},hold:()=>{waitCreate=new Promise(resolve=>{releaseCreate=resolve;});return ()=>releaseCreate();}};
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
  await submit(ui);const retry=ui.calls.at(-1);assert.equal(retry.request_id,failed.request_id);assert.deepEqual(retry.trip,failed.trip);assert.equal(ui.els['success-view'].hidden,false);
  await ui.els['success-alerts'].onclick();assert.equal(ui.els['alerts-view'].hidden,false);
  const secondCard=ui.els['train-list'].children[1].children[1];assert.ok(secondCard.children.some(e=>e.textContent==='با فیلتر ساعت مطابق نیست'));assert.ok(secondCard.children.some(e=>e.textContent.includes('محدودیت تلگرام')));
  ui.modes.find(i=>i.value==='selected').onchange();let checks=ui.els['train-list'].children.map(e=>e.children[0]);assert.equal(checks[0].checked,false);
  checks[0].checked=true;checks[0].onchange();await ui.els['save-alerts'].onclick();assert.deepEqual(ui.calls.at(-1).keys,['a']);assert.match(ui.els['alert-count'].textContent,/ذخیره‌شده/);
  ui.els['train-search'].value='نامربوط';ui.els['train-search'].oninput();assert.equal(ui.els['train-empty'].hidden,false);assert.match(ui.els['alert-count'].textContent,/۱/);
  // Failed saves retain the selected sold-out service rather than replacing it.
  ui.els['train-search'].value='';ui.els['train-search'].oninput();checks=ui.els['train-list'].children.map(e=>e.children[0]);checks[1].checked=true;checks[1].onchange();ui.fail();await ui.els['save-alerts'].onclick();assert.match(ui.els['alert-count'].textContent,/۲/);assert.match(ui.els.notice.textContent,/حفظ/);
  const double=await fixture();choose(double,'origin','تهران');choose(double,'destination','مشهد');double.els['quick-dates'].children[1].onclick();await submit(double);await submit(double);
  const release=double.hold(),pending=submit(double);await flush();await submit(double);assert.equal(double.calls.filter(c=>c.action==='create_trip').length,1);assert.equal(double.els.main.inert,true);release();await pending;assert.equal(double.els.main.inert,false);
  const midnight=await fixture();midnight.els['quick-dates'].children[0].onclick();assert.match(midnight.els['selected-date'].textContent,/۱۹/);midnight.advance(60000);assert.equal(midnight.els['selected-date'].textContent,'انتخاب از تقویم شمسی');assert.match(midnight.els.notice.textContent,/روز عوض/);assert.equal(midnight.els['calendar-days'].children.find(e=>e.dataset.date==='1405-08-19').disabled,true);
  const removal=await fixture(),removeButton=()=>removal.els['trip-list'].children[0].children[3].children[1];
  removeButton().onclick();assert.equal(removal.els['delete-dialog'].open,true);assert.match(removal.els['delete-trip-summary'].textContent,/تهران به مشهد/);
  removal.els['cancel-delete'].onclick();assert.equal(removal.calls.filter(c=>c.action==='delete_trip').length,0);
  removeButton().onclick();removal.fail();await removal.els['confirm-delete'].onclick();assert.ok(removal.els['trip-list'].children[0].children.length>0,'Failed removal keeps the trip');assert.equal(removal.els['delete-dialog'].open,false);
  removeButton().onclick();const deleting=removal.els['confirm-delete'].onclick();await removal.els['confirm-delete'].onclick();await deleting;
  assert.equal(removal.calls.filter(c=>c.action==='delete_trip').length,2,'One failed attempt and one confirmed success; double clicks do not resend');assert.match(removal.els['trip-list'].children[0].textContent,/هنوز سفری/);
  console.log('PASS Unified UI: secure entry, validation, city/date choice, back navigation, count/partial persistence, retry identity, sold-out selection, search and conflict drafts');
})().catch(e=>{console.error(e);process.exitCode=1;});
