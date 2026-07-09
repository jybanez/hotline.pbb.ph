# PBB Hotline Community SDK

Source-only browser SDK for PBB apps that need to react to Hotline community alert status and public/community broadcasts.

```js
import { createHotlineCommunityClient } from './js/hotline-community.js';

const hotline = createHotlineCommunityClient({
  baseUrl: 'https://hotline.pbb.ph',
});

hotline.on('alert.changed', ({ current }) => {
  console.log(current.level);
});

hotline.on('broadcast.received', ({ broadcast }) => {
  console.log(broadcast.title, broadcast.message);
});

await hotline.start();
```

The SDK fetches Hotline public endpoints and requests its own narrow Realtime admission. Consuming apps do not need Hotline secrets or Realtime project codes.

## Public Endpoints

- `GET /api/public/community-status`
- `GET /api/public/community-realtime`

`community-status` returns the current alert and active broadcasts targeted to citizen/caller/community audiences. Operator-only broadcasts are filtered out.

`community-realtime` returns a narrow Realtime admission for:

```text
hotline.settings.global
hotline.broadcast.global
```

The token allows only `session.connect` and `room.join`.

## Events

- `community.loaded`
- `alert.loaded`
- `alert.changed`
- `broadcast.received`
- `broadcast.removed`
