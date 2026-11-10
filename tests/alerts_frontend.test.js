'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { canonical, matches } = require('../telegram-alerts.js');

class Element {
  constructor(tag = 'div') {
    this.tagName = tag; this.children = []; this.events = {}; this.attributes = {};
    this.textContent = ''; this.value = ''; this.hidden = true; this.checked = false; this.disabled = false;
    this.style = {};
  }
  append(...nodes) { this.children.push(...nodes); }
  replaceChildren(...nodes) { this.children = nodes; }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(type, handler) { this.events[type] = handler; }
  fire(type) { return this.events[type]({ target: this, preventDefault() {} }); }
  set innerHTML(_) { throw new Error('Untrusted train data must never be rendered as HTML'); }
}

function bootstrap(authenticated = true) {
  const ids = ['trip-summary','notice','save','refresh','dirty-state','selected-count','footer-count','trains',
    'empty','total','search','updated','controls','footer','select-all','clear','except-mode'];
  const elements = Object.fromEntries(ids.map(id => [id, new Element()]));
  const modes = ['selected','all','except','none'].map(value => Object.assign(new Element('input'), { value }));
  const document = {
    getElementById: id => elements[id], documentElement: new Element('html'),
    querySelectorAll: selector => { assert.equal(selector, 'input[name=mode]'); return modes; },
    createElement: tag => new Element(tag), createTextNode: text => Object.assign(new Element('text'), { textContent: text }),
  };
  let data = {
    trip_id: 1, route: 'tehran-mashhad', route_title: 'تهران به مشهد', date: '1405-08-20', mode: 'selected', revision: 0,
    selected: ['a'], excluded: [], updated_at: 1700000000, stale: false,
    options: [
      { key:'a',number:'۱۸۸',time:'۱۱:۴۰',wagon:'قطار ناموجود',company:'فدک',capacity:0,price:3590000,missing:false },
      { key:'b',number:'192',time:'14:20',wagon:'<img onerror=alert(1)>',company:'رجا',capacity:2,price:1000000,missing:false },
      { key:'c',number:'۱۹۶',time:'19:00',wagon:'حفظ انتخاب',company:'بن ریل',capacity:null,price:null,missing:true },
    ],
  };
  const requests = [];
  let nextError = null;
  const window = { location:{href:'https://example.test/telegram-alerts.php?trip_id=1',search:'?trip_id=1'}, addEventListener() {},
    Telegram:{WebApp:{initData:authenticated ? 'signed-private-data' : '',colorScheme:'light',ready(){},expand(){},
      enableClosingConfirmation(){},disableClosingConfirmation(){},onEvent(){},HapticFeedback:{notificationOccurred(){}}}} };
  const fetch = async (url, options) => {
    assert.equal(url.searchParams.has('init_data'), false);
    const body = JSON.parse(options.body); requests.push(body);
    assert.equal(body.init_data, 'signed-private-data');
    if (nextError) {
      const error = nextError; nextError = null;
      return {ok:false,status:409,json:async()=>({ok:false,error})};
    }
    if (body.action === 'save') {
      data = {...data, mode:body.mode === 'selected' && !body.keys.length ? 'none' : body.mode,selected:body.keys,excluded:body.excluded_keys,revision:data.revision+1};
    }
    return {ok:true,status:200,json:async()=>({ok:true,data:structuredClone(data)})};
  };
  vm.runInNewContext(fs.readFileSync(require.resolve('../telegram-alerts.js'),'utf8'),
    {document,window,fetch,URL,URLSearchParams,Set,Number,String,Date,console});
  return {elements,modes,requests,setError: message=>{nextError=message;},addOption:item=>data.options.push(item)};
}

const flush = () => new Promise(resolve=>setImmediate(resolve));
function checkbox(ui, number) {
  const visit = node => {
    if (node.attributes['aria-label']?.includes(number)) return node;
    for (const child of node.children) { const found = visit(child); if (found) return found; }
  };
  return visit(ui.elements.trains);
}

(async () => {
  assert.equal(canonical('selected',new Set()),canonical('none',new Set()));
  assert.equal(matches({number:'۱۸۸',time:'۱۱:۴۰',wagon:'فدک',company:'فدک'},'188'),true);
  const ui = bootstrap(); await flush();
  assert.equal(ui.elements.controls.hidden,false);
  assert.equal(ui.elements.trains.children.length,3,'sold-out and missing trains must be listed');
  assert.equal(checkbox(ui,'۱۸۸').checked,true);
  assert.equal(ui.requests.length,1);

  await ui.elements.clear.fire('click');
  assert.equal(ui.elements['footer-count'].textContent,'هشدار خاموش');
  await ui.elements.save.fire('click');
  assert.equal(ui.requests.at(-1).mode,'none');
  assert.equal(ui.requests.at(-1).keys.length,0);

  const soldOut = checkbox(ui,'۱۸۸'); soldOut.checked = true; await soldOut.fire('change');
  await ui.elements.refresh.fire('click');
  assert.equal(checkbox(ui,'۱۸۸').checked,true,'refresh must preserve the unsaved draft');
  await ui.elements.save.fire('click');
  assert.equal(ui.requests.at(-1).mode,'selected');
  assert.deepEqual(ui.requests.at(-1).keys,['a']);

  ui.elements.search.value='196'; await ui.elements.search.fire('input');
  assert.equal(ui.elements.trains.children.length,1,'search must match Persian and English digits');
  ui.elements.search.value=''; await ui.elements.search.fire('input');
  const available = checkbox(ui,'192'); available.checked=true; await available.fire('change');
  ui.setError('تنظیمات تغییر کرده است');
  await ui.elements.save.fire('click');
  assert.equal(ui.elements.notice.textContent,'تنظیمات تغییر کرده است');
  assert.equal(checkbox(ui,'192').checked,true,'failed saves must not erase selections');
  assert.equal(ui.elements['dirty-state'].textContent,'تغییرات ثبت نشده');
  assert.equal(ui.elements.save.disabled,false);

  await ui.modes.find(input=>input.value==='all').fire('change');
  assert.equal(checkbox(ui,'۱۸۸').checked,true);
  assert.equal(checkbox(ui,'192').checked,true);
  const muted = checkbox(ui,'۱۸۸'); muted.checked=false; await muted.fire('change');
  await ui.elements.save.fire('click');
  assert.equal(ui.requests.at(-1).mode,'except');
  assert.deepEqual(ui.requests.at(-1).excluded_keys,['a']);
  assert.equal(checkbox(ui,'192').checked,true);
  ui.addOption({key:'future',number:'999',time:'20:00',wagon:'قطار تازه',company:'رجا',capacity:3,price:1000000,missing:false});
  await ui.elements.refresh.fire('click');
  assert.equal(checkbox(ui,'999').checked,true,'a new service must stay enabled in all-except mode');
  const restored = checkbox(ui,'۱۸۸'); restored.checked=true; await restored.fire('change');
  await ui.elements.save.fire('click');
  assert.equal(ui.requests.at(-1).mode,'all');
  assert.equal(ui.requests.at(-1).excluded_keys.length,0);
  const outside = bootstrap(false); await flush();
  assert.equal(outside.requests.length,0,'outside Telegram the app must not send unauthenticated requests');
  assert.equal(outside.elements.controls.hidden,true);
  console.log('PASS Mini app UI: full list, sold-out selection, clear/pause, search, draft preservation and save errors');
})().catch(error=>{ console.error(error); process.exitCode=1; });
