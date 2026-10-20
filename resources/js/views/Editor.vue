<script setup>
import { computed, inject, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { get, put, post, starterBlock, newSection, labels, layouts, failure } from '../lib';
import StudioFrame from '../components/StudioFrame.vue';
import ContentPage from '../components/ContentPage.vue';
import Block from '../components/Block.vue';
const props=defineProps({user:{type:Object,required:true}}); const emit=defineEmits(['logout']);
const route=useRoute();const router=useRouter();const {toast}=inject('session');
const page=ref(null);const loading=ref(true);const busy=ref(false);const error=ref('');const preview=ref(false);
const tab=ref('content');const selected=ref({kind:'section',id:null});const drag=ref(null);
const showHistory=ref(false);const revisions=ref([]);const media=ref([]);
const canEdit=computed(()=>['owner','admin','editor'].includes(props.user.role));
const sections=computed(()=>page.value?.document?.sections||[]);
const activeSection=computed(()=>sections.value.find(s=>s.id===selected.value.id)||(selected.value.kind==='section'?sections.value[0]:null));
const activeBlock=computed(()=>sections.value.flatMap(s=>s.blocks).find(b=>b.id===selected.value.id)||null);
const selectionSection=computed(()=>selected.value.kind==='section'?activeSection.value:sections.value.find(s=>s.blocks.some(b=>b.id===selected.value.id)));
const validLayoutNames=Object.entries(layouts);
const icons={heading:'H',text:'≡',image:'▧',button:'↗',quote:'❞',callout:'✦',divider:'—',list:'☷',stat:'%',faq:'?'};
const fields={
  heading:[['text','text','Heading'],['level','select','Heading level',[1,2,3,4]]],
  text:[['text','textarea','Paragraph text']],
  image:[['src','text','Image URL'],['alt','text','Alt text'],['caption','text','Caption']],
  button:[['text','text','Button label'],['url','text','Destination URL']],
  quote:[['text','textarea','Quote'],['by','text','Attribution']],
  callout:[['title','text','Callout title'],['text','textarea','Body text']],
  divider:[],
  list:[['title','text','List title'],['items','textarea','List items (one per line)']],
  stat:[['value','text','Value'],['label','text','Label']],
  faq:[['question','text','Question'],['answer','textarea','Answer']],
};
async function load(){loading.value=true;error.value='';try{page.value=(await get('/studio/pages/'+route.params.id)).page;selected.value={kind:'section',id:page.value.document.sections[0]?.id||null};}catch(e){error.value=failure(e);}finally{loading.value=false;}}
onMounted(load);
function selectSection(s){selected.value={kind:'section',id:s.id};tab.value='content';}
function selectBlock(b){selected.value={kind:'block',id:b.id};tab.value='content';}
function appendSection(layout='stack'){if(!canEdit.value)return;const s=newSection(layout);sections.value.push(s);selectSection(s);}
function appendBlock(type){if(!canEdit.value)return;if(!sections.value.length)appendSection();const s=selectionSection.value||sections.value.at(-1);const b=starterBlock(type);s.blocks.push(b);selectBlock(b);}
function cloneBlock(si,bi){if(!canEdit.value)return;const s=sections.value[si];const old=s.blocks[bi];const b={id:crypto.randomUUID(),type:old.type,props:{...old.props}};s.blocks.splice(bi+1,0,b);selectBlock(b);}
function removeBlock(si,bi){if(!canEdit.value)return;sections.value[si].blocks.splice(bi,1);selectSection(sections.value[si]);}
function removeSection(si){if(!canEdit.value)return;const target=sections.value[si];if(!confirm('Remove this entire section and all its blocks?'))return;sections.value.splice(si,1);selected.value={kind:'section',id:sections.value[0]?.id||null};}
function moveSection(from,to){if(!canEdit.value||to<0||to>=sections.value.length||from===to)return;const [m]=sections.value.splice(from,1);sections.value.splice(to,0,m);}
function moveBlock(si,bi,toSi,toBi){if(!canEdit.value||toSi<0||toSi>=sections.value.length)return;const from=sections.value[si]?.blocks;
  if(!from||bi<0||bi>=from.length)return;
  const [b]=from.splice(bi,1);
  let targetIndex=toBi;
  if(si===toSi&&bi<targetIndex)targetIndex--;
  const target=sections.value[toSi].blocks;
  target.splice(Math.max(0,Math.min(targetIndex,target.length)),0,b);
  selectBlock(b);
}
function setDrag(e,type,si,bi){if(!canEdit.value)return;drag.value={type,si,bi};e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain','fastfind');}
function dropOnSection(e,to){e.preventDefault();if(!drag.value)return;
  if(drag.value.type==='section')moveSection(drag.value.si,to);
  else moveBlock(drag.value.si,drag.value.bi,to,sections.value[to].blocks.length);
  drag.value=null;
}
function dropOnBlock(e,si,bi){e.preventDefault();e.stopPropagation();if(drag.value?.type==='block')moveBlock(drag.value.si,drag.value.bi,si,bi);drag.value=null;}
function endDrag(){drag.value=null;}
function moveKeyboardBlock(si,bi,delta){const target=bi+delta;if(target<0||target>=sections.value[si].blocks.length)return;moveBlock(si,bi,si,target+(delta>0?1:0));}
async function save(){if(!canEdit.value||busy.value)return false;busy.value=true;error.value='';try{const result=await put('/studio/pages/'+page.value.id,{
  version:page.value.version,title:page.value.title,slug:page.value.slug,summary:page.value.summary,
  seo_title:page.value.seo_title,seo_description:page.value.seo_description,
  show_in_search:!!page.value.show_in_search,document:page.value.document,
});page.value=result.page;toast('Draft saved successfully.');return true;}catch(e){error.value=failure(e);toast(error.value);return false;}finally{busy.value=false;}}
async function stateChange(action){if(busy.value||!canEdit.value)return;const saved=await save();if(!saved)return;busy.value=true;try{page.value=(await post('/studio/pages/'+page.value.id+'/'+action,{version:page.value.version})).page;toast(action==='publish'?'Your page is live!':'Page unpublished.');}catch(e){toast(failure(e));}finally{busy.value=false;}}
async function history(){try{revisions.value=(await get('/studio/pages/'+page.value.id+'/revisions')).revisions;showHistory.value=true;}catch(e){toast(failure(e));}}
async function restoreVersion(v){if(!confirm('Restore revision '+v+' as a new draft version?'))return;try{page.value=(await post('/studio/pages/'+page.value.id+'/restore',{revision_version:v,version:page.value.version})).page;showHistory.value=false;toast('Revision restored. Save or publish when ready.');}catch(e){toast(failure(e));}}
async function openMedia(){try{media.value=(await get('/studio/media')).media;tab.value='media';}catch(e){toast(failure(e));}}
function chooseImage(asset){if(activeBlock.value?.type==='image'){activeBlock.value.props.src=asset.url;activeBlock.value.props.alt=asset.alt||'';tab.value='content';toast('Image selected.');}else{const b=starterBlock('image');b.props.src=asset.url;b.props.alt=asset.alt||'';const s=selectionSection.value||sections.value[0];if(s){s.blocks.push(b);selectBlock(b);toast('Image added.');}}}
</script>
<template>
<StudioFrame :user="user" page-title="Page builder" subtitle="Create it. Arrange it. Make it yours." @logout="emit('logout')">
  <template #toolbar><RouterLink class="btn outline small" to="/studio/pages">← All pages</RouterLink></template>
  <div v-if="loading" class="admin-loading">Opening the canvas…</div>
  <div v-else-if="error && !page" class="alert error">{{error}}</div>
  <div v-else-if="page" class="editor-wrap">
    <div class="editor-top"><div><span class="eyebrow">PAGE / {{page.status.toUpperCase()}}</span><div class="editor-title-line"><input v-model="page.title" :disabled="!canEdit" maxlength="180" class="editor-title" aria-label="Page title"/><span :class="['state-pill',page.status]">{{page.status}}</span></div><span class="editor-path">fastfind.com/p/{{page.slug}}</span></div><div class="editor-actions"><button class="btn outline small" @click="history">◷ History</button><button class="btn outline small" @click="preview=!preview">{{preview?'↙ Edit':'◉ Preview'}}</button><button class="btn outline small" :disabled="busy||!canEdit" @click="save">{{busy?'Saving…':'Save draft'}}</button><button class="btn primary small" :disabled="busy||!canEdit" @click="stateChange(page.status==='published'?'unpublish':'publish')">{{page.status==='published'?'Unpublish':'Publish'}} ↗</button></div></div>
    <div v-if="error" class="alert error">{{error}}</div>
    <div v-if="preview" class="live-preview"><div class="preview-ribbon"><span>◉ LIVE PREVIEW</span><span>Only visible in the editor until published</span></div><div class="preview-content"><header><span class="eyebrow">FASTFIND / PREVIEW</span><h1>{{page.title}}</h1><p>{{page.summary}}</p></header><ContentPage :document="page.document"/></div></div>
    <div v-else class="builder-grid">
      <aside class="builder-palette"><div class="builder-aside-header"><span class="eyebrow">CREATIVE TOOLKIT</span><h3>Add a block</h3><p>Build something unique, one piece at a time.</p></div><div class="palette-grid"><button v-for="(label,type) in labels" :key="type" :disabled="!canEdit" class="palette-item" @click="appendBlock(type)"><span>{{icons[type]}}</span><small>{{label}}</small></button></div><div class="palette-bottom"><span class="eyebrow">SECTION LAYOUTS</span><button v-for="(label,type) in layouts" :key="type" class="layout-choice" :disabled="!canEdit" @click="appendSection(type)"><span class="layout-mini" :class="'mini-'+type">▦</span>{{label}} <b>+</b></button></div></aside>
      <div class="builder-center"><div class="canvas-top"><div><span class="eyebrow">PAGE CANVAS</span><strong>Arrange your story</strong></div><span class="canvas-hint">⠿ Drag to reorder · Click to edit</span></div>
        <div class="builder-canvas"><div v-if="!sections.length" class="empty-canvas"><span>✳</span><h2>A blank canvas. Endless possibilities.</h2><p>Add your first section to begin.</p><button class="btn primary" @click="appendSection()">+ Add a section</button></div>
          <div v-for="(s,si) in sections" :key="s.id" class="canvas-section" :class="{'is-selected':selected.kind==='section'&&selected.id===s.id}">
            <div class="section-toolbar" @click="selectSection(s)" @dragover.prevent @drop="dropOnSection($event,si)"><span class="drag-handle" :draggable="canEdit" @dragstart="setDrag($event,'section',si)" @dragend="endDrag" title="Drag section">⠿</span><span class="section-label">SECTION {{si+1}}</span><span class="section-layout">{{layouts[s.layout]||s.layout}}</span><span class="section-spacer"></span><button class="tiny-control" title="Move section up" :disabled="!canEdit||si===0" @click.stop="moveSection(si,si-1)">↑</button><button class="tiny-control" title="Move section down" :disabled="!canEdit||si===sections.length-1" @click.stop="moveSection(si,si+1)">↓</button><button class="tiny-control danger-ink" title="Delete section" :disabled="!canEdit" @click.stop="removeSection(si)">×</button></div>
            <div class="canvas-section-body" :class="['layout-'+s.layout,'section-bg-'+s.settings.background]" @dragover.prevent @drop="dropOnSection($event,si)"><div v-for="(b,bi) in s.blocks" :key="b.id" class="canvas-block" :class="{'selected-block':selected.kind==='block'&&selected.id===b.id}" @click.stop="selectBlock(b)" @dragover.prevent @drop="dropOnBlock($event,si,bi)"><div class="canvas-block-tools"><span class="block-drag" :draggable="canEdit" @dragstart="setDrag($event,'block',si,bi)" @dragend="endDrag" title="Drag block">⠿</span><small>{{labels[b.type]}}</small><div class="spacer"></div><button title="Move block up" :disabled="!canEdit||bi===0" @click.stop="moveKeyboardBlock(si,bi,-1)">↑</button><button title="Move block down" :disabled="!canEdit||bi===s.blocks.length-1" @click.stop="moveKeyboardBlock(si,bi,1)">↓</button><button title="Duplicate block" :disabled="!canEdit" @click.stop="cloneBlock(si,bi)">⧉</button><button title="Delete block" :disabled="!canEdit" @click.stop="removeBlock(si,bi)">×</button></div><Block :block="b"/></div><button v-if="!s.blocks.length" class="empty-section" @click="selectSection(s)">↳ Empty section. Choose a block on the left.</button></div>
            <div class="section-end" @dragover.prevent @drop="dropOnSection($event,si)"><span>Drop a block here to move it into this section</span><button @click="selectSection(s);appendBlock('text')" :disabled="!canEdit">+ Add text</button></div>
          </div><button class="add-section-line" :disabled="!canEdit" @click="appendSection()"><span>+</span> Add another section</button></div>
      </div>
      <aside class="builder-properties"><div class="inspector-tabs"><button :class="{selected:tab==='content'}" @click="tab='content'">Properties</button><button :class="{selected:tab==='seo'}" @click="tab='seo'">Page & SEO</button><button :class="{selected:tab==='media'}" @click="openMedia">Images</button></div>
        <div v-if="tab==='content'" class="inspector-body"><template v-if="selected.kind==='block'&&activeBlock"><span class="eyebrow">BLOCK INSPECTOR</span><h3>{{labels[activeBlock.type]}}</h3><p class="inspector-sub">Edit the details for your selected block.</p><div class="stack-field"><label v-for="field in fields[activeBlock.type]" :key="field[0]">{{field[2]}}<select v-if="field[1]==='select'" v-model.number="activeBlock.props[field[0]]" :disabled="!canEdit"><option v-for="opt in field[3]" :key="opt" :value="opt">{{'Heading '+opt}}</option></select><textarea v-else-if="field[1]==='textarea'" v-model="activeBlock.props[field[0]]" rows="5" :disabled="!canEdit"/><input v-else v-model="activeBlock.props[field[0]]" :disabled="!canEdit"/></label></div><button v-if="activeBlock.type==='image'" class="btn outline wide" @click="openMedia">Browse image library →</button><div class="inspector-tip">✦ Content is automatically escaped, helping keep your pages safe.</div></template>
          <template v-else-if="activeSection"><span class="eyebrow">SECTION INSPECTOR</span><h3>Section settings</h3><p class="inspector-sub">Fine-tune how this section feels and flows.</p><div class="stack-field"><label>Layout<select v-model="activeSection.layout" :disabled="!canEdit"><option v-for="(label,value) in layouts" :key="value" :value="value">{{label}}</option></select></label><label>Background<select v-model="activeSection.settings.background" :disabled="!canEdit"><option value="plain">Plain white</option><option value="soft">Soft neutral</option><option value="dark">Deep navy</option><option value="accent">Citrus accent</option></select></label><label>Content width<select v-model="activeSection.settings.width" :disabled="!canEdit"><option value="normal">Comfortable</option><option value="wide">Wide canvas</option></select></label></div><div class="inspector-tip">✳ Select a block on the canvas to edit its contents. Use the drag handles to rearrange sections.</div></template>
          <div v-else class="inspector-tip">Select a section or block to see editing options.</div>
        </div>
        <div v-if="tab==='seo'" class="inspector-body"><span class="eyebrow">PAGE DETAILS</span><h3>Make it findable.</h3><div class="stack-field"><label>URL slug<input v-model="page.slug" :disabled="!canEdit"/><small>Lowercase letters, digits, and hyphens.</small></label><label>Page summary<textarea rows="3" v-model="page.summary" :disabled="!canEdit" maxlength="500"/></label><label>SEO title<input v-model="page.seo_title" maxlength="180" :disabled="!canEdit"/></label><label>SEO description<textarea rows="3" v-model="page.seo_description" maxlength="320" :disabled="!canEdit"/></label><label class="checkline"><input type="checkbox" v-model="page.show_in_search" :disabled="!canEdit"/> Include in search results</label></div></div>
        <div v-if="tab==='media'" class="inspector-body"><span class="eyebrow">VISUAL LIBRARY</span><h3>Pick an image.</h3><p class="inspector-sub">Select an image to insert or replace.</p><div class="media-choices"><button v-for="m in media" :key="m.id" title="Choose image" @click="chooseImage(m)"><img :src="m.url" :alt="m.alt"/><small>{{m.filename}}</small></button></div><p v-if="!media.length" class="inspector-tip">Upload images in the Media library first.</p><RouterLink to="/studio/media" class="text-link">Go to media library ↗</RouterLink></div>
      </aside>
    </div>
    <div v-if="showHistory" class="modal-backdrop" @click.self="showHistory=false"><div class="history-modal" role="dialog" aria-modal="true" aria-label="Revision history"><div class="panel-heading"><div><span class="eyebrow">TIME MACHINE</span><h2>Revision history</h2></div><button class="icon-button" @click="showHistory=false">×</button></div><p>Restoring creates a new revision without destroying the timeline.</p><div v-for="r in revisions" :key="r.id" class="revision-row"><span>Version {{r.version}}</span><small>{{new Date(r.created_at).toLocaleString()}}</small><button class="btn outline small" :disabled="!canEdit" @click="restoreVersion(r.version)">Restore</button></div><p v-if="!revisions.length">No revisions saved yet.</p></div></div>
  </div>
</StudioFrame>
</template>
