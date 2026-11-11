'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),crypto=require('node:crypto').webcrypto;
class Element{
  constructor(tag='div'){this.tagName=tag.toUpperCase();this.children=[];this.attributes={};this.events={};this.value='';this.textContent='';this.hidden=false;this.checked=false;this.dataset={};}
  append(...nodes){this.children.push(...nodes);}replaceChildren(...nodes){this.children=nodes;}setAttribute(key,value){this.attributes[key]=value;}addEventListener(key,handler){this.events[key]=handler;}focus(){}scrollIntoView(){}showModal(){this.open=true;}close(){this.open=false;}
  set innerHTML(_){throw new Error('Unsafe identity rendering');}
}
const flush=()=>new Promise(resolve=>setImmediate(resolve));
function find(node,test){if(test(node))return node;for(const child of node.children){const match=find(child,test);if(match)return match;}return null;}
function fixture(){
  const html=fs.readFileSync(require.resolve('../telegram-app.html'),'utf8'),els={},all=[];
  for(const [,tag,id,attrs]of html.matchAll(/<(\w+)[^>]*?\bid="([^"]+)"([^>]*)>/g)){assert.equal(els[id],undefined,'Duplicate DOM ID '+id);const el=new Element(tag);el.hidden=/\bhidden\b/.test(attrs);if(attrs.includes('data-people-control'))el.setAttribute('data-people-control','');els[id]=el;all.push(el);}
  const radios={'person-age':['0','1','2'].map(value=>Object.assign(new Element('input'),{value})),'person-gender':['1','2'].map(value=>Object.assign(new Element('input'),{value}))};
  Object.values(radios).flat().forEach(el=>{el.setAttribute('data-people-control','');all.push(el);});
  const doc={getElementById:id=>{assert.ok(els[id],id);return els[id];},createElement:tag=>{const el=new Element(tag);all.push(el);return el;},querySelectorAll:selector=>{const name=selector.match(/^\[name=([^\]]+)\]$/);if(name)return radios[name[1]];if(selector==='[data-people-control]')return all.filter(el=>'data-people-control'in el.attributes);throw new Error(selector);}};
  let consent=true,fail=null,ambiguous=false,version=1,nextId=4,hold=null,release=null;
  const state={travelers:[{id:1,first_name:'اول',last_name:'آزمایشی',national_code:'0012345678',passenger_type:0,gender:1,wheelchair:false,version:'p1'},{id:3,first_name:'نوزاد',last_name:'آزمایشی',national_code:'0012345679',passenger_type:2,gender:2,wheelchair:false,version:'p3'}],lists:[{id:11,name:'خانواده',member_ids:[1],seat_count:1,version:'l1'}],contact:{person_name:'',phone_number:'',email:'',version:'c1'}};
  const calls=[],ledger=new Map();
  const api=async(action,body={})=>{
    body=structuredClone(body);
    calls.push({action,...structuredClone(body)});if(hold)await hold;if(fail){const failure=fail;fail=null;throw failure;}
    let mutation;if(action!=='people_load'){
      mutation=ledger.get(body.request_id);
      if(!mutation){const id=body.id;
        if(action==='people_save_traveler'){const person={...body.traveler,id:id??nextId++,version:'p'+(++version)};state.travelers=state.travelers.filter(p=>p.id!==person.id);state.travelers.unshift(person);mutation={kind:'traveler',id:person.id};}
        else if(action==='people_save_list'){const row={id:id??nextId++,name:body.name,member_ids:body.member_ids,seat_count:body.member_ids.filter(id=>state.travelers.find(p=>p.id===id).passenger_type!==2).length,version:'l'+(++version)};state.lists=state.lists.filter(l=>l.id!==row.id);state.lists.unshift(row);mutation={kind:'list',id:row.id};}
        else if(action==='people_delete_list'){state.lists=state.lists.filter(l=>l.id!==id);mutation={kind:'list',id,deleted:true};}
        else if(action==='people_delete_traveler'){state.travelers=state.travelers.filter(p=>p.id!==id);state.lists.forEach(l=>l.member_ids=l.member_ids.filter(member=>member!==id));mutation={kind:'traveler',id,deleted:true};}
        else if(action==='people_save_contact'){state.contact={person_name:body.person_name,phone_number:body.phone_number,email:body.email,version:'c'+(++version)};mutation={kind:'contact',id:0};}
        else throw new Error(action);
        ledger.set(body.request_id,mutation);
        if(ambiguous){ambiguous=false;throw new TypeError('Response interrupted after commit');}
      }
    }
    return structuredClone({...state,...(mutation?{mutation}:{})});
  };
  const window={document:doc,crypto,confirm:()=>consent,scrollTo(){},TrainMotion:{sync(){}}};
  vm.runInNewContext(fs.readFileSync(require.resolve('../miniapp-people.js'),'utf8'),{window,Uint8Array,console});
  let working=false;const app=window.TrainPeople.create({api,document:doc,setWorking:value=>working=value,onChange(){},notice(){}});
  return {app,els,radios,state,calls,confirm:value=>consent=value,fail:(status=409)=>fail=Object.assign(new Error('Concurrent edit'),{status}),ambiguous:()=>ambiguous=true,hold:()=>{hold=new Promise(resolve=>release=resolve);return ()=>{release();hold=null;};},working:()=>working};
}
const submit=(ui,id)=>ui.els[id].events.submit({preventDefault(){}});
const card=(ui,name)=>ui.els['people-records'].children.find(row=>row.children[0].children[0].textContent===name);
function choose(ui,name,value){ui.radios[name].forEach(input=>input.checked=input.value===String(value));}
function identity(ui,code='۰۰۱۲۳۴۵۶۸۰'){ui.els['person-first-name'].value='تازه';ui.els['person-last-name'].value='آزمایشی';ui.els['person-national-code'].value=code;choose(ui,'person-age',2);choose(ui,'person-gender',2);ui.els['person-form'].events.input();}
(async()=>{
  const ui=fixture();await ui.app.open();assert.equal(ui.els['people-root'].hidden,false);assert.equal(ui.els['people-records'].children.length,2);assert.equal(ui.els['people-contact'].hidden,false);assert.equal(find(ui.els['people-records'],el=>el.textContent.includes('0012345678')),null,'National IDs stay masked in the list');
  ui.els['people-search'].value='۰۰۱۲۳۴۵۶۷۸';ui.els['people-search'].oninput();assert.equal(ui.els['people-records'].children.length,1);assert.equal(ui.calls.length,1,'Address-book search stays local');
  ui.els['people-tabs-lists'].onclick();ui.els['people-add'].onclick();ui.els['list-name'].value='هم‌سفرها';ui.els['people-list-form'].events.input();
  const member=ui.els['people-members'].children[0].children[1];member.checked=true;member.onchange();ui.els['list-add-person'].onclick();identity(ui);await submit(ui,'person-form');
  assert.equal(ui.els['people-list'].hidden,false);assert.equal(ui.els['list-name'].value,'هم‌سفرها');assert.match(ui.els['member-count'].textContent,/۲ مسافر، ۱ صندلی/,'Infants and occupied seats remain different');assert.equal(ui.app.isDirty(),true,'The containing list still needs saving');
  await submit(ui,'people-list-form');const listRequest=ui.calls.find(call=>call.action==='people_save_list');assert.deepEqual(listRequest.member_ids,[1,4]);assert.equal(ui.els['people-root'].hidden,false);
  let group=card(ui,'هم‌سفرها');group.children[1].children[0].onclick();const infantSwitch=ui.els['people-members'].children.find(label=>label.children[0].children[0].textContent==='تازه آزمایشی').children[1];infantSwitch.checked=false;infantSwitch.onchange();await submit(ui,'people-list-form');assert.deepEqual(ui.state.lists[0].member_ids,[1]);
  group=card(ui,'هم‌سفرها');group.children[1].children[1].onclick();assert.equal(ui.els['people-delete-dialog'].open,true);ui.app.back();assert.equal(ui.els['people-delete-dialog'].open,false);assert.equal(ui.calls.filter(call=>call.action==='people_delete_list').length,0);
  group.children[1].children[1].onclick();await ui.els['people-delete-confirm'].onclick();assert.equal(ui.state.lists.length,1);assert.equal(ui.state.travelers.length,3,'Deleting a list preserves its people');
  ui.els['people-tabs-travelers'].onclick();card(ui,'اول آزمایشی').children[1].children[0].onclick();ui.els['person-first-name'].value='ویرایش';ui.els['person-form'].events.input();ui.confirm(false);ui.app.back();assert.equal(ui.els['people-person'].hidden,false);ui.confirm(true);ui.app.back();assert.equal(ui.els['people-root'].hidden,false);
  ui.els['people-add'].onclick();identity(ui,'0012345681');ui.ambiguous();await submit(ui,'person-form');assert.equal(ui.els['people-person'].hidden,false);const first=ui.calls.at(-1);await submit(ui,'person-form');const retry=ui.calls.at(-1);assert.equal(first.request_id,retry.request_id);assert.equal(ui.state.travelers.length,4,'A lost create response cannot duplicate a person');
  card(ui,'تازه آزمایشی').children[1].children[0].onclick();ui.els['person-last-name'].value='نگه‌دار';ui.els['person-form'].events.input();ui.fail();await submit(ui,'person-form');assert.equal(ui.els['person-last-name'].value,'نگه‌دار');assert.equal(ui.els['people-retry'].hidden,false,'A concurrent edit leaves the draft intact and offers refresh');ui.confirm(false);ui.els['people-retry'].onclick();assert.equal(ui.els['people-person'].hidden,false);ui.confirm(true);await ui.els['people-retry'].onclick();
  ui.els['people-contact'].onclick();assert.equal(ui.els['people-contact-panel'].hidden,false);ui.els['contact-person_name'].value='خریدار';ui.els['contact-phone_number'].value='۰۹۱۲۳۴۵۶۷۸۹';ui.els['contact-email'].value='';await submit(ui,'people-contact-form');assert.equal(ui.state.contact.phone_number,'09123456789');assert.equal(ui.els['people-root'].hidden,false);
  const direct=fixture();await direct.app.open('new_traveler');assert.equal(direct.els['people-person'].hidden,false,'A bot add-person link opens the form after loading');direct.app.back();await direct.app.open('new_list');assert.equal(direct.els['people-list'].hidden,false);direct.app.back();await direct.app.open('contact');assert.equal(direct.els['people-contact-panel'].hidden,false);
  const loading=fixture(),release=loading.hold(),pending=loading.app.open();await flush();assert.equal(loading.els['people-loading'].hidden,false);assert.equal(loading.app.canLeave(),false);release();await pending;assert.equal(loading.els['people-loading'].hidden,true);assert.equal(loading.working(),false);
  console.log('PASS Passenger UI: masked/searchable history, list member switches, nested person creation, infant seat counts, edit/cancel, deletion, ambiguous retry, stale drafts, contact, direct links and loading');
})().catch(error=>{console.error(error);process.exitCode=1;});
