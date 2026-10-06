import assert from 'node:assert/strict';
import fs from 'node:fs';
import vm from 'node:vm';

// Execute the actual modal orchestration with controlled transport completion.
const source = fs.readFileSync('resources/js/surfaces/operatorSurface.js', 'utf8');
const code = source.slice(source.indexOf('function callbackCitizenIsOnline('), source.indexOf('function workbenchNavbarIcon('));
const tick = () => new Promise(resolve => setImmediate(resolve));
function harness() {
    const events = new EventTarget();
    const frames = [];
    let interval;
    const requests = [];
    const signals = [];
    const alerts = [];
    let online = true;
    let response = async url => url.endsWith('/callback-call') ? {attempt:{id:12}} : {attempt:{id:12,status:'ended',outcome:'cancelled_by_operator'}};
    const element = () => ({classList:{add(){}}, setAttribute(){}, prepend(){}, innerHTML:''});
    let options, modal;
    const helper = {
        createActionModal(opts) {
            options = opts;
            modal = {refs:{busyLayer:element(),busyMessage:element(),busyCancelButton:element()},
                open(){this.opened=true;}, setBusy(busy, config){this.busy=busy;this.config={...this.config,...config};},
                async close(){if(options.onBeforeClose()===false)return;this.closed=true;options.onClose();}, destroy(){this.destroyed=true;}};
            return modal;
        },
        uiAlert:async message => alerts.push(message),
    };
    const context = vm.createContext({
        AbortController, Date, Number, String, console:{info(){},table(){}},
        window:{addEventListener:events.addEventListener.bind(events), removeEventListener:events.removeEventListener.bind(events),
            requestAnimationFrame:fn=>frames.push(fn),setInterval:fn=>{interval=fn;return 1;},clearInterval(){}},
        document:{createElement:element}, escapeHtml:String, createIconMarkup:()=>'<svg/>',
        workbenchCallerName:()=> 'Citizen',workbenchCallerAvatar:()=>'',operatorIncidentCitizenId:()=>5,
        operatorTransferPresenceRuntime:()=>({roster:{}}),operatorDiscoveryPresenceRuntime:()=>({joined:true}),
        listPresenceRosterItems:()=> online ? [{userId:5,meta:{role:'citizen'},state:'online',expiresAt:new Date(Date.now()+60000).toISOString()}] : [],
        OPERATOR_DISCOVERY_PRESENCE_HEARTBEAT_MS:60000,CALL_DISCOVERY_ROOM:'presence.global.hotline',
        appState:{bootstrap:{user:{id:3}},runtime:{operatorRealtimeStream:{client:{isOpen:()=>true}}}},
        fetchJson:(url,opts)=>{requests.push({url,opts});return response(url);},showToast:()=>{},
        publishOperatorCallFlow:(type,payload)=>{signals.push({type,payload});return true;},
        currentOperatorRoot:()=>({}),syncOperatorActiveIncident:()=>{},
        openIncomingCallModal:async()=>{throw Error('Disposed workbench reopened');}, startOperatorAnsweredCallBridge:async()=>{},
    });
    vm.runInContext(code,context);
    const button = {};
    const owner = context.openCallbackAvailabilityModal(helper,{id:31,citizen:{name:'Citizen'}},button);
    const dispatch = (type,userId=5) => {const event=new Event(type);event.detail={payload:{call_attempt_id:12},sender:{user_id:userId}};events.dispatchEvent(event);};
    return {requests,signals,alerts,owner,dispatch,button,watch:()=>interval(),get modal(){return modal;},setOnline:value=>online=value,
        respond:fn=>response=fn,paint:async()=>{while(frames.length)await frames.shift()();await tick();}};
}
{
    const h=harness(); h.setOnline(false); await h.paint();
    assert.equal(h.requests.length,0,'Offline citizens must not create attempts');
    assert.match(h.alerts[0],/not currently reachable/);
}
{
    const h=harness(); assert.equal(h.modal.opened,true); await h.paint();
    h.dispatch('hotline:callback-declined',99); await tick();
    assert.equal(h.modal.closed,undefined,'Another sender cannot decline this call');
    h.dispatch('hotline:callback-declined'); await tick();
    assert.equal(h.requests.at(-1).opts.data.outcome,'declined_by_citizen');
    assert.match(h.alerts[0],/declined/);
}
{
    const h=harness(); await h.paint(); let resolve;
    h.respond(url=>url.endsWith('/answer') ? new Promise(done=>resolve=done) : Promise.resolve({attempt:{id:12,status:'ended',outcome:'cancelled_by_operator'}}));
    h.dispatch('hotline:callback-answer'); await tick(); h.owner.destroy();
    resolve({call_session:{id:44},incident:{id:31}}); await tick();
    assert.equal(h.modal.destroyed,true);
    assert.equal(h.signals.some(item=>item.type==='operator.callback.accepted'),false);
    assert.equal(h.requests.at(-1).url,'/api/operator/call-sessions/44/hangup');
    assert.equal(h.alerts.length,0,'Late committed answer does not reopen UI');
}
{
    const h=harness(); let resolve;
    h.respond(url=>url.endsWith('/callback-call') ? new Promise(done=>resolve=done) : Promise.resolve({attempt:{id:12,status:'ended',outcome:'cancelled_by_operator'}}));
    const painting=h.paint(); await tick(); h.owner.destroy(); resolve({attempt:{id:12}}); await painting;
    assert.equal(h.requests.at(-1).url,'/api/operator/callback-call-attempts/12/cancel');
    assert.equal(h.signals.some(item=>item.type==='operator.callback.request'),false,'Cancelled initialization cannot dial');
}
{
    const h=harness(); await h.paint();
    h.respond(async url=>{if(url.endsWith('/cancel'))throw Error('Lost response');return {attempt:{id:12,status:'ended',outcome:'cancelled_by_operator'}};});
    h.modal.config.cancelBusy.onCancel(); await tick();
    assert.equal(h.modal.closed,undefined,'Uncertain cancellation keeps modal visible');
    assert.equal(h.button.disabled,true,'Uncertain cancellation retains reservation UI');
    assert.equal(h.signals.some(item=>item.type==='operator.callback.cancelled'),false);
    assert.equal(h.alerts.length,0,'No confirmed termination claim on failed cancel');
    h.modal.config.cancelBusy.onCancel(); await tick();
    assert.equal(h.requests.at(-1).opts.method,'get','Reconcile uncertainty without replaying mutation');
    assert.equal(h.modal.closed,true);
    assert.equal(h.button.disabled,false);
    assert.equal(h.requests.filter(item=>item.url.endsWith('/cancel')).length,1);
}
{
    const h=harness();
    h.respond(async url=>url.endsWith('/callback-call') ? {attempt:{id:12},expires_at:new Date(Date.now()-1000).toISOString()}
        : {attempt:{id:12,status:'ended',outcome:'timed_out'}});
    await h.paint(); h.watch(); await tick();
    assert.equal(h.requests.at(-1).opts.method,'get','Deadline is reconciled by server read');
    assert.equal(h.modal.closed,true);
    assert.match(h.alerts[0],/expired/);
}
{
    const h=harness(); await h.paint();
    h.respond(async()=>({attempt:{id:12,status:'ended',outcome:'answered'},call_session:{id:44}}));
    h.modal.config.cancelBusy.onCancel(); await tick();
    assert.equal(h.signals.some(item=>item.type==='operator.callback.cancelled'),false,'An answered session is never reported cancelled');
    assert.match(h.alerts[0],/answered before cancellation/);
    assert.equal(h.button.disabled,true,'Active-call reconciliation cannot enable another callback');
}
console.log('PASS callback lifecycle: offline, trusted decline, disposed answer, late creation cleanup, uncertain cancellation reconciliation');
