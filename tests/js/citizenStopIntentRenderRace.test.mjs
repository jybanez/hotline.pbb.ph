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
const beforePending = guard.scope();
const delayed=coordinator('citizen');
assert.equal(guard.isCurrent(beforePending),false,'Transition start invalidates old callbacks while bootstrap is pending');
assert.equal(guard.reconcile(beforePending,ended),null,'An old home response cannot apply during pending bootstrap');
assert.equal(guard.stop(beforePending,43,65),false,'An old hangup callback cannot mutate another session during pending bootstrap');
await coordinator('citizen',{bootstrap:bootstrap(ended)});
requests.shift()(bootstrap(active));await delayed;
assert.equal(state.bootstrap.surface_payload.current_open_incident.call_history[0].status,'ended','Older bootstrap must not overwrite newer render');
assert.equal(rendered.at(-1).call_history[0].status,'ended');
await coordinator('citizen',{bootstrap:bootstrap(active)});
assert.equal(rendered.at(-1).call_history[0].status,'ended','Terminal guard also rejects stale payload on a later render');
const oldScope=guard.scope();const oldUserRequest=coordinator('citizen');
assert.equal(guard.isCurrent(oldScope),false,'Old citizen callbacks are invalid before new identity is known');
await coordinator('citizen',{bootstrap:bootstrap({id:44,call_history:[]},6)});
requests.shift()(bootstrap(active,5));await oldUserRequest;
assert.equal(state.bootstrap.user.id,6);
assert.equal(guard.isCurrent(oldScope),false);
assert.equal(guard.suppress(guard.scope(),43,65),false);
console.log('PASS actual render coordinator: uncertain fallback, response ordering, terminal monotonicity and citizen context changes');

// Exercise actual logout entry: old scopes must die before the logout request resolves.
const shared = fs.readFileSync(new URL('../../resources/js/surfaces/surfaceShared.js',import.meta.url),'utf8');
const logoutSource = shared.slice(shared.indexOf('async function logoutCurrentUser()'),shared.indexOf('async function openLoginModal('));
let finishLogout;let logoutRequests=0;
const logoutGuard=createCitizenCallStopIntent();const logoutScope=logoutGuard.setCitizen(5);logoutGuard.stop(logoutScope,43,64);
const logoutState={activeSurface:'citizen',bootstrap:{authenticated:true,user:{id:5}},runtime:{callerRealtimeStream:{stopScope:logoutScope}}};
const logout=new Function('citizenCallStopIntent','appState','accountSsoConfig','fetchJson','clearClientSessionState','setCsrfToken','showToast','window',logoutSource+';return logoutCurrentUser;')(logoutGuard,logoutState,()=>({}),()=>{logoutRequests++;return new Promise(resolve=>finishLogout=resolve);},()=>logoutGuard.setCitizen(null),()=>{},()=>{},{location:{assign:()=>{}}});
const signout=logout();
assert.equal(logoutGuard.isCurrent(logoutScope),false,'Logout invalidates context before network completes');
assert.equal(logoutGuard.reconcile(logoutScope,active),null);
assert.equal(logoutGuard.stop(logoutScope,43,65),false);
assert.equal(logoutRequests,1,'No automatic mutation replay');
finishLogout({});await signout;
assert.equal(logoutGuard.scope().citizen,'');
console.log('PASS pending-bootstrap and pending-logout old-context cancellation');

const preLogoutBootstrap=coordinator('citizen');
const preLogoutScope=guard.scope();
guard.beginTransition();
requests.shift()(bootstrap(active,5));await preLogoutBootstrap;
assert.equal(state.bootstrap.user.id,6,'Bootstrap started before logout must not restore the old account');
assert.equal(guard.isCurrent(preLogoutScope),false);
console.log('PASS logout supersedes an already pending bootstrap');
