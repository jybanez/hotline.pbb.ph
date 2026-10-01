import assert from 'node:assert/strict';
import { resolveChatSenderAvatar } from '../../resources/js/surfaces/chatSenderAvatar.js';

const participants = [{ id: 12, avatar: '/operator.webp' }, { id: 34, avatar: '/citizen.webp' }];
assert.equal(resolveChatSenderAvatar({ sender: { user_id: '12' } }, participants), '/operator.webp');
assert.equal(resolveChatSenderAvatar({ sender: { user_id: 34 } }, participants), '/citizen.webp');
assert.equal(resolveChatSenderAvatar({ sender: { user_id: 99 } }, participants), '');
assert.equal(resolveChatSenderAvatar({ sender: {} }, participants), '');
assert.equal(resolveChatSenderAvatar({ sender: { user_id: 99, avatar: '/other.webp' } }, participants), '/other.webp');
assert.equal(resolveChatSenderAvatar({ sender: { user_id: 12 } }, [{ id: 12, avatar: null }]), '');
console.log('Chat sender avatar identity checks passed.');
