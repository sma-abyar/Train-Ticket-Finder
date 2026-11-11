(function(root){
  'use strict';
  function create(context){
    const doc=context.document||root.document,$=id=>doc.getElementById(id),fa=value=>String(value).replace(/\d/g,d=>'۰۱۲۳۴۵۶۷۸۹'[d]);
    const en=value=>String(value).replace(/[۰-۹٠-٩]/g,c=>String(c.charCodeAt(0)>1775?c.charCodeAt(0)-1776:c.charCodeAt(0)-1632));
    const norm=value=>en(value).replace(/ي/g,'ی').replace(/ك/g,'ک').toLowerCase();
    const ages=['بزرگسال','کودک','نوزاد'],genders={1:'آقا',2:'خانم'};
    let data=null,tab='travelers',panel='root',busy=false,dirty=false,editing=null,parent=null,deleting=null,pending=null;
    let listIds=new Set();
    const element=(tag,text,className)=>{const el=doc.createElement(tag);if(text!==undefined)el.textContent=text;if(className)el.className=className;return el;};
    const radio=(name,value)=>doc.querySelectorAll('[name='+name+']').forEach(input=>input.checked=String(value)===input.value);
    const chosen=name=>[...doc.querySelectorAll('[name='+name+']')].find(input=>input.checked)?.value;
    const isDirty=()=>dirty||!!parent?.dirty;
    function signal(){context.onChange?.();root.TrainMotion?.sync();}
    function error(message=''){$('people-error').textContent=message;$('people-error').hidden=!message;if(message)$('people-error').scrollIntoView({block:'nearest'});}
    function lock(value){busy=value;context.setWorking?.(value);$('people-view').setAttribute('aria-busy',String(value));doc.querySelectorAll('[data-people-control]').forEach(control=>control.disabled=value);}
    function canLeave(){if(busy)return false;if(isDirty()&&!root.confirm('تغییرات مسافران ذخیره نشده است. بدون ذخیره خارج می‌شوی؟'))return false;dirty=false;parent=null;signal();return true;}
    function show(next){panel=next;['root','person','list','contact'].forEach(name=>$(name==='contact'?'people-contact-panel':'people-'+name).hidden=name!==next);$('people-heading-actions').hidden=next!=='root';$('people-title').textContent=next==='person'?(editing?'ویرایش مسافر':'مسافر جدید'):next==='list'?(editing?'ویرایش لیست':'لیست جدید'):next==='contact'?'اطلاعات تماس رزرو':'مسافران';error();signal();$('people-title').focus({preventScroll:true});root.scrollTo?.({top:0});}
    function back(){
      if(busy)return true;
      if($('people-delete-dialog').open){$('people-delete-dialog').close();deleting=null;signal();return true;}
      if(panel==='root')return false;
      if(dirty&&!root.confirm('این تغییرات ذخیره نشده‌اند. بدون ذخیره برگردیم؟'))return true;
      if(panel==='person'&&parent){const saved=parent;parent=null;editing=saved.editing;listIds=saved.ids;dirty=saved.dirty;show('list');$('list-name').value=saved.name;renderMembers();return true;}
      dirty=false;editing=null;parent=null;show('root');renderRoot();return true;
    }
    const getBackLabel=()=>panel==='root'?'بازگشت به سفرها':panel==='person'&&parent?'بازگشت به لیست':'بازگشت به مسافران';
    function renderRoot(){
      if(!data)return;
      $('people-tabs-travelers').setAttribute('aria-pressed',String(tab==='travelers'));$('people-tabs-lists').setAttribute('aria-pressed',String(tab==='lists'));
      $('people-add').textContent=tab==='travelers'?'+ افزودن مسافر':'+ ساخت لیست';$('people-search').placeholder=tab==='travelers'?'نام یا کد ملی مسافر':'نام لیست یا اعضای آن';
      $('people-count').textContent=tab==='travelers'?fa(data.travelers.length)+' مسافر ذخیره‌شده':fa(data.lists.length)+' لیست مسافر';
      $('people-contact-status').textContent=data.contact.person_name&&data.contact.phone_number?'اطلاعات تماس ثبت شده است':'برای رزرو سریع‌تر تکمیل کن';
      const search=norm($('people-search').value),map=new Map(data.travelers.map(person=>[person.id,person]));
      const rows=(tab==='travelers'?data.travelers:data.lists).filter(row=>norm(tab==='travelers'?row.first_name+' '+row.last_name+' '+row.national_code:row.name+' '+row.member_ids.map(id=>{const p=map.get(id);return p?p.first_name+' '+p.last_name:'';}).join(' ')).includes(search));
      const container=$('people-records');container.replaceChildren();
      rows.forEach(row=>{
        const card=element('article',undefined,'people-card'),body=element('div',undefined,'people-card-body');
        const name=tab==='travelers'?row.first_name+' '+row.last_name:row.name;
        body.append(element('h2',name),element('p',tab==='travelers'?[ages[row.passenger_type]||'گروه سنی نامشخص',genders[row.gender]||'جنسیت نامشخص',row.wheelchair?'نیاز به ویلچر':''].filter(Boolean).join('، '):fa(row.member_ids.length)+' مسافر، '+fa(row.seat_count)+' صندلی','people-meta'));
        if(tab==='travelers'){const code=element('span','کد ملی: '+fa('••••••'+String(row.national_code).slice(-4)),'people-code');body.append(code);}
        else body.append(element('p',row.member_ids.slice(0,3).map(id=>{const p=map.get(id);return p?p.first_name+' '+p.last_name:'';}).join('، ')+(row.member_ids.length>3?' و '+fa(row.member_ids.length-3)+' نفر دیگر':'')||'هنوز عضوی ندارد','people-meta'));
        const controls=element('div',undefined,'people-card-actions'),edit=element('button','ویرایش','text-button'),remove=element('button','حذف','text-button people-danger');
        for(const button of [edit,remove]){button.type='button';button.setAttribute('data-people-control','');}
        edit.setAttribute('aria-label','ویرایش '+name);remove.setAttribute('aria-label','حذف '+name);
        edit.onclick=()=>tab==='travelers'?openPerson(row):openList(row);remove.onclick=()=>askDelete(tab==='travelers'?'traveler':'list',row);
        controls.append(edit,remove);card.append(body,controls);container.append(card);
      });
      $('people-empty').hidden=rows.length>0;$('people-empty').textContent=search?'موردی با این جست‌وجو پیدا نشد.':tab==='travelers'?'هنوز مسافری ذخیره نکرده‌ای. از «افزودن مسافر» شروع کن.':'برای خانواده یا هم‌سفرها یک لیست بساز؛ بعداً هم می‌توانی اعضایش را تغییر بدهی.';
      signal();
    }
    function openPerson(person=null,fromList=false){
      if(busy)return;
      if(fromList)parent={editing,name:$('list-name').value,ids:new Set(listIds),dirty};else parent=null;
      editing=person;dirty=false;pending=null;show('person');
      $('person-first-name').value=person?.first_name||'';$('person-last-name').value=person?.last_name||'';$('person-national-code').value=person?fa(person.national_code):'';
      radio('person-age',person?.passenger_type??0);radio('person-gender',person?.gender??'');$('person-wheelchair').checked=person?.wheelchair??false;signal();
    }
    function openList(list=null){
      if(busy)return;editing=list;parent=null;dirty=false;pending=null;listIds=new Set(list?.member_ids||[]);show('list');$('list-name').value=list?.name||'';$('member-search').value='';renderMembers();
    }
    function renderMembers(){
      const search=norm($('member-search').value),container=$('people-members');container.replaceChildren();
      data.travelers.filter(person=>norm(person.first_name+' '+person.last_name+' '+person.national_code).includes(search)).forEach(person=>{
        const label=element('label',undefined,'toggle-row people-member'),text=element('span'),name=element('strong',person.first_name+' '+person.last_name),meta=element('small',(ages[person.passenger_type]||'')+'، '+fa('••••••'+String(person.national_code).slice(-4)));
        text.append(name,meta);const input=element('input');input.type='checkbox';input.setAttribute('role','switch');input.setAttribute('aria-label','عضویت '+person.first_name+' '+person.last_name);input.setAttribute('data-people-control','');input.checked=listIds.has(person.id);
        input.onchange=()=>{if(busy)return;input.checked?listIds.add(person.id):listIds.delete(person.id);dirty=true;renderMemberCount();signal();};label.append(text,input);container.append(label);
      });
      $('members-empty').hidden=container.children.length>0;$('members-empty').textContent=data.travelers.length?'مسافری با این جست‌وجو پیدا نشد.':'اول مسافر اضافه کن؛ بعد همین‌جا عضو لیست می‌شود.';renderMemberCount();
    }
    function renderMemberCount(){const members=data.travelers.filter(person=>listIds.has(person.id));$('member-count').textContent=fa(members.length)+' مسافر، '+fa(members.filter(person=>person.passenger_type!==2).length)+' صندلی';}
    function openContact(){if(busy||!data)return;editing=null;parent=null;dirty=false;pending=null;show('contact');for(const field of ['person_name','phone_number','email'])$('contact-'+field).value=data.contact[field]||'';}
    async function load(section='travelers'){
      if(busy)return;lock(true);error();$('people-retry').hidden=true;$('people-loading').hidden=false;if(!data)$('people-root').hidden=true;let loaded=false;
      try{data=await context.api('people_load');dirty=false;parent=null;editing=null;show('root');tab=section==='lists'||section==='new_list'?'lists':'travelers';$('people-search').value='';renderRoot();loaded=true;}
      catch(failure){error(failure.message);$('people-retry').hidden=false;}
      finally{$('people-loading').hidden=true;lock(false);signal();}
      if(loaded){if(section==='new_traveler')openPerson();else if(section==='new_list')openList();else if(section==='contact')openContact();}
    }
    function request(action,payload){const serialized=JSON.stringify([action,payload]);if(!pending||pending.serialized!==serialized){pending={action,payload,serialized,id:[...root.crypto.getRandomValues(new Uint8Array(16))].map(value=>value.toString(16).padStart(2,'0')).join('')};}return pending;}
    async function mutate(action,payload,onSuccess){
      if(busy)return;const operation=request(action,payload);lock(true);error();$('people-retry').hidden=true;
      try{const response=await context.api(action,{...payload,request_id:operation.id});data=response;pending=null;dirty=false;const message=onSuccess(response.mutation);context.notice?.(message||(response.mutation.deleted?'حذف شد.':response.mutation.kind==='traveler'?'مسافر ذخیره شد.':response.mutation.kind==='list'?'لیست ذخیره شد.':'اطلاعات تماس ذخیره شد.'));}
      catch(failure){error(failure.status?failure.message:'پاسخ ذخیره دریافت نشد. دوباره بزن؛ درخواست تکراری ثبت نمی‌شود.');$('people-retry').hidden=failure.status!==409;}
      finally{lock(false);signal();}
    }
    async function savePerson(event){
      event.preventDefault();if(busy)return;
      const person={first_name:$('person-first-name').value.trim(),last_name:$('person-last-name').value.trim(),national_code:en($('person-national-code').value).replace(/\s/g,''),passenger_type:Number(chosen('person-age')),gender:Number(chosen('person-gender')),wheelchair:$('person-wheelchair').checked};
      if(!person.first_name||!person.last_name){error('نام و نام خانوادگی مسافر را وارد کن.');return;}
      if(!/^\d{10}$/.test(person.national_code)){error('کد ملی را با ۱۰ رقم وارد کن.');$('person-national-code').focus();return;}
      if(![1,2].includes(person.gender)){error('جنسیت مسافر را انتخاب کن.');return;}
      await mutate('people_save_traveler',{id:editing?.id??null,version:editing?.version??null,traveler:person},result=>{
        if(parent){const saved=parent;parent=null;editing=saved.editing;listIds=saved.ids;listIds.add(result.id);show('list');$('list-name').value=saved.name;$('member-search').value='';dirty=true;renderMembers();return 'مسافر ذخیره و انتخاب شد؛ برای ثبت عضویت، لیست را هم ذخیره کن.';}
        else{tab='travelers';editing=null;$('people-search').value='';show('root');renderRoot();}
      });
    }
    async function saveList(event){event.preventDefault();if(!$('list-name').value.trim()){error('برای لیست یک نام انتخاب کن.');$('list-name').focus();return;}
      await mutate('people_save_list',{id:editing?.id??null,version:editing?.version??null,name:$('list-name').value.trim(),member_ids:[...listIds].sort((a,b)=>a-b)},()=>{tab='lists';editing=null;$('people-search').value='';show('root');renderRoot();});}
    async function saveContact(event){event.preventDefault();await mutate('people_save_contact',{version:data.contact.version,person_name:$('contact-person_name').value.trim(),phone_number:en($('contact-phone_number').value).replace(/[\s()-]/g,''),email:$('contact-email').value.trim()},()=>{show('root');renderRoot();});}
    function askDelete(kind,row){if(busy)return;deleting={kind,row};$('people-delete-title').textContent=kind==='traveler'?'این مسافر حذف شود؟':'این لیست حذف شود؟';$('people-delete-name').textContent=kind==='traveler'?row.first_name+' '+row.last_name:row.name;$('people-delete-help').textContent=kind==='traveler'?'مسافر از همهٔ لیست‌های خودت هم برداشته می‌شود. سایر مسافران باقی می‌مانند.':'فقط این لیست حذف می‌شود؛ مسافران ذخیره‌شده باقی می‌مانند.';$('people-delete-dialog').showModal();$('people-delete-cancel').focus();signal();}
    async function confirmDelete(){if(!deleting||busy)return;const target=deleting;await mutate('people_delete_'+target.kind,{id:target.row.id,version:target.row.version},()=>{$('people-delete-dialog').close();deleting=null;show('root');renderRoot();});if(deleting){$('people-delete-dialog').close();deleting=null;}}
    $('people-tabs-travelers').onclick=()=>{if(busy)return;tab='travelers';$('people-search').value='';renderRoot();};$('people-tabs-lists').onclick=()=>{if(busy)return;tab='lists';$('people-search').value='';renderRoot();};
    $('people-add').onclick=()=>tab==='travelers'?openPerson():openList();$('people-search').oninput=renderRoot;$('people-contact').onclick=openContact;$('people-contact-shortcut').onclick=openContact;$('member-search').oninput=renderMembers;$('list-add-person').onclick=()=>openPerson(null,true);
    $('person-form').addEventListener('submit',savePerson);$('people-list-form').addEventListener('submit',saveList);$('people-contact-form').addEventListener('submit',saveContact);
    ['person-form','people-list-form','people-contact-form'].forEach(id=>$(id).addEventListener('input',()=>{dirty=true;signal();}));
    ['person-cancel','list-cancel','contact-cancel'].forEach(id=>$(id).onclick=back);
    $('people-refresh').onclick=()=>load(tab);$('people-retry').onclick=()=>{if(canLeave())return load(tab);};
    $('people-delete-cancel').onclick=()=>{if(!busy){$('people-delete-dialog').close();deleting=null;signal();}};$('people-delete-confirm').onclick=confirmDelete;
    $('people-delete-dialog').addEventListener('cancel',event=>{if(busy)event.preventDefault();else deleting=null;});
    return {open:load,back,canLeave,isDirty,getBackLabel};
  }
  if(typeof module!=='undefined'&&module.exports)module.exports={create};else root.TrainPeople={create};
})(typeof window==='undefined'?globalThis:window);
