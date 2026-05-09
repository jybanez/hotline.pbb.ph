---
name: hotline-helper-vendor-refresh
description: Refresh the vendored helpers.pbb.ph copy for this Hotline repo only. Use when updating Helper UI behavior in c:\wamp64\www\pbb\hotline, especially when helper changes are not appearing in the app, when the user asks to update the local vendor copy, or when bundle-preferring Hotline surfaces need current helper source/dist assets under public\vendor\helpers.pbb.ph.
---

# Hotline Helper Vendor Refresh

This skill is only for `c:\wamp64\www\pbb\hotline`.

Hotline serves Helper from:
- `c:\wamp64\www\pbb\hotline\public\vendor\helpers.pbb.ph`

Do not stop at updating the source helper clone:
- `c:\wamp64\www\pbb\helpers.pbb.ph`

Official upstream source of truth:
- `https://github.com/jybanez/helpers.pbb.ph.git`

Hotline is bundle-preferring at runtime, so actual behavior comes from the vendored Helper copy inside this repo.

## Use This Skill When

- the user says to update the local vendor copy of Helper
- a new Helper feature exists upstream but does not appear in Hotline
- Helper form/modal/device-primer/navbar changes seem missing in the app
- bundle-backed Helper behavior looks stale even after a normal app rebuild

## Required Process

1. Update the source Helper repo first.
   - Repo: `c:\wamp64\www\pbb\helpers.pbb.ph`
   - Check `git status --short --branch`
   - Pull latest upstream with `git pull --ff-only origin main`

2. Determine whether a Helper rebuild is required.
   - If the Helper team says a new bundle/build is needed, do not assume `git pull` is enough.
   - If relevant, rebuild in the source Helper repo before refreshing Hotline’s vendor copy.
   - Typical cases: changes affecting `dist/`, `ui.loader`, `ui.form.modal*`, or bundle-backed runtime behavior.

3. Refresh Hotline’s vendored Helper copy.
   - Repo: `c:\wamp64\www\pbb\hotline\public\vendor\helpers.pbb.ph`
   - Prefer a direct fast-forward there if it is a clean git clone:
     - `git -C public\vendor\helpers.pbb.ph status --short --branch`
     - `git -C public\vendor\helpers.pbb.ph pull --ff-only origin main`
   - Verify vendored HEAD after update.

4. Verify the actual vendored files, not just the source clone.
   - Check the specific files related to the reported feature.
   - Example for account avatar UI:
     - `public\vendor\helpers.pbb.ph\dist\helpers.ui.bundle.min.js`
     - `public\vendor\helpers.pbb.ph\dist\helpers.ui.bundle.min.css`
     - `public\vendor\helpers.pbb.ph\js\ui\ui.form.modal.js`
     - `public\vendor\helpers.pbb.ph\js\ui\ui.form.modal.presets.js`
     - `public\vendor\helpers.pbb.ph\css\ui\ui.form.modal.css`

5. Keep Hotline’s helper cache-bust pin aligned.
   - Update both:
     - `c:\wamp64\www\pbb\hotline\resources\js\surfaces\surfaceShared.js`
     - `c:\wamp64\www\pbb\hotline\resources\js\app.js`
   - Set `HELPER_VENDOR_REV` to the new Helper short commit only when Hotline should point at the refreshed vendor line.

6. Rebuild Hotline.
   - Run: `npm run build`

7. Tell the user to hard refresh the browser if the issue is visual/runtime stale-cache related.
   - Normal reload may still keep old vendor assets.

## Verification Checklist

- source Helper clone HEAD is current
- vendored Hotline Helper HEAD is current
- expected vendored source/dist files contain the new feature
- Hotline `HELPER_VENDOR_REV` is aligned
- `npm run build` passes

## Important Rules

- Treat `public\vendor\helpers.pbb.ph` as the runtime source of truth for Hotline behavior.
- Do not assume updating `c:\wamp64\www\pbb\helpers.pbb.ph` alone changes Hotline.
- Do not rely only on filesystem timestamps; verify git HEAD and file contents when needed.
- If the Helper team says a bundle rebuild is pending, wait for that upstream update before blaming Hotline app code.
