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
    const requests = [];
    const signals = [];
    const alerts = [];
    let online = true;
    let response = async url => url.endsWith('/callback-call') ? {attempt:{id:12}} : {ok:true};
    const element = () => ({classList:{add(){}}, setAttribute(){}, prepend(){}, innerHTML:''});
    let options, modal;
    const helper = {
        createActionModal(opts) {
            options = opts;
            modal = {refs:{busyLayer:element(),busyMessage:element(),busyCancelButton:element()},
                open(){this.opened=true;}, setBusy(busy, config){this.busy=busy;this.config=config;},
                async close(){if(options.onBeforeClose()===false)return;this.closed=true;options.onClose();}, destroy(){this.destroyed=true;}};
            return modal;
        },
        uiAlert:async message => alerts.push(message),
    };
    const context = vm.createContext({
        AbortController, Date, Number, String, console:{info(){},table(){}},
        window:{addEventListener:events.addEventListener.bind(events), removeEventListener:events.removeEventListener.bind(events),
            requestAnimationFrame:fn=>frames.push(fn),setInterval:()=>1,clearInterval(){}},
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
    return {requests,signals,alerts,owner,dispatch,get modal(){return modal;},setOnline:value=>online=value,
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
    assert.equal(h.requests.at(-1).opts.body.outcome,'declined_by_citizen');
    assert.match(h.alerts[0],/declined/);
}
{
    const h=harness(); await h.paint(); let resolve;
    h.respond(url=>url.endsWith('/answer') ? new Promise(done=>resolve=done) : Promise.resolve({ok:true}));
    h.dispatch('hotline:callback-answer'); await tick(); h.owner.destroy();
    resolve({call_session:{id:44},incident:{id:31}}); await tick();
    assert.equal(h.modal.destroyed,true);
    assert.equal(h.signals.some(item=>item.type==='operator.callback.accepted'),false);
    assert.equal(h.requests.at(-1).url,'/api/operator/call-sessions/44/hangup');
    assert.equal(h.alerts.length,0,'Late committed answer does not reopen UI');
}
{
    const h=harness(); let resolve;
    h.respond(url=>url.endsWith('/callback-call') ? new Promise(done=>resolve=done) : Promise.resolve({ok:true}));
    const painting=h.paint(); await tick(); h.owner.destroy(); resolve({attempt:{id:12}}); await painting;
    assert.equal(h.requests.at(-1).url,'/api/operator/callback-call-attempts/12/cancel');
    assert.equal(h.signals.some(item=>item.type==='operator.callback.request'),false,'Cancelled initialization cannot dial');
}
console.log('PASS callback lifecycle: offline, trusted decline, disposed answer, late creation cleanup');
