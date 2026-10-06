// Browser-local intent survives modal/surface teardown; it never declares a server outcome.
export function createCitizenCallStopIntent() {
    let citizen = '';
    let generation = 0;
    const sessions = new Map();
    const key = (incidentId, sessionId) => `${Number(incidentId)}:${Number(sessionId)}`;
    const valid = (scope) => Boolean(citizen) && scope?.citizen === citizen && scope?.generation === generation;
    return {
        setCitizen(id) {
            const next = String(id ?? '').trim();
            if (next !== citizen) { citizen = next; generation++; sessions.clear(); }
            return this.scope();
        },
        scope() { return {citizen, generation}; },
        isCurrent(scope) { return valid(scope); },
        stop(scope, incidentId, sessionId) {
            if (!valid(scope) || !(Number(incidentId) > 0 && Number(sessionId) > 0)) return false;
            const id = key(incidentId, sessionId);
            sessions.set(id, {...sessions.get(id), stopped: true});
            return true;
        },
        suppress(scope, incidentId, sessionId) {
            if (!valid(scope)) return true;
            const state = sessions.get(key(incidentId, sessionId));
            return Boolean(state?.stopped || state?.terminal);
        },
        uncertain(scope, incidentId, sessionId) {
            if (!valid(scope)) return false;
            const state = sessions.get(key(incidentId, sessionId));
            return Boolean(state?.stopped && !state?.terminal);
        },
        reconcile(scope, incident) {
            if (!valid(scope)) return null;
            if (!incident) return incident;
            const update = (session) => {
                if (!session?.id) return session;
                const id = key(incident.id, session.id);
                const previous = sessions.get(id);
                // Terminal snapshots are monotonic. A delayed active snapshot cannot revive them.
                if (session.status === 'ended') {
                    const terminal = {status: session.status, outcome: session.outcome, ended_at: session.ended_at, updated_at: session.updated_at};
                    const known = Object.fromEntries(Object.entries(terminal).filter(([,value]) => value !== undefined && value !== null));
                    sessions.set(id, {...previous, terminal: {...previous?.terminal, ...known}});
                }
                const terminal = sessions.get(id)?.terminal;
                return terminal ? {...session, ...terminal} : session;
            };
            return {...incident,
                call_history: Array.isArray(incident.call_history) ? incident.call_history.map(update) : incident.call_history,
                ...(incident.current_call_session ? {current_call_session: update(incident.current_call_session)} : {}),
            };
        },
    };
}

export const citizenCallStopIntent = createCitizenCallStopIntent();
