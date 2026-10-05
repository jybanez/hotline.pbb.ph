export function incidentStatusErrorMessage(error, targetStatus) {
    const message = error?.response?.data?.message;
    const reason = typeof message === 'string' ? message.trim() : '';
    if (targetStatus === 'Resolved'
        && Number(error?.response?.status) === 409
        && reason === 'Resolve is blocked until all team assignments are completed or cancelled.') {
        return `${reason} In Dispatch, complete or cancel the outstanding team assignments, then try resolving the incident again.`;
    }
    return reason || 'Unable to change the incident status. Check the current incident status before trying again.';
}
