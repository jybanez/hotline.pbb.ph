import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';
const source = fs.readFileSync('resources/js/surfaces/operatorSurface.js', 'utf8');
const bridge = source.indexOf('const liftConnectionGate = async', source.indexOf('async function', source.indexOf('const handleWorkbenchPeerState')));
// Select the answer bridge gate, which precedes its deferred-location registration.
const end = source.indexOf('    appState.runtime.operatorDeferredCallerLocationPersist = {', source.indexOf('answer-bridge-ready-api-request'));
const start = source.lastIndexOf('    const liftConnectionGate = async', end);
const snippet = source.slice(start,end);
for (const outcome of ['available','absent','failure']) {
 let settle;
 const location = new Promise((resolve,reject) => {settle = outcome === 'failure' ? reject : resolve;});
 const events=[];
 const workbench={payload:{id:34,caller_location:null}};
 const context=vm.createContext({readiness:{localStream:true,remoteStream:true,completed:false,inFlight:false}, incidentId:34,callSessionId:46,citizenId:5,payload:{id:34}, Date, logCallFlow(){},fetchJson:async()=>({call_session:{answered_at:'now'}}),patchIncidentCallSession:p=>p,callRuntime:{setMediaMuted(){},startSessionKeepalive(){}},appState:{bootstrap:{user:{id:3}},runtime:{operatorWorkbench:workbench}},publishOperatorCallFlow:type=>events.push(type),openConnectedWorkbench:async()=>events.push('workbench'),flushDeferredOperatorCallerLocationPersist:()=>{events.push('location');return location;},syncOperatorActiveIncident(){},currentOperatorRoot:()=>null,updateWorkbenchCallerLocationView:()=>events.push('location-render'),clearDeferredOperatorCallerLocationPersist(){},showToast:()=>events.push('error'),console:{warn:()=>events.push('warning')}});
 vm.runInContext(snippet+'globalThis.lift = liftConnectionGate;',context);
 await context.lift();
 assert.deepEqual(events,['citizen.call.ready','workbench','location']);
 assert.equal(context.readiness.completed,true);
 settle(outcome==='available'?{id:34,caller_location:{latitude:10}}:outcome==='failure'?new Error('location unavailable'):null);
 await new Promise(resolve=>setImmediate(resolve));
 assert.equal(events.includes('error'),false);
 assert.equal(events.includes('location-render'),outcome==='available');
 console.log('PASS answer bridge with '+outcome+' optional location');
}
