import assert from 'node:assert/strict';
import {createCitizenCallStopIntent} from '../../resources/js/features/citizenCallStopIntent.js';
const guard = createCitizenCallStopIntent();
const scope = guard.setCitizen(5);
const active = {id:43, call_history:[{id:64,status:'in_progress'}],current_call_session:{id:64,status:'in_progress'}};
assert.equal(guard.suppress(scope,43,64),false);
assert.equal(guard.stop(scope,43,64),true);
// Modal destruction and a fresh render for the same authenticated citizen retain intent.
assert.deepEqual(guard.setCitizen(5),scope);
const fallback = guard.reconcile(scope,structuredClone(active));
assert.equal(fallback.call_history[0].status,'in_progress','Unconfirmed outcome must not be fabricated');
assert.equal(guard.uncertain(scope,43,64),true);
assert.equal(guard.suppress(scope,43,64),true,'Delayed active bootstrap cannot remount after fallback');
const ended = {id:43,call_history:[{id:64,status:'ended',outcome:'ended_by_citizen',ended_at:'2026-10-06T16:56:22Z'}]};
guard.reconcile(scope,ended);
assert.equal(guard.uncertain(scope,43,64),false);
for(let i=0;i<3;i++) {
 const stale=guard.reconcile(scope,structuredClone(active));
 assert.equal(stale.call_history[0].status,'ended');
 assert.equal(stale.current_call_session.status,'ended');
 assert.equal(stale.call_history[0].ended_at,ended.call_history[0].ended_at);
 assert.equal(guard.suppress(scope,43,64),true,'Duplicate ready/media renders cannot resurrect terminal session');
}
assert.equal(guard.suppress(scope,43,65),false,'Legitimate distinct session remains possible');
assert.equal(guard.suppress(scope,44,64),false,'Intent is scoped to incident/session');
const other=guard.setCitizen(6);
assert.equal(guard.stop(scope,43,65),false,'Late old-context callback must not record new intent');
assert.equal(guard.reconcile(scope,active),null,'Late old-context snapshot is rejected');
assert.equal(guard.suppress(scope,43,65),true);
assert.equal(guard.suppress(other,43,64),false,'Citizen contexts do not inherit another user intent');
guard.setCitizen(null);
assert.equal(guard.isCurrent(other),false,'Logout invalidates outstanding callbacks');
assert.equal(guard.reconcile(other,ended),null);
const newLogin=guard.setCitizen(5);
assert.notDeepEqual(newLogin,scope,'Logout/login creates a new context even for same user');
console.log('PASS citizen stop intent: fallback, stale terminal snapshots, duplicate events, distinct sessions and context cancellation');
