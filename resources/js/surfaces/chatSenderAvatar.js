export function resolveChatSenderAvatar(payload, participants = []) {
    const senderId = String(payload?.sender?.user_id ?? '').trim();
    if (!senderId) return '';

    const participant = participants.find((item) => String(item?.id ?? '').trim() === senderId);
    return String(participant?.avatar ?? payload?.sender?.avatar ?? '').trim();
}
