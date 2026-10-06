import assert from 'node:assert/strict';
import fs from 'node:fs';
import {createCitizenCallStopIntent} from '../../resources/js/features/citizenCallStopIntent.js';
// Exercise the actual render coordinator with network/UI dependencies replaced.
let source = fs.readFileSync(new URL('../../resources/js/surfaces/renderSurface.js',import.meta.url),'utf8');
source=source.replace(/^import .*;$/gm,'').replace('export async function','async function');
source=source.replace("await import('./citizenSurface.js')",'deps');
const guard=createCitizenCallStopIntent();
const root={dataset:{apiBootstrapUrl:'/bootstrap'}};
const requests=[];const rendered=[];
const state={runtime:{}};
const deps={renderCitizenSurface:async (_root,bootstrap)=>{
 rendered.push(guard.reconcile(guard.scope(),bootstrap.surface_payload.current_open_incident));
}};
const coordinator=new Function('deps','citizenCallStopIntent','appState','document','fetchJson','resetSurfaceRuntime','ensureHelperUi','syncBootstrapSessionState','initAccountSessionSdk','openLoginModal',source+';return renderSurface;')(
 deps,guard,state,{getElementById:()=>root},()=>new Promise(resolve=>requests.push(resolve)),()=>{},async()=>{},()=>{},()=>{},async()=>{});
const active={id:43,call_history:[{id:64,status:'in_progress'}]};
const ended={id:43,call_history:[{id:64,status:'ended',outcome:'ended_by_citizen'}]};
const bootstrap=(incident,id=5)=>({authenticated:true,user:{id},surface_payload:{current_open_incident:incident}});
await coordinator('citizen',{bootstrap:bootstrap(active)});
guard.stop(guard.scope(),43,64);
// Fallback re-render keeps the uncertain stop intent despite active server snapshot.
await coordinator('citizen',{bootstrap:bootstrap(active)});
assert.equal(guard.suppress(guard.scope(),43,64),true);
assert.equal(rendered.at(-1).call_history[0].status,'in_progress');
const delayed=coordinator('citizen');
await coordinator('citizen',{bootstrap:bootstrap(ended)});
requests.shift()(bootstrap(active));await delayed;
assert.equal(state.bootstrap.surface_payload.current_open_incident.call_history[0].status,'ended','Older bootstrap must not overwrite newer render');
assert.equal(rendered.at(-1).call_history[0].status,'ended');
await coordinator('citizen',{bootstrap:bootstrap(active)});
assert.equal(rendered.at(-1).call_history[0].status,'ended','Terminal guard also rejects stale payload on a later render');
const oldScope=guard.scope();const oldUserRequest=coordinator('citizen');
await coordinator('citizen',{bootstrap:bootstrap({id:44,call_history:[]},6)});
requests.shift()(bootstrap(active,5));await oldUserRequest;
assert.equal(state.bootstrap.user.id,6);
assert.equal(guard.isCurrent(oldScope),false);
assert.equal(guard.suppress(guard.scope(),43,65),false);
console.log('PASS actual render coordinator: uncertain fallback, response ordering, terminal monotonicity and citizen context changes');
