/* Persian calendar uses the platform ICU calendar; dates are anchored at UTC noon. */
(function (root) {
  'use strict';
  const dayMs = 86400000;
  const formatter = new Intl.DateTimeFormat('en-US-u-ca-persian', {year:'numeric',month:'numeric',day:'numeric',timeZone:'UTC'});
  const parts = date => {
    const p = Object.fromEntries(formatter.formatToParts(date).map(x => [x.type,x.value]));
    return {y:Number(p.year),m:Number(p.month),d:Number(p.day)};
  };
  const key = p => `${p.y}-${String(p.m).padStart(2,'0')}-${String(p.d).padStart(2,'0')}`;
  function start(y) {
    if (!Number.isInteger(y) || y < 1300 || y > 1500) throw new RangeError('Unsupported calendar year');
    for (let day=18;day<=23;day++) {
      const date = new Date(Date.UTC(y+621,2,day,12));
      const p = parts(date);
      if (p.y===y && p.m===1 && p.d===1) return date;
    }
    throw new RangeError('Calendar unavailable');
  }
  function month(y,m) {
    if (!Number.isInteger(m) || m<1 || m>12) throw new RangeError('Invalid month');
    const first = new Date(start(y).getTime() + ((m-1)*31 - Math.max(0,m-7))*dayMs);
    const days=[];
    for (let i=0;i<31;i++) {
      const date=new Date(first.getTime()+i*dayMs), p=parts(date);
      if (p.m!==m || p.y!==y) break;
      days.push({key:key(p),day:p.d,weekday:(date.getUTCDay()+1)%7});
    }
    return days;
  }
  const api={parts,key,month,dayMs};
  if (typeof module!=='undefined') module.exports=api;
  root.TrainCalendar=api;
})(typeof window!=='undefined'?window:globalThis);
