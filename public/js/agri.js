/* Kivu AgriSmart — module console (MVP). Uses /api/agri/*, scoped by account. */
'use strict';
const Agri = { farms: [], fields: [] };

document.addEventListener('DOMContentLoaded', async function () {
  await boot('agri');
  initTabs($('#agriTabs').parentElement);
  $('#agriRefresh').onclick = () => loadAll();
  $('#agriNewFarm').onclick = () => farmForm();
  $('#agriNewField').onclick = () => fieldForm();
  $('#agriNewCycle').onclick = () => cycleForm();
  $('#agriNewAnimal').onclick = () => animalForm();
  $('#agriNewInv').onclick = () => invForm();
  $('#agriNewYield').onclick = () => yieldForm();
  $('#agriTabs').addEventListener('click', e => { const t=e.target.closest('.tab'); if(t){ if(t.dataset.pane==='agriFarms')loadFarms(); if(t.dataset.pane==='agriFields')loadFields(); if(t.dataset.pane==='agriCycles')loadCycles(); if(t.dataset.pane==='agriLivestock')loadLivestock(); if(t.dataset.pane==='agriInventory')loadInventory(); if(t.dataset.pane==='agriYields')loadYields(); }});
  loadAll();
});

async function loadAll() { await Promise.all([loadSummary(), loadFarms(), loadFields(), loadCycles(), loadLivestock(), loadInventory(), loadYields()]); }

async function loadSummary() {
  try {
    const d = await API.get('/api/agri/summary');
    const s = d.summary || {};
    tiles($('#agriSummary'), [
      { label: 'Farms', value: String(s.farms||0), color: 'green' },
      { label: 'Fields', value: String(s.fields||0), color: 'teal' },
      { label: 'Crop cycles', value: String(s.crop_cycles||0), color: 'blue' },
      { label: 'Livestock', value: String(s.livestock||0), color: 'orange' },
      { label: 'Inventory items', value: String(s.inventory_items||0), color: 'violet' },
      { label: 'Total yield', value: fmt.num(s.total_yield_qty||0, 1), color: 'amber' }
    ]);
  } catch (e) { $('#agriSummary').innerHTML = '<p class="muted">Agri data unavailable: ' + h(e.message) + '</p>'; }
}

function actBtns(kind, row, editFn) {
  return '<button class="btn xs" data-a="e" data-k="'+kind+'" data-id="'+h(row.id)+'">Edit</button> '
       + '<button class="btn xs danger" data-a="d" data-k="'+kind+'" data-id="'+h(row.id)+'">Delete</button>';
}
function bindActs(root, editMap) {
  $$( 'button[data-a]', root).forEach(b => b.onclick = async () => {
    if (b.dataset.a === 'e') editMap[b.dataset.k](b.dataset.id);
    else { if(!confirm('Delete this record?')) return; await API.del('/api/agri/'+b.dataset.k+'/'+b.dataset.id); toast('Deleted','ok'); loadAll(); }
  });
}

async function loadFarms() {
  const d = await API.get('/api/agri/farms'); Agri.farms = d.farms||[];
  const box=$('#agriFarmsTable'); renderTable(box,{ columns:[{key:'name',label:'Farm'},{key:'location',label:'Location'},{key:'size_ha',label:'Size (ha)',type:'number'},{key:'owner_name',label:'Owner'},{key:'actions',label:'',type:'html'}],
    rows: Agri.farms.map(r=>Object.assign({},r,{actions:actBtns('farms',r)}))});
  bindActs(box,{farms:id=>farmForm(Agri.farms.find(x=>x.id===id))});
}
function farmForm(r){ r=r||{}; formModal({ title:r.id?'Edit farm':'New farm', fields:[
  {name:'name',label:'Farm name',value:r.name,required:true},{name:'location',label:'Location',value:r.location},
  {name:'size_ha',label:'Size (ha)',type:'number',step:'0.01',value:r.size_ha},{name:'owner_name',label:'Owner',value:r.owner_name},
  {name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/farms/'+r.id:'/api/agri/farms',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }

async function loadFields() {
  const d = await API.get('/api/agri/fields'); Agri.fields = d.fields||[];
  const box=$('#agriFieldsTable'); renderTable(box,{ columns:[{key:'name',label:'Field'},{key:'farm_name',label:'Farm'},{key:'crop',label:'Crop'},{key:'soil_type',label:'Soil'},{key:'area_ha',label:'Area (ha)',type:'number'},{key:'actions',label:'',type:'html'}],
    rows: Agri.fields.map(r=>Object.assign({},r,{actions:actBtns('fields',r)}))});
  bindActs(box,{fields:id=>fieldForm(Agri.fields.find(x=>x.id===id))});
}
function farmOpts(sel){ return [{value:'',label:'Select farm'}].concat(Agri.farms.map(f=>({value:f.id,label:f.name}))); }
function fieldForm(r){ r=r||{}; formModal({ title:r.id?'Edit field':'New field', fields:[
  {name:'farm_id',label:'Farm',type:'select',options:farmOpts(),value:r.farm_id,required:true},{name:'name',label:'Field name',value:r.name,required:true},
  {name:'crop',label:'Crop',value:r.crop},{name:'soil_type',label:'Soil type',value:r.soil_type},
  {name:'area_ha',label:'Area (ha)',type:'number',step:'0.01',value:r.area_ha},{name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/fields/'+r.id:'/api/agri/fields',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }

async function loadCycles() {
  const d = await API.get('/api/agri/cycles'); Agri.cycles=d.cycles||[];
  const box=$('#agriCyclesTable'); renderTable(box,{ columns:[{key:'crop',label:'Crop'},{key:'field_name',label:'Field'},{key:'status',label:'Status',type:'html'},{key:'planted_at',label:'Planted'},{key:'expected_harvest',label:'Expected harvest'},{key:'yield_qty',label:'Yield',type:'number'},{key:'actions',label:'',type:'html'}],
    rows: Agri.cycles.map(r=>Object.assign({},r,{status:'<span class="chip '+(r.status==='harvested'?'ok':r.status==='growing'?'info':r.status==='failed'?'danger':'warn')+'">'+h(r.status)+'</span>',actions:actBtns('cycles',r)}))});
  bindActs(box,{cycles:id=>cycleForm(Agri.cycles.find(x=>x.id===id))});
}
function cycleForm(r){ r=r||{}; formModal({ title:r.id?'Edit crop cycle':'New crop cycle', wide:true, fields:[
  {name:'field_id',label:'Field',type:'select',options:[{value:'',label:'Select field'}].concat(Agri.fields.map(f=>({value:f.id,label:f.name+(f.farm_name?' ('+f.farm_name+')':'')}))),value:r.field_id,required:true},
  {name:'crop',label:'Crop',value:r.crop,required:true},{name:'variety',label:'Variety',value:r.variety},
  {name:'status',label:'Status',type:'select',options:['planned','growing','harvested','failed'].map(s=>({value:s,label:s})),value:r.status||'planned'},
  {name:'planted_at',label:'Planted date',type:'date',value:r.planted_at},{name:'expected_harvest',label:'Expected harvest',type:'date',value:r.expected_harvest},
  {name:'harvested_at',label:'Harvested date',type:'date',value:r.harvested_at},{name:'yield_qty',label:'Yield quantity',type:'number',step:'0.01',value:r.yield_qty},
  {name:'yield_unit',label:'Yield unit',value:r.yield_unit||'kg'},{name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/cycles/'+r.id:'/api/agri/cycles',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }

async function loadLivestock() {
  const d=await API.get('/api/agri/livestock'); Agri.ls=d.livestock||[];
  const box=$('#agriLivestockTable'); renderTable(box,{ columns:[{key:'tag',label:'Tag/ID'},{key:'species',label:'Species'},{key:'breed',label:'Breed'},{key:'farm_name',label:'Farm'},{key:'status',label:'Status',type:'html'},{key:'weight_kg',label:'Weight (kg)',type:'number'},{key:'actions',label:'',type:'html'}],
    rows: Agri.ls.map(r=>Object.assign({},r,{status:'<span class="chip '+(r.status==='active'?'ok':'warn')+'">'+h(r.status)+'</span>',actions:actBtns('livestock',r)}))});
  bindActs(box,{livestock:id=>animalForm(Agri.ls.find(x=>x.id===id))});
}
function animalForm(r){ r=r||{}; formModal({ title:r.id?'Edit livestock':'New livestock', wide:true, fields:[
  {name:'farm_id',label:'Farm',type:'select',options:farmOpts(),value:r.farm_id,required:true},{name:'tag',label:'Tag/ID',value:r.tag},
  {name:'species',label:'Species',value:r.species,required:true},{name:'breed',label:'Breed',value:r.breed},
  {name:'sex',label:'Sex',type:'select',options:[{value:'',label:''},{value:'female',label:'female'},{value:'male',label:'male'}],value:r.sex},
  {name:'birth_date',label:'Birth date',type:'date',value:r.birth_date},{name:'weight_kg',label:'Weight (kg)',type:'number',step:'0.01',value:r.weight_kg},
  {name:'status',label:'Status',type:'select',options:['active','sold','deceased'].map(s=>({value:s,label:s})),value:r.status||'active'},
  {name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/livestock/'+r.id:'/api/agri/livestock',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }

async function loadInventory() {
  const d=await API.get('/api/agri/inventory'); Agri.inv=d.inventory||[];
  const box=$('#agriInventoryTable'); renderTable(box,{ columns:[{key:'item_name',label:'Item'},{key:'category',label:'Category'},{key:'farm_name',label:'Farm'},{key:'quantity',label:'Qty',type:'number'},{key:'unit',label:'Unit'},{key:'reorder_level',label:'Reorder at',type:'number'},{key:'actions',label:'',type:'html'}],
    rows: Agri.inv.map(r=>Object.assign({},r,{actions:actBtns('inventory',r)}))});
  bindActs(box,{inventory:id=>invForm(Agri.inv.find(x=>x.id===id))});
}
function invForm(r){ r=r||{}; formModal({ title:r.id?'Edit inventory item':'New inventory item', fields:[
  {name:'farm_id',label:'Farm (optional)',type:'select',options:farmOpts(),value:r.farm_id},{name:'item_name',label:'Item name',value:r.item_name,required:true},
  {name:'category',label:'Category',type:'select',options:['seed','fertilizer','feed','chemical','equipment','other'].map(s=>({value:s,label:s})),value:r.category||'other'},
  {name:'quantity',label:'Quantity',type:'number',step:'0.01',value:r.quantity||0},{name:'unit',label:'Unit',value:r.unit||'units'},
  {name:'reorder_level',label:'Reorder level',type:'number',step:'0.01',value:r.reorder_level},{name:'supplier',label:'Supplier',value:r.supplier},
  {name:'cost',label:'Cost',type:'number',step:'0.01',value:r.cost},{name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/inventory/'+r.id:'/api/agri/inventory',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }

async function loadYields() {
  const d=await API.get('/api/agri/yields'); Agri.yields=d.yields||[];
  const box=$('#agriYieldsTable'); renderTable(box,{ columns:[{key:'product',label:'Product'},{key:'farm_name',label:'Farm'},{key:'quantity',label:'Quantity',type:'number'},{key:'unit',label:'Unit'},{key:'harvest_date',label:'Harvest date'},{key:'revenue',label:'Revenue',type:'money'},{key:'actions',label:'',type:'html'}],
    rows: Agri.yields.map(r=>Object.assign({},r,{actions:actBtns('yields',r)}))});
  bindActs(box,{yields:id=>yieldForm(Agri.yields.find(x=>x.id===id))});
}
function yieldForm(r){ r=r||{}; formModal({ title:r.id?'Edit yield record':'New yield record', fields:[
  {name:'farm_id',label:'Farm',type:'select',options:farmOpts(),value:r.farm_id},{name:'product',label:'Product',value:r.product,required:true},
  {name:'quantity',label:'Quantity',type:'number',step:'0.01',value:r.quantity||0},{name:'unit',label:'Unit',value:r.unit||'kg'},
  {name:'harvest_date',label:'Harvest date',type:'date',value:r.harvest_date},{name:'revenue',label:'Revenue',type:'number',step:'0.01',value:r.revenue},
  {name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/agri/yields/'+r.id:'/api/agri/yields',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadAll(); } }); }
