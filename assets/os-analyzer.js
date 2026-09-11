(function(){
  'use strict';

  const esc=(v)=>String(v??'').replace(/[&<>'"]/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const isAdmin=()=>Boolean(document.querySelector('a[href*="configuracoes.php"]'));

  async function carregarIntegracoes(){
    const r=await fetch('/api/v1/integrations.php',{credentials:'same-origin'});
    const j=await r.json();
    if(!r.ok||!j.ok)throw new Error(j.erro||'Não foi possível carregar as integrações.');
    return j;
  }

  function campo(label,key,value='',type='text',placeholder=''){
    return `<label class="dnb-os-admin-field"><span>${esc(label)}</span><input type="${type}" data-os-key="${esc(key)}" value="${esc(value)}" placeholder="${esc(placeholder)}"></label>`;
  }
  function segredo(label,key,defined){
    return `<label class="dnb-os-admin-field"><span>${esc(label)} ${defined?'<small>• definido</small>':'<small>• ausente</small>'}</span><input type="password" data-os-key="${esc(key)}" value="" placeholder="${defined?'Deixe em branco para manter':'Informe o segredo'}" autocomplete="new-password"></label>`;
  }
  function toggle(label,key,value){
    return `<label class="dnb-os-admin-toggle"><input type="checkbox" data-os-key="${esc(key)}" ${String(value)==='1'?'checked':''}><span>${esc(label)}</span></label>`;
  }

  async function initAdmin(){
    if(!isAdmin()||!location.pathname.endsWith('/configuracoes.php'))return;
    const panel=document.querySelector('#tab-integracoes');
    if(!panel||document.querySelector('[data-os-analyzer-admin]'))return;
    let state;
    try{state=await carregarIntegracoes();}catch(e){return;}
    const f=state.fields||{},s=state.secrets||{};
    const card=document.createElement('section');
    card.className='bg-white rounded-xl border border-slate-200 shadow-sm mb-4 dnb-os-admin-card';
    card.dataset.osAnalyzerAdmin='1';
    card.innerHTML=`<div class="p-4">
      <div class="dnb-os-admin-head"><div><span class="dnb-eyebrow">Leitura inteligente da OS</span><h2 class="dnb-section-title">n8n · Extrator · Azure · IA visual</h2><p>O CRM publica <b>https://crm.dnbair.com.br/os/analisar</b>. O endereço real da VPS/n8n e os segredos ficam somente no servidor e são configurados aqui pelo ADM.</p></div><span class="dnb-os-admin-status ${state.status?.os_analyzer?'is-on':'is-off'}">${state.status?.os_analyzer?'Ativo':'Não configurado'}</span></div>
      <form data-os-analyzer-config>
        <div class="dnb-os-admin-toggles">${toggle('Ativar leitor inteligente', 'DNB_OS_N8N_ENABLED', f.DNB_OS_N8N_ENABLED)}${toggle('Permitir fallback para leitor PHP local se o n8n falhar', 'DNB_OS_N8N_FALLBACK_LOCAL', f.DNB_OS_N8N_FALLBACK_LOCAL)}${toggle('Usar IA visual como fallback no workflow', 'DNB_OS_AI_VISUAL_ENABLED', f.DNB_OS_AI_VISUAL_ENABLED)}</div>
        <div class="dnb-os-admin-grid">
          ${campo('Webhook de produção do n8n','DNB_OS_N8N_WEBHOOK_URL',f.DNB_OS_N8N_WEBHOOK_URL,'url','https://.../webhook/os-analisar-v1')}
          ${segredo('Bearer token do n8n','DNB_OS_N8N_TOKEN',s.DNB_OS_N8N_TOKEN)}
          ${campo('Timeout do workflow (segundos)','DNB_OS_N8N_TIMEOUT',f.DNB_OS_N8N_TIMEOUT||'120','number')}
          ${campo('Endpoint do Extrator OS na VPS','DNB_OS_EXTRACTOR_URL',f.DNB_OS_EXTRACTOR_URL,'url','https://.../analisar')}
          ${segredo('Token do Extrator OS','DNB_OS_EXTRACTOR_TOKEN',s.DNB_OS_EXTRACTOR_TOKEN)}
          ${campo('Azure Document Intelligence endpoint','DNB_OS_AZURE_ENDPOINT',f.DNB_OS_AZURE_ENDPOINT,'url','https://SEU-RECURSO.cognitiveservices.azure.com')}
          ${segredo('Azure Document Intelligence key','DNB_OS_AZURE_KEY',s.DNB_OS_AZURE_KEY)}
          ${campo('Azure model','DNB_OS_AZURE_MODEL',f.DNB_OS_AZURE_MODEL||'prebuilt-layout')}
          ${campo('Azure API version','DNB_OS_AZURE_API_VERSION',f.DNB_OS_AZURE_API_VERSION||'2024-11-30')}
          ${campo('Endpoint da IA visual','DNB_OS_AI_VISUAL_ENDPOINT',f.DNB_OS_AI_VISUAL_ENDPOINT,'url','https://...')}
          ${segredo('Token da IA visual','DNB_OS_AI_VISUAL_TOKEN',s.DNB_OS_AI_VISUAL_TOKEN)}
          ${campo('Modelo da IA visual','DNB_OS_AI_VISUAL_MODEL',f.DNB_OS_AI_VISUAL_MODEL,'text','modelo configurado no provedor')}
        </div>
        <div class="dnb-os-admin-actions"><button type="submit">Salvar leitura inteligente</button><span data-os-admin-feedback></span></div>
        <p class="dnb-os-admin-note">Segredos em branco preservam o valor atual. O CRM envia as configurações ao n8n somente em comunicação servidor-servidor HTTPS; elas não são expostas ao navegador durante a leitura.</p>
      </form></div>`;
    panel.prepend(card);
    const form=card.querySelector('form'),feedback=card.querySelector('[data-os-admin-feedback]');
    form.addEventListener('submit',async(e)=>{
      e.preventDefault();feedback.textContent='Salvando…';
      const payload={};
      form.querySelectorAll('[data-os-key]').forEach((el)=>{
        const key=el.dataset.osKey;
        if(el.type==='checkbox')payload[key]=el.checked?'1':'0';
        else if(el.type==='password'&&el.value.trim()==='')return;
        else payload[key]=el.value.trim();
      });
      payload.csrf_token=state.csrf;
      try{
        const r=await fetch('/api/v1/integrations.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','X-CSRF-Token':state.csrf},body:JSON.stringify(payload)});
        const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.erro||'Falha ao salvar.');
        feedback.textContent='Configuração salva.';setTimeout(()=>location.reload(),700);
      }catch(err){feedback.textContent=err.message||'Falha ao salvar.';}
    });
  }

  async function initVisitas(){
    if(!location.pathname.endsWith('/visitas.php'))return;
    const action=document.querySelector('form input[name="acao"][value="importar_pdf_preview"]');
    const form=action?.closest('form');
    if(form&&!form.dataset.osSmartBound){
      form.dataset.osSmartBound='1';
      form.addEventListener('submit',async(e)=>{
        e.preventDefault();
        const btn=form.querySelector('button[type="submit"],button:not([type])');
        const original=btn?.textContent||'';if(btn){btn.disabled=true;btn.textContent='Analisando PDF…';}
        try{
          const r=await fetch('/os/analisar',{method:'POST',credentials:'same-origin',body:new FormData(form)});
          const j=await r.json();if(!r.ok||!j.ok)throw new Error(j.erro||'Não foi possível analisar o PDF.');
          location.href=j.redirect||'/visitas.php';
        }catch(err){alert(err.message||'Não foi possível analisar o PDF.');if(btn){btn.disabled=false;btn.textContent=original;}}
      });
    }
    try{
      const r=await fetch('/api/v1/os-preview-status.php',{credentials:'same-origin'}),j=await r.json();
      if(!j.pending)return;
      const editor=document.querySelector('.dnb-os-editor__head');if(!editor||document.querySelector('[data-os-analysis-summary]'))return;
      const a=j.analysis||{},u=Array.isArray(a.unresolved)?a.unresolved:[],empty=Array.isArray(a.empty)?a.empty:[];
      const box=document.createElement('div');box.dataset.osAnalysisSummary='1';box.className='dnb-os-analysis-summary';
      box.innerHTML=`<strong>${j.source==='n8n'?'Leitura inteligente n8n':'Leitura local'}</strong><span>${Number(a.filled||0)} preenchidos · ${u.length} precisam conferir · ${empty.length} vazios informados · ${Number(a.photos_count||0)} foto(s) detectada(s)</span>${j.warning?`<small>${esc(j.warning)}</small>`:''}`;
      editor.appendChild(box);
    }catch(_e){}
  }

  document.addEventListener('DOMContentLoaded',()=>{initAdmin();initVisitas();});
})();
