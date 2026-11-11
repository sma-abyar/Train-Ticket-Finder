'use strict';
const assert = require('node:assert/strict');
const { createMotion } = require('../miniapp-motion.js');

function fixture({ reduced = false, animate = true } = {}) {
  const frames = [], animations = [], observers = [], mediaListeners = [];
  const media = { matches:reduced, addEventListener:(_, fn) => mediaListeners.push(fn) };
  const outer = { left:100, top:20, width:310, height:56 };
  const targets = [204, 104, 4].map((x, index) => ({
    getBoundingClientRect:() => ({ left:outer.left + 1 + x * outer.width / 310, top:outer.top + 5, width:(index === 1 ? 94 : 96) * outer.width / 310, height:46 }),
  }));
  let selected = 0;
  const group = { tagName:'DIV', dataset:{}, clientLeft:1, clientTop:1, scrollLeft:0, scrollTop:0, listeners:{},
    getBoundingClientRect:() => outer,
    querySelector:() => ({ closest:() => targets[selected] }),
    append(marker) { this.marker = marker; },
    addEventListener(name, listener) { this.listeners[name] = listener; },
  };
  const doc = { getElementById:id => id === 'quota-choices' ? group : null, createElement:() => {
    const marker = { style:{}, hidden:true, setAttribute(){},
      getBoundingClientRect() {
        if (this.hidden) return { left:0, top:0, width:0, height:0 };
        if (this.rendered) return this.rendered;
        const values = this.style.transform.match(/translate3d\(([-.\d]+)px,([-.\d]+)px,0\)/);
        return { left:outer.left + 1 + Number(values[1]), top:outer.top + 1 + Number(values[2]), width:parseFloat(this.style.width), height:parseFloat(this.style.height) };
      },
    };
    if (animate) marker.animate = (keyframes, options) => {
      const animation = { keyframes, options, canceled:false, cancel() { this.canceled=true; marker.rendered=null; this.oncancel?.(); } };
      animations.push(animation); return animation;
    };
    return marker;
  } };
  const win = { matchMedia:() => media, requestAnimationFrame:callback => frames.push(callback),
    ResizeObserver:class { constructor(callback) { observers.push(callback); } observe(){} },
  };
  const motion = createMotion(win, doc), flush = () => { while (frames.length) frames.shift()(); };
  flush();
  return { group, motion, animations, frames, outer, targets, flush,
    choose(index) { selected=index; group.listeners.change(); group.listeners.click(); },
    reduce() { media.matches=true; mediaListeners.forEach(listener => listener()); flush(); },
    resize() { observers.forEach(callback => callback()); flush(); },
  };
}

const ui = fixture();
assert.equal(ui.group.dataset.motionReady, 'true');assert.equal(ui.animations.length, 0, 'Initial selection is positioned without an unsolicited entrance');
assert.equal(ui.group.marker.style.transform, 'translate3d(204px,4px,0) scaleX(1)');
ui.choose(1);assert.equal(ui.frames.length, 1, 'Change and click coalesce into one measurement frame');ui.flush();
assert.equal(ui.animations.length, 1);assert.equal(ui.animations[0].options.duration, 340);
assert.match(ui.animations[0].keyframes[0].transform, /translate3d\(204px/);assert.equal(ui.animations[0].keyframes.at(-1).transform, 'translate3d(104px,4px,0) scaleX(1)', 'RTL uses physical geometry, not a mirrored index');
ui.group.marker.rendered={ left:251, top:25, width:99, height:46 };
ui.choose(2);ui.flush();
assert.equal(ui.animations[0].canceled, true);assert.match(ui.animations[1].keyframes[0].transform, /translate3d\(150px/, 'Interrupted motion resumes at the currently rendered position');
assert.equal(ui.group.marker.style.transform, 'translate3d(4px,4px,0) scaleX(1)');
ui.reduce();assert.equal(ui.animations[1].canceled, true);ui.choose(0);ui.flush();assert.equal(ui.animations.length, 2, 'Reduced motion also stops JavaScript animation');assert.match(ui.group.marker.style.transform, /204px/);
ui.outer.left=20;ui.resize();assert.equal(ui.animations.length, 2);assert.match(ui.group.marker.style.transform, /204px/, 'Resize repositions without animation or RTL drift');
ui.outer.width=390;ui.resize();assert.ok(Math.abs(parseFloat(ui.group.marker.style.width)-96*390/310)<.01,'An orientation/width change also remeasures the selected cell');assert.equal(ui.animations.length,2);
ui.outer.width=0;ui.resize();assert.equal(ui.group.marker.hidden, true);ui.outer.width=310;ui.resize();assert.equal(ui.group.marker.hidden, false);
const fallback=fixture({ animate:false });fallback.choose(1);fallback.flush();assert.equal(fallback.group.marker.style.width, '94px', 'Selection remains functional without Web Animations');
const still=fixture({ reduced:true });still.choose(2);still.flush();assert.equal(still.animations.length, 0);assert.match(still.group.marker.style.transform, /4px/);
console.log('PASS Motion: RTL and unequal widths, frame coalescing, interrupted clicks, resize/hidden panels, reduced motion and missing animation support');
