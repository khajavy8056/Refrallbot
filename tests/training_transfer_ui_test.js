// Run with NODE_PATH pointing at an installation of jsdom.
const assert = require('assert');
const fs = require('fs');
const { JSDOM } = require('jsdom');
const html = `<meta name="csrf-token" content="test-token"><div data-training-transfer data-start="/admin/training/download/start" data-status="/admin/training/download/status" data-video="/admin/training/video" data-download="/admin/training/video?download=1"><p data-transfer-message></p><progress data-transfer-progress max="100" value="0"></progress><button data-transfer-retry hidden></button></div><section data-training-window data-source="drive" data-local="0"></section><a data-training-download href="#"></a>`;
(async () => {
  const dom = new JSDOM(html, { url: 'https://example.test/admin/update', runScripts: 'outside-only' });
  const w = dom.window, tasks = [], calls = [];
  w.setTimeout = fn => { tasks.push(fn); return tasks.length; };
  w.clearTimeout = () => {};
  let statusCalls = 0;
  w.fetch = async (url, options) => {
    calls.push([url, options]);
    const data = url.endsWith('/start') ? {ok:true,state:'done'} :
      ++statusCalls === 1 ? {ok:true,state:'idle',bytes:0,total:200} :
      statusCalls === 2 ? {ok:true,state:'running',bytes:100,total:200} : {ok:true,state:'done',bytes:200,total:200};
    return {status:200, headers:{get:()=> 'application/json'}, text:async()=>JSON.stringify(data)};
  };
  w.eval(fs.readFileSync('public/assets/js/app.js', 'utf8'));
  const tick = () => new Promise(resolve => setImmediate(resolve));
  await tick(); await tick();
  assert.equal(calls[0][0], '/admin/training/download/status');
  assert.equal(calls[1][0], '/admin/training/download/start');
  assert.equal(calls[1][1].headers['X-CSRF-Token'], 'test-token');
  tasks.shift()(); await tick(); await tick();
  assert.equal(w.document.querySelector('progress').value, 50);
  tasks.shift()(); await tick(); await tick();
  assert.equal(w.document.querySelector('progress').value, 100);
  assert.equal(w.document.querySelector('[data-training-window]').dataset.local, '1');
  assert.equal(w.document.querySelector('[data-training-window]').dataset.source, '/admin/training/video');
  assert.equal(w.document.querySelector('[data-training-download]').getAttribute('href'), '/admin/training/video?download=1');
  console.log('Transfer UI: automatic start, CSRF, polling, progress and local source passed');
  w.close();
})().catch(err => { console.error(err); process.exit(1); });
