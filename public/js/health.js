/* Kivu HealthConnect — module console (MVP). Sensitive: RBAC + server audit. */
'use strict';
const HC = { patients: [] };

document.addEventListener('DOMContentLoaded', async function () {
  await boot('health');
  initTabs($('#hcTabs').parentElement);
  $('#hcRefresh').onclick = loadAll;
  $('#hcNewPatient').onclick = () => patientForm();
  $('#hcNewAppt').onclick = () => apptForm();
  $('#hcNewVital').onclick = () => vitalForm();
  $('#hcNewPlan').onclick = () => planForm();
  $('#hcNewMsg').onclick = () => msgForm();
  $('#hcSearch').addEventListener('input', debounce(loadPatients, 250));
  $('#hcVitalsPatient').onchange = loadVitals;
  $('#hcMsgPatient').onchange = loadMsgs;
  $('#hcTabs').addEventListener('click', e => { const t=e.target.closest('.tab'); if(!t) return;
    if(t.dataset.pane==='hcPatients')loadPatients(); if(t.dataset.pane==='hcAppts')loadAppts();
    if(t.dataset.pane==='hcVitals')loadVitals(); if(t.dataset.pane==='hcPlans')loadPlans(); if(t.dataset.pane==='hcMsgs')loadMsgs(); });
  loadAll();
});

async function loadAll(){ await Promise.all([loadSummary(), loadPatients(), loadAppts(), loadPlans()]); loadVitals(); loadMsgs(); }
function pOpts(sel){ const o=[{value:'',label:'Select patient'}].concat(HC.patients.map(p=>({value:p.id,label:p.full_name+(p.mrn?' ('+p.mrn+')':'')}))); return o; }
function fillPatientSelects(){ [$('#hcVitalsPatient'),$('#hcMsgPatient')].forEach(s=>{ if(!s) return; const v=s.value; s.innerHTML=''; pOpts().forEach(o=>s.appendChild(el('option',{value:o.value},o.label))); if(v) s.value=v; }); }

async function loadSummary(){
  try{ const d=await API.get('/api/health/summary'); const s=d.summary||{};
    tiles($('#hcSummary'),[{label:'Patients',value:String(s.patients||0),color:'teal'},{label:'Appointments',value:String(s.appointments||0),color:'blue'},{label:'Upcoming',value:String(s.upcoming||0),color:'green'},{label:'Care plans',value:String(s.care_plans||0),color:'violet'}]);
  }catch(e){ $('#hcSummary').innerHTML='<p class="muted">Health data unavailable: '+h(e.message)+'</p>'; }
}

async function loadPatients(){
  const q=$('#hcSearch').value||''; const d=await API.get('/api/health/patients'+qs({q})); HC.patients=d.patients||[]; fillPatientSelects();
  const box=$('#hcPatientsTable'); renderTable(box,{ columns:[{key:'full_name',label:'Patient'},{key:'mrn',label:'MRN'},{key:'dob',label:'DOB'},{key:'phone',label:'Phone'},{key:'status',label:'Status',type:'html'},{key:'consent_at',label:'Consent',type:'datetime'},{key:'actions',label:'',type:'html'}],
    rows: HC.patients.map(r=>Object.assign({},r,{status:'<span class="chip '+(r.status==='active'?'ok':'warn')+'">'+h(r.status)+'</span>',actions:'<button class="btn xs" data-e="'+h(r.id)+'">Edit</button> '+(State.user&&State.user.role!=='viewer'?'<button class="btn xs danger" data-d="'+h(r.id)+'">Archive</button>':'')}))});
  $$( 'button[data-e]',box).forEach(b=>b.onclick=()=>patientForm(HC.patients.find(x=>x.id===b.dataset.e)));
  $$( 'button[data-d]',box).forEach(b=>b.onclick=async()=>{ if(!confirm('Archive this patient record?'))return; await API.del('/api/health/patients/'+b.dataset.d); toast('Archived','ok'); loadAll(); });
}
function patientForm(r){ r=r||{}; formModal({ title:r.id?'Edit patient':'New patient', wide:true, fields:[
  {name:'full_name',label:'Full name',value:r.full_name,required:true},{name:'mrn',label:'MRN',value:r.mrn},
  {name:'dob',label:'Date of birth',type:'date',value:r.dob},{name:'sex',label:'Sex',type:'select',options:[{value:'',label:''},{value:'female',label:'female'},{value:'male',label:'male'},{value:'other',label:'other'}],value:r.sex},
  {name:'phone',label:'Phone',value:r.phone},{name:'email',label:'Email',type:'email',value:r.email},
  {name:'address',label:'Address',value:r.address},{name:'emergency_contact',label:'Emergency contact',value:r.emergency_contact},
  {name:'status',label:'Status',type:'select',options:['active','inactive','archived'].map(s=>({value:s,label:s})),value:r.status||'active'},
  {name:'consent_at',label:'Consent recorded at',type:'datetime-local',value:r.consent_at?r.consent_at.replace(' ','T').slice(0,16):''},
  {name:'notes',label:'Clinical notes (sensitive)',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/health/patients/'+r.id:'/api/health/patients',Object.assign({id:r.id||''},v,{consent_at:v.consent_at?v.consent_at.replace('T',' ')+':00':null})); toast('Saved','ok'); loadAll(); } }); }

async function loadAppts(){
  const d=await API.get('/api/health/appointments'); HC.appts=d.appointments||[];
  renderTable($('#hcApptsTable'),{ columns:[{key:'scheduled_at',label:'When',type:'datetime'},{key:'patient_name',label:'Patient'},{key:'kind',label:'Type'},{key:'channel',label:'Channel'},{key:'clinician',label:'Clinician'},{key:'status',label:'Status',type:'html'},{key:'actions',label:'',type:'html'}],
    rows: HC.appts.map(r=>Object.assign({},r,{status:'<span class="chip '+(r.status==='completed'?'ok':r.status==='canceled'?'danger':'info')+'">'+h(r.status)+'</span>',actions:'<button class="btn xs" data-e="'+h(r.id)+'">Edit</button> <button class="btn xs danger" data-d="'+h(r.id)+'">Delete</button>'}))});
  const box=$('#hcApptsTable');
  $$( 'button[data-e]',box).forEach(b=>b.onclick=()=>apptForm(HC.appts.find(x=>x.id===b.dataset.e)));
  $$( 'button[data-d]',box).forEach(b=>b.onclick=async()=>{ if(!confirm('Delete appointment?'))return; await API.del('/api/health/appointments/'+b.dataset.d); toast('Deleted','ok'); loadAppts(); });
}
function apptForm(r){ r=r||{}; formModal({ title:r.id?'Edit appointment':'New appointment', wide:true, fields:[
  {name:'patient_id',label:'Patient',type:'select',options:pOpts(),value:r.patient_id,required:true},{name:'scheduled_at',label:'Scheduled at',type:'datetime-local',value:r.scheduled_at?r.scheduled_at.replace(' ','T').slice(0,16):'',required:true},
  {name:'kind',label:'Type',type:'select',options:['consult','followup','tele-nursing','home-visit'].map(s=>({value:s,label:s})),value:r.kind||'consult'},
  {name:'channel',label:'Channel',type:'select',options:['video','phone','in_person'].map(s=>({value:s,label:s})),value:r.channel||'video'},
  {name:'duration_min',label:'Duration (min)',type:'number',value:r.duration_min||30},{name:'clinician',label:'Clinician',value:r.clinician},
  {name:'status',label:'Status',type:'select',options:['scheduled','completed','canceled','no_show'].map(s=>({value:s,label:s})),value:r.status||'scheduled'},
  {name:'reason',label:'Reason',value:r.reason},{name:'notes',label:'Notes',type:'textarea',value:r.notes}
], onSave: async v => { await API.post(r.id?'/api/health/appointments/'+r.id:'/api/health/appointments',Object.assign({id:r.id||''},v,{scheduled_at:v.scheduled_at.replace('T',' ')+':00'})); toast('Saved','ok'); loadAll(); } }); }

async function loadVitals(){
  const pid=$('#hcVitalsPatient').value; if(!pid){ $('#hcVitalsTable').innerHTML='<p class="hint">Select a patient to view vitals.</p>'; return; }
  const d=await API.get('/api/health/vitals'+qs({patient_id:pid}));
  renderTable($('#hcVitalsTable'),{ columns:[{key:'recorded_at',label:'Recorded',type:'datetime'},{key:'recorded_by',label:'By'},{key:'temp_c',label:'Temp °C',type:'number'},{key:'pulse_bpm',label:'Pulse',type:'number'},{key:'bp_systolic',label:'BP sys',type:'number'},{key:'bp_diastolic',label:'BP dia',type:'number'},{key:'spo2',label:'SpO2',type:'number'},{key:'glucose_mmol',label:'Glucose',type:'number'},{key:'actions',label:'',type:'html'}],
    rows:(d.vitals||[]).map(r=>Object.assign({},r,{actions:'<button class="btn xs danger" data-d="'+h(r.id)+'">Delete</button>'}))});
  $$( 'button[data-d]',$('#hcVitalsTable')).forEach(b=>b.onclick=async()=>{ if(!confirm('Delete vitals record?'))return; await API.del('/api/health/vitals/'+b.dataset.d); toast('Deleted','ok'); loadVitals(); });
}
function vitalForm(){ const pid=$('#hcVitalsPatient').value; formModal({ title:'Record vitals', wide:true, fields:[
  {name:'patient_id',label:'Patient',type:'select',options:pOpts(),value:pid,required:true},{name:'recorded_at',label:'Recorded at',type:'datetime-local',value:isoLocal(new Date())},
  {name:'temp_c',label:'Temp (°C)',type:'number',step:'0.1'},{name:'pulse_bpm',label:'Pulse (bpm)',type:'number'},{name:'resp_rate',label:'Resp rate',type:'number'},
  {name:'bp_systolic',label:'BP systolic',type:'number'},{name:'bp_diastolic',label:'BP diastolic',type:'number'},{name:'spo2',label:'SpO2 (%)',type:'number'},
  {name:'weight_kg',label:'Weight (kg)',type:'number',step:'0.01'},{name:'glucose_mmol',label:'Glucose (mmol/L)',type:'number',step:'0.01'},{name:'notes',label:'Notes',type:'textarea'}
], onSave: async v => { await API.post('/api/health/vitals',Object.assign({},v,{recorded_at:v.recorded_at.replace('T',' ')+':00'})); toast('Vitals saved','ok'); $('#hcVitalsPatient').value=v.patient_id; loadVitals(); } }); }

async function loadPlans(){
  const d=await API.get('/api/health/care-plans'); HC.plans=d.plans||[];
  renderTable($('#hcPlansTable'),{ columns:[{key:'title',label:'Plan'},{key:'patient_name',label:'Patient'},{key:'status',label:'Status',type:'html'},{key:'start_date',label:'Start'},{key:'review_date',label:'Review'},{key:'actions',label:'',type:'html'}],
    rows: HC.plans.map(r=>Object.assign({},r,{status:'<span class="chip '+(r.status==='active'?'ok':'info')+'">'+h(r.status)+'</span>',actions:'<button class="btn xs" data-e="'+h(r.id)+'">Edit</button> <button class="btn xs danger" data-d="'+h(r.id)+'">Delete</button>'}))});
  const box=$('#hcPlansTable');
  $$( 'button[data-e]',box).forEach(b=>b.onclick=()=>planForm(HC.plans.find(x=>x.id===b.dataset.e)));
  $$( 'button[data-d]',box).forEach(b=>b.onclick=async()=>{ if(!confirm('Delete care plan?'))return; await API.del('/api/health/care-plans/'+b.dataset.d); toast('Deleted','ok'); loadPlans(); });
}
function planForm(r){ r=r||{}; formModal({ title:r.id?'Edit care plan':'New care plan', wide:true, fields:[
  {name:'patient_id',label:'Patient',type:'select',options:pOpts(),value:r.patient_id,required:true},{name:'title',label:'Title',value:r.title,required:true},
  {name:'diagnosis',label:'Diagnosis',value:r.diagnosis},{name:'status',label:'Status',type:'select',options:['active','completed','paused'].map(s=>({value:s,label:s})),value:r.status||'active'},
  {name:'start_date',label:'Start date',type:'date',value:r.start_date},{name:'review_date',label:'Review date',type:'date',value:r.review_date},
  {name:'goals',label:'Goals',type:'textarea',value:r.goals},{name:'interventions',label:'Interventions',type:'textarea',value:r.interventions}
], onSave: async v => { await API.post(r.id?'/api/health/care-plans/'+r.id:'/api/health/care-plans',Object.assign({id:r.id||''},v)); toast('Saved','ok'); loadPlans(); } }); }

async function loadMsgs(){
  const pid=$('#hcMsgPatient').value; if(!pid){ $('#hcMsgsTable').innerHTML='<p class="hint">Select a patient to view secure messages.</p>'; return; }
  const d=await API.get('/api/health/messages'+qs({patient_id:pid}));
  renderTable($('#hcMsgsTable'),{ columns:[{key:'sent_at',label:'Sent',type:'datetime'},{key:'direction',label:'Direction'},{key:'channel',label:'Channel'},{key:'sender',label:'Sender'},{key:'body',label:'Message'}], rows:d.messages||[] });
}
function msgForm(){ const pid=$('#hcMsgPatient').value; formModal({ title:'New message (logged)', fields:[
  {name:'patient_id',label:'Patient',type:'select',options:pOpts(),value:pid,required:true},
  {name:'direction',label:'Direction',type:'select',options:['outbound','inbound','internal'].map(s=>({value:s,label:s})),value:'outbound'},
  {name:'channel',label:'Channel',type:'select',options:['secure','sms','email','note'].map(s=>({value:s,label:s})),value:'secure'},
  {name:'body',label:'Message',type:'textarea',value:'',required:true}
], onSave: async v => { await API.post('/api/health/messages',v); toast('Message logged','ok'); $('#hcMsgPatient').value=v.patient_id; loadMsgs(); } }); }
