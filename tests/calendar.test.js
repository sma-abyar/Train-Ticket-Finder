'use strict';
const assert=require('node:assert/strict');
const C=require('../miniapp-calendar.js');
let dates=0;
for(let y=1390;y<=1420;y++){
  const seen=new Set();
  for(let m=1;m<=12;m++){
    const days=C.month(y,m);assert.equal(days[0].key,`${y}-${String(m).padStart(2,'0')}-01`);
    assert.equal(days.length,m<=6?31:m<=11?30:days.length);assert.ok([29,30,31].includes(days.length));
    days.forEach((d,i)=>{assert.equal(d.day,i+1);assert.ok(!seen.has(d.key));seen.add(d.key);if(i)assert.equal(d.weekday,(days[i-1].weekday+1)%7);dates++;});
  }
  assert.ok([365,366].includes(seen.size));
}
assert.equal(C.month(1399,12).length,30);assert.equal(C.month(1400,12).length,29);assert.equal(C.month(1403,12).length,30);assert.equal(C.month(1404,12).length,29);
assert.equal(C.key(C.parts(new Date('2026-11-10T12:00:00Z'))),'1405-08-19');
assert.equal(C.month(1405,8)[18].weekday,3); // Tuesday: Saturday is column zero, at the right in RTL.
console.log(`PASS Persian calendar: ${dates} unique dates, leap years, month lengths and weekday order`);
