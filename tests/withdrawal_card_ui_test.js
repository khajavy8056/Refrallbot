// Run with NODE_PATH pointing at an installation of jsdom.
const assert = require('assert');
const fs = require('fs');
const { JSDOM } = require('jsdom');
(async () => {
  const dom = new JSDOM(`<!doctype html><html><head></head><body><form id="withdraw-form"><input name="amount" value="30000"><input name="card_number" id="withdraw-card"><input name="card_holder" value="علی رضایی"><button id="withdraw-submit">ثبت</button></form><div id="withdraw-card-hint"></div></body></html>`, {url:'https://example.test/app',runScripts:'outside-only'});
  const w = dom.window, calls = [];
  w.RB_MINIAPP = {csrf:'csrf-test'};
  w.fetch = async (url, options) => {
    calls.push({url, options});
    return {status:200, text: async () => JSON.stringify({ok:false,error:'test'})};
  };
  w.eval(fs.readFileSync('public/assets/js/miniapp.js','utf8'));
  const input = w.document.querySelector('#withdraw-card');
  const form = w.document.querySelector('#withdraw-form');
  const tick = () => new Promise(resolve => setImmediate(resolve));
  for (const value of ['123', '6037997512345671', '۱۱۱۱-شماره-۷', 'IR123456', '1'.repeat(300)]) {
    input.value = value;
    input.dispatchEvent(new w.Event('input', {bubbles:true}));
    assert.equal(input.value, value, 'input must not be altered');
    form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true}));
    await tick();
    const request = calls.filter(c => c.url.endsWith('/app/api/withdraw')).pop();
    assert.ok(request, 'form submitted');
    assert.equal(JSON.parse(request.options.body).card_number, value);
  }
  const n = calls.filter(c => c.url.endsWith('/app/api/withdraw')).length;
  input.value = '  ';
  form.dispatchEvent(new w.Event('submit',{bubbles:true,cancelable:true})); await tick();
  assert.equal(calls.filter(c => c.url.endsWith('/app/api/withdraw')).length, n, 'empty input not submitted');
  console.log('Mini app: free-form card is submitted unchanged; empty input is rejected');
  w.close();
})().catch(e => {console.error(e); process.exit(1);});
