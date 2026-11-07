/* =====================================================================
   KIVU Tracker — public/js/cms.js
   Website content manager (/cms).

   Super administrator, desktop browser only. The Android app never sees it:
   common.js hides the navigation link, this script stops before it asks for
   anything, and the server refuses /cms and /api/cms/* for the app's user
   agent (php/routes_cms.php → cms_is_mobile_app()).
   ===================================================================== */
'use strict';

const CMS = {
  pages: [],        /* page list (for the current account + filters) */
  page: null,       /* page being edited */
  blocks: [],
  kinds: {},
  kindOrder: [],
  pageTypes: {},    /* type code -> label */
  typeOrder: [],
  assetTypes: {},   /* asset code -> {label, glyph} */
  accounts: [],     /* super admin only */
  scope: '',        /* '' = platform site, 'all' = every account, else account id */
  canPlatform: false,
  loaded: false     /* the pickers have been filled once */
};

document.addEventListener('DOMContentLoaded', async function () {
  if (isMobileApp()) {
    cmsBlocked('The content manager is not available in the mobile app.');
    return;
  }
  const meta = await boot('cms');
  if (!meta) return;
  if (!State.user || State.user.role !== 'super') {
    cmsBlocked('Website content is managed by the platform super administrator.');
    return;
  }

  $('#btnNewPage').addEventListener('click', cmsNewPage);
  $('#btnSeed').addEventListener('click', cmsSeed);
  $('#btnExport').addEventListener('click', cmsExport);
  $('#btnImport').addEventListener('click', cmsImport);
  $('#btnSavePage').addEventListener('click', cmsSavePage);
  $('#btnPreview').addEventListener('click', cmsPreview);
  $('#btnDeletePage').addEventListener('click', cmsDeletePage);
  $('#btnAddBlock').addEventListener('click', cmsAddBlock);
  $('#scopeAccount').addEventListener('change', () => {
    CMS.scope = $('#scopeAccount').value;
    CMS.page = null;
    CMS.blocks = [];
    cmsRenderForm();
    cmsRenderBlocks();
    cmsLoadPages();
  });
  $('#scopeType').addEventListener('change', cmsLoadPages);
  $('#scopeAsset').addEventListener('change', cmsLoadPages);

  try {
    await cmsLoadPages();
  } catch (e) {
    cmsLog('Could not load the page list: ' + e.message, 'error');
  }
});

/* ------------------------------------------------------------- helpers -- */
function cmsLog(message, type) {
  const box = $('#cmsLog');
  if (!box) return;
  box.innerHTML = '<p class="' + (type === 'error' ? 'err' : 'hint') + '">' + h(message) + '</p>';
  if (type !== 'error') setTimeout(() => { if (box.textContent === message) box.innerHTML = ''; }, 4000);
}
function cmsBlocked(message) {
  const wrap = $('#cmsWrap');
  const box = el('div', { class: 'cms-blocked card pad' },
    '<h2>Not available here</h2><p class="small">' + h(message) + '</p>'
    + '<p><a class="btn primary" href="/app">Open the monitoring console</a></p>');
  if (wrap) wrap.replaceWith(box); else document.body.appendChild(box);
}

/* ------------------------------------------------------------ page list -- */
async function cmsLoadPages() {
  const q = { account_id: CMS.scope };
  const type = $('#scopeType') ? $('#scopeType').value : '';
  const asset = $('#scopeAsset') ? $('#scopeAsset').value : '';
  if (type) q.type = type;
  if (asset) q.asset_type = asset;
  const data = await API.get('/api/cms/pages' + qs(q));

  CMS.pages = data.pages || [];
  CMS.kinds = data.kinds || {};
  CMS.kindOrder = data.kinds_order || Object.keys(CMS.kinds);
  CMS.pageTypes = data.page_types || {};
  CMS.typeOrder = data.page_types_order || Object.keys(CMS.pageTypes);
  CMS.assetTypes = data.asset_types || {};
  CMS.accounts = data.accounts || [];
  CMS.canPlatform = !!data.can_platform;
  if (data.scope !== undefined) CMS.scope = data.scope;
  $('#pageCount').textContent = CMS.pages.length;

  /* fill the pickers once (accounts, page types, asset types) */
  if (!CMS.loaded) {
    const acc = $('#scopeAccount');
    acc.innerHTML = '';
    if (CMS.canPlatform) {
      acc.appendChild(el('option', { value: '' }, 'Platform website'));
      acc.appendChild(el('option', { value: 'all' }, 'All accounts (overview)'));
      CMS.accounts.forEach(a => acc.appendChild(el('option', { value: a.id },
        a.name + '  (/p/' + a.slug + ')')));
      acc.value = CMS.scope || '';
    } else {
      acc.appendChild(el('option', { value: CMS.scope }, 'My account'));
      acc.disabled = true;
    }
    const tp = $('#scopeType');
    tp.appendChild(el('option', { value: '' }, 'All page types'));
    CMS.typeOrder.forEach(k => tp.appendChild(el('option', { value: k }, CMS.pageTypes[k] || k)));
    const as = $('#scopeAsset');
    as.appendChild(el('option', { value: '' }, 'All asset types'));
    as.appendChild(el('option', { value: 'any' }, 'Any / not set'));
    Object.keys(CMS.assetTypes).forEach(k => as.appendChild(el('option', { value: k },
      (CMS.assetTypes[k].glyph ? CMS.assetTypes[k].glyph + ' ' : '') + (CMS.assetTypes[k].label || k))));
    const bk = $('#newBlockKind');
    if (bk && !bk.options.length) {
      CMS.kindOrder.forEach(k => bk.appendChild(el('option', { value: k }, CMS.kinds[k] || k)));
    }
    CMS.loaded = true;
  }

  const list = $('#pageList');
  list.innerHTML = '';
  if (!CMS.pages.length) {
    list.appendChild(el('p', { class: 'hint' },
      'No pages here yet — use “Load starter page” to create a landing/help/terms set, or “+ New page”.'));
  }
  CMS.pages.forEach(p => {
    const btn = el('button', {
      class: 'cms-page-item' + (CMS.page && CMS.page.id === p.id ? ' active' : ''), type: 'button'
    });
    const who = p.account_name ? p.account_name : 'Platform';
    btn.innerHTML = '<span class="t">' + h(p.title) + '</span><span class="s">'
      + h(who) + ' · ' + h(CMS.pageTypes[p.type] || p.type)
      + (p.asset_type ? ' · ' + h(p.asset_type) : '')
      + ' · ' + h(p.url) + ' · ' + h(p.status)
      + (Number(p.is_home) ? ' · front page' : '')
      + (Number(p.show_in_nav) ? ' · in menu' : '')
      + (p.editable ? '' : ' · read-only')
      + ' · ' + h(String(p.blocks)) + ' block(s)</span>';
    btn.addEventListener('click', () => cmsOpenPage(p.id));
    list.appendChild(btn);
  });
}

async function cmsOpenPage(id) {
  try {
    const data = await API.get('/api/cms/page/' + id);
    CMS.page = data.page;
    CMS.blocks = data.blocks || [];
    cmsRenderForm();
    cmsRenderBlocks();
    cmsLoadPages();          /* refresh the highlight */
  } catch (e) {
    cmsLog('Could not open that page: ' + e.message, 'error');
  }
}

function cmsNewPage() {
  CMS.page = {
    id: '', slug: '', title: '', status: 'draft', type: 'custom', asset_type: '',
    account_id: CMS.scope === 'all' ? '' : CMS.scope,
    is_home: 0, show_in_nav: 0, meta_desc: '', sort_order: 100
  };
  CMS.blocks = [];
  cmsRenderForm();
  cmsRenderBlocks();
  cmsLog('New page — fill in the title and address, then save.', 'info');
}

/* ----------------------------------------------------------- page form -- */
function cmsRenderForm() {
  const pane = $('#pageForm');
  pane.innerHTML = '';
  if (!CMS.page) {
    pane.appendChild(el('p', { class: 'hint' }, 'Pick a page on the left, or create a new one.'));
    $('#btnSavePage').disabled = true;
    $('#btnPreview').disabled = true;
    $('#btnDeletePage').disabled = true;
    return;
  }
  $('#btnSavePage').disabled = false;
  $('#btnPreview').disabled = false;
  $('#btnDeletePage').disabled = !CMS.page.id;

  const p = CMS.page;
  const grid = el('div', { style: 'display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px' });

  const field = (label, node, hint) => {
    const box = el('div');
    box.appendChild(el('label', { class: 'tiny dim' }, h(label)));
    box.appendChild(node);
    if (hint) box.appendChild(el('p', { class: 'hint', style: 'margin:4px 0 0' }, h(hint)));
    return box;
  };
  const input = (value, placeholder) => el('input', { type: 'text', value: value || '', placeholder: placeholder || '' });

  const title = input(p.title, 'Page title');
  const slug = input(p.slug, 'page-address');
  title.addEventListener('input', () => { p.title = title.value; });
  slug.addEventListener('input', () => { p.slug = slug.value; });
  grid.appendChild(field('Title', title, 'Shown in the browser tab and in the page menu.'));
  grid.appendChild(field('Address', slug, 'Public URL: /p/<address> — lowercase letters, numbers, dashes.'));

  const status = el('select');
  status.appendChild(el('option', { value: 'draft' }, 'Draft (not public)'));
  status.appendChild(el('option', { value: 'published' }, 'Published'));
  status.value = p.status === 'published' ? 'published' : 'draft';
  status.addEventListener('change', () => { p.status = status.value; });
  grid.appendChild(field('Status', status, 'Only published pages are visible to visitors.'));

  /* page type: landing / help / terms / faq / custom */
  const type = el('select');
  CMS.typeOrder.forEach(k => type.appendChild(el('option', { value: k }, CMS.pageTypes[k] || k)));
  type.value = p.type || 'custom';
  type.addEventListener('change', () => { p.type = type.value; });
  grid.appendChild(field('Type', type, 'An account\'s help/terms pages are linked in its console.'));

  /* optional asset type (lorry, motorcycle, …) */
  const asset = el('select');
  asset.appendChild(el('option', { value: '' }, 'Any asset type'));
  Object.keys(CMS.assetTypes).forEach(k => asset.appendChild(el('option', { value: k },
    (CMS.assetTypes[k].glyph ? CMS.assetTypes[k].glyph + ' ' : '') + (CMS.assetTypes[k].label || k))));
  asset.value = p.asset_type || '';
  asset.addEventListener('change', () => { p.asset_type = asset.value; });
  grid.appendChild(field('Asset type', asset, 'Optional: use it for content about one vehicle type.'));

  const order = el('input', { type: 'number', value: String(p.sort_order || 100), min: '0', max: '9999' });
  order.addEventListener('input', () => { p.sort_order = Number(order.value) || 100; });
  grid.appendChild(field('Menu order', order, 'Lower numbers come first.'));

  const meta = el('textarea', { style: 'min-height:52px' });
  meta.value = p.meta_desc || '';
  meta.addEventListener('input', () => { p.meta_desc = meta.value; });
  grid.appendChild(field('Meta description', meta, 'Search-engine summary (max 300 characters).'));

  const flags = el('div', { class: 'row wrap', style: 'gap:14px;margin-top:10px' });
  const mk = (label, checked, onChange) => {
    const l = el('label', { class: 'row', style: 'gap:6px;align-items:center' });
    const cb = el('input', { type: 'checkbox' });
    cb.checked = !!checked;
    cb.addEventListener('change', () => onChange(cb.checked));
    l.appendChild(cb);
    l.appendChild(el('span', null, h(label)));
    return l;
  };
  flags.appendChild(mk('Show in the page menu', Number(p.show_in_nav) === 1, v => { p.show_in_nav = v ? 1 : 0; }));
  /* the front page exists only for the platform site */
  if (CMS.canPlatform && !p.account_id) {
    flags.appendChild(mk('Front page (render at /)', Number(p.is_home) === 1, v => { p.is_home = v ? 1 : 0; }));
  }
  grid.appendChild(flags);

  pane.appendChild(grid);
  if (p.updated_at) {
    pane.appendChild(el('p', { class: 'hint', style: 'margin:8px 0 0' },
      'Last change ' + h(fmt.dt(p.updated_at)) + (p.updated_by ? ' by ' + h(p.updated_by) : '')));
  }
}

async function cmsSavePage() {
  if (!CMS.page) return;
  const body = {
    title: CMS.page.title, slug: CMS.page.slug, status: CMS.page.status,
    type: CMS.page.type || 'custom', asset_type: CMS.page.asset_type || '',
    is_home: Number(CMS.page.is_home) === 1, show_in_nav: Number(CMS.page.show_in_nav) === 1,
    meta_desc: CMS.page.meta_desc, sort_order: Number(CMS.page.sort_order) || 100
  };
  /* a new page goes into the account currently selected (super admin) */
  if (!CMS.page.id && CMS.canPlatform) body.account_id = CMS.scope === 'all' ? '' : CMS.scope;
  try {
    const data = CMS.page.id
      ? await API.put('/api/cms/page/' + CMS.page.id, body)
      : await API.post('/api/cms/pages', body);
    CMS.page = data.page;
    CMS.blocks = data.blocks || CMS.blocks;
    cmsRenderForm();
    cmsRenderBlocks();
    await cmsLoadPages();
    cmsLog(data.message || 'Page saved');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

function cmsPreview() {
  if (!CMS.page || !CMS.page.slug) { cmsLog('Save the page first — it needs an address.', 'error'); return; }
  window.open('/p/' + CMS.page.slug, '_blank', 'noopener');
}

async function cmsDeletePage() {
  if (!CMS.page || !CMS.page.id) return;
  const ok = await confirmDialog('Delete “' + CMS.page.title + '” and all of its blocks? This cannot be undone.',
    'Delete page');
  if (!ok) return;
  try {
    const data = await API.del('/api/cms/page/' + CMS.page.id);
    CMS.page = null;
    CMS.blocks = [];
    cmsRenderForm();
    cmsRenderBlocks();
    await cmsLoadPages();
    cmsLog(data.message || 'Page deleted');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}


/* --------------------------------------------------------------- blocks -- */
function cmsRenderBlocks() {
  const box = $('#blockList');
  box.innerHTML = '';
  if (!CMS.page) return;
  if (!CMS.blocks.length) {
    box.appendChild(el('p', { class: 'hint' }, 'No blocks yet — pick a type below and add one.'));
    return;
  }
  CMS.blocks.forEach((b, i) => box.appendChild(cmsBlockCard(b, i)));
}

function cmsBlockCard(b, index) {
  const card = el('div', { class: 'cms-block' + (Number(b.enabled) ? '' : ' off') });
  const head = el('div', { class: 'cms-block-head' });
  const label = el('span', { class: 'kind' }, h((index + 1) + '. ' + (CMS.kinds[b.kind] || b.kind)));
  head.appendChild(label);

  const kind = el('select', { style: 'width:auto;max-width:200px' });
  CMS.kindOrder.forEach(k => kind.appendChild(el('option', { value: k }, CMS.kinds[k] || k)));
  kind.value = b.kind;
  kind.addEventListener('change', () => {
    b.kind = kind.value;
    label.textContent = (index + 1) + '. ' + (CMS.kinds[kind.value] || kind.value);
  });
  head.appendChild(kind);
  head.appendChild(el('span', { style: 'flex:1' }));

  const mk = (text, cls, fn) => {
    const btn = el('button', { class: 'btn xs ' + (cls || ''), type: 'button' }, h(text));
    btn.addEventListener('click', fn);
    return btn;
  };
  head.appendChild(mk('Save', 'primary', () => cmsSaveBlock(b)));
  head.appendChild(mk('↑', '', () => cmsMoveBlock(index, -1)));
  head.appendChild(mk('↓', '', () => cmsMoveBlock(index, 1)));
  head.appendChild(mk('Delete', 'danger', () => cmsDeleteBlock(b)));
  card.appendChild(head);

  const field = (labelText, value, onChange, multiline, placeholder) => {
    const f = el('div', { class: 'f' });
    f.appendChild(el('label', null, h(labelText)));
    const node = multiline ? el('textarea', { placeholder: placeholder || '' })
      : el('input', { type: 'text', placeholder: placeholder || '' });
    node.value = value || '';
    node.addEventListener('input', () => onChange(node.value));
    f.appendChild(node);
    return f;
  };
  card.appendChild(field('Title', b.title, v => { b.title = v; }, false,
    b.kind === 'button' ? 'Button label' : 'Heading / card title'));
  card.appendChild(field('Text', b.body, v => { b.body = v; }, true,
    b.kind === 'code' ? 'Commands or code' : 'The content of this block'));
  if (b.kind === 'button' || b.kind === 'image') {
    card.appendChild(field(b.kind === 'image' ? 'Image URL' : 'Link', b.url, v => { b.url = v; }, false,
      b.kind === 'image' ? 'https://… or /img/…' : 'https://… , /register , mailto:…'));
  }

  const flags = el('div', { class: 'row', style: 'gap:6px;align-items:center;margin-top:6px' });
  const cb = el('input', { type: 'checkbox' });
  cb.checked = Number(b.enabled) === 1;
  cb.addEventListener('change', () => { b.enabled = cb.checked ? 1 : 0; });
  flags.appendChild(cb);
  flags.appendChild(el('span', { class: 'hint' }, 'Visible on the published page'));
  card.appendChild(flags);
  return card;
}

async function cmsSaveBlock(b) {
  const body = { kind: b.kind, title: b.title, body: b.body, url: b.url, enabled: Number(b.enabled) === 1 };
  try {
    const data = b.id
      ? await API.put('/api/cms/block/' + b.id, body)
      : await API.post('/api/cms/page/' + CMS.page.id + '/blocks', body);
    if (data.blocks) CMS.blocks = data.blocks;
    cmsRenderBlocks();
    cmsLog(data.message || 'Block saved');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

async function cmsDeleteBlock(b) {
  if (!b.id) return;
  const ok = await confirmDialog('Delete this ' + String(CMS.kinds[b.kind] || b.kind).toLowerCase() + ' block?',
    'Delete block');
  if (!ok) return;
  try {
    const data = await API.del('/api/cms/block/' + b.id);
    if (data.blocks) CMS.blocks = data.blocks;
    cmsRenderBlocks();
    cmsLog(data.message || 'Block deleted');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

async function cmsMoveBlock(index, delta) {
  const target = index + delta;
  if (target < 0 || target >= CMS.blocks.length) return;
  CMS.blocks.splice(target, 0, CMS.blocks.splice(index, 1)[0]);
  cmsRenderBlocks();
  try {
    const data = await API.post('/api/cms/page/' + CMS.page.id + '/reorder',
      { ids: CMS.blocks.map(x => x.id) });
    if (data.blocks) CMS.blocks = data.blocks;
    cmsRenderBlocks();
    cmsLog(data.message || 'Order saved');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}


/* New blocks start with usable sample content, so "Add" always succeeds. */
async function cmsAddBlock() {
  if (!CMS.page || !CMS.page.id) { cmsLog('Save the page first.', 'error'); return; }
  const kind = $('#newBlockKind').value;
  const seeds = {
    heading: { title: 'Section heading', body: '' },
    text: { title: '', body: 'Write something here.' },
    feature: { title: 'Feature name', body: 'What this feature does for the customer.' },
    button: { title: 'Learn more', body: '', url: '/register' },
    list: { title: '', body: 'First point\nSecond point\nThird point' },
    image: { title: 'Illustration', body: '', url: (State.meta && State.meta.logo) || '' },
    quote: { title: 'Customer name', body: 'A quote from a happy customer.' },
    code: { title: '', body: 'php install.php --fresh' },
    divider: { title: '', body: '' },
    html: { title: '', body: '<p>Custom markup</p>' }
  };
  const seed = seeds[kind] || { title: '', body: 'New block' };
  try {
    const data = await API.post('/api/cms/page/' + CMS.page.id + '/blocks',
      { kind: kind, title: seed.title || '', body: seed.body || '', url: seed.url || '', enabled: true });
    if (data.blocks) CMS.blocks = data.blocks;
    cmsRenderBlocks();
    cmsLog(data.message || 'Block added');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

/* ------------------------------------------- starter, export and import -- */
async function cmsSeed() {
  const target = CMS.scope === '' ? 'the platform website'
    : (CMS.scope === 'all' ? 'the selected account' : 'this account');
  const ok = await confirmDialog('Create starter pages for ' + target
    + ' (landing + help + terms)? This only works while that site has no pages yet.', 'Create starter pages');
  if (!ok) return;
  try {
    const data = await API.post('/api/cms/seed', { account_id: CMS.scope === 'all' ? '' : CMS.scope });
    await cmsLoadPages();
    if (data.page_id) await cmsOpenPage(data.page_id);
    cmsLog(data.message || 'Starter pages created');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

async function cmsExport() {
  try {
    const data = await API.get('/api/cms/export');
    const blob = new Blob([JSON.stringify(data.export, null, 2)], { type: 'application/json' });
    const link = el('a', {
      href: URL.createObjectURL(blob),
      download: 'kivu-cms-' + new Date().toISOString().slice(0, 10) + '.json'
    });
    document.body.appendChild(link);
    link.click();
    link.remove();
    cmsLog('Exported ' + data.count + ' page(s) as JSON');
  } catch (e) {
    cmsLog(e.message, 'error');
  }
}

function cmsImport() {
  const picker = el('input', { type: 'file', accept: 'application/json,.json' });
  picker.addEventListener('change', async () => {
    const file = picker.files && picker.files[0];
    if (!file) return;
    try {
      const payload = JSON.parse(await file.text());
      const data = await API.post('/api/cms/import', { export: payload });
      await cmsLoadPages();
      cmsLog(data.message || 'Imported');
    } catch (e) {
      cmsLog('Import failed: ' + e.message, 'error');
    }
  });
  picker.click();
}
