# PBB Helper UI Property Editor Secret Field Proposal

## Summary

`ui.property.editor` needs a supported secret/password property kind so PBB apps can keep integration and credential settings inside the shared property-editor contract without falling back to app-local custom sections.

This proposal is based on a real downstream need in `PBB Hotline Beta`, where the Admin Runtime Settings modal now includes an `Integration` section for Realtime configuration:

- `Realtime URL`
- `Realtime Token Signing Secret`

The URL fits `ui.property.editor` cleanly. The signing secret does not, because the current editor does not expose a masked/secret field type.

## Current Gap

Current `ui.property.editor` behavior is good for ordinary settings such as:

- text
- number
- select
- boolean

But it does not currently expose a property kind for values that should be:

- masked by default
- revealable through a shared show/hide interaction
- still editable through the same property-editor lifecycle as other settings

Practical downstream effect:

- apps can keep normal settings inside the shared editor
- secret-bearing settings must be rendered outside the editor
- the settings modal becomes partially helper-owned and partially app-owned
- a repeated cross-project integration pattern starts drifting into project-local composition

## Current Hotline Workaround

Current implementation in `c:\wamp64\www\pbb\hotline`:

- runtime settings still use `ui.property.editor`
- the `Integration` section is custom-composed in the modal body
- `Realtime Token Signing Secret` is rendered separately with `ui.password`

This works, but it is not the clean shared contract.

Instead of a single property-editor schema, Hotline now has:

- property-editor data for regular runtime settings
- handwritten section markup for integration settings
- app-owned wiring to bridge the separate password field back into the same draft state

That is a real Helper gap, not a Hotline-only styling preference.

## Proposed Direction

Add a narrow secret/password property kind to `ui.property.editor`.

Recommended V1 shape:

```js
createPropertyEditor(container, {
  sections: [
    {
      id: "integration",
      title: "Integration",
      properties: [
        {
          id: "realtime_url",
          label: "Realtime URL",
          kind: "text",
          value: "https://realtime.pbb.ph",
        },
        {
          id: "realtime_token_signing_secret",
          label: "Realtime Token Signing Secret",
          kind: "password",
          value: "",
        },
      ],
    },
  ],
});
```

## Recommendation

Preferred V1:

- `kind: "password"`

Reason:

- it reuses the already shipped Helper `ui.password` behavior
- it is clearer and more conventional than inventing a Hotline-specific `secret` concept
- it keeps the property-editor schema aligned with ordinary form vocabulary

If Helper wants an alias later, `kind: "secret"` could map to the same internal implementation, but `password` is enough for the first shared contract.

## Expected Behavior

Any accepted property-editor password kind should guarantee:

- the value is masked by default
- the field uses the shared Helper show/hide toggle behavior from `ui.password`
- the property still participates in normal property-editor change events
- apps do not need to special-case save flows for password properties
- apps can mix password and non-password properties in one editor instance

## Suggested Minimal API Contract

The existing property shape should remain narrow.

Suggested additive support:

```js
{
  id: "realtime_token_signing_secret",
  label: "Realtime Token Signing Secret",
  kind: "password",
  value: "",
  placeholder: "Enter signing secret",
  help: "Shared signing secret used when issuing trusted admission for PBB Realtime.",
}
```

Optional additive fields, only if Helper already supports them elsewhere:

- `placeholder`
- `help`
- `autocomplete`

## Why This Belongs In Helper

This is not just one Hotline modal.

Cross-project browser apps will likely need integration settings such as:

- signing secrets
- API secrets
- shared tokens
- webhook secrets
- private integration keys

If `ui.property.editor` cannot host those fields cleanly, every app will eventually end up composing:

- property editor for normal fields
- ad hoc secret inputs beside it

That is exactly the kind of repeated infrastructure gap the Helper playbook says should become a proposal rather than a permanent local workaround.

## Non-Goals

This proposal does not ask `ui.property.editor` to:

- become a credential vault
- hide values from app state entirely
- add special encryption/storage behavior
- own backend secret persistence rules

It only asks the editor to support a proper masked password-style field in the same schema as other settings.

## Concrete Hotline Example

Desired Hotline settings schema after Helper support:

```js
{
  sections: [
    {
      id: "alerts",
      title: "Alerts",
      properties: [...],
    },
    {
      id: "integration",
      title: "Integration",
      properties: [
        {
          id: "realtime_url",
          label: "Realtime URL",
          kind: "text",
          value: state.settings.draft.realtime_url,
        },
        {
          id: "realtime_token_signing_secret",
          label: "Realtime Token Signing Secret",
          kind: "password",
          value: state.settings.draft.realtime_token_signing_secret,
        },
      ],
    },
  ],
}
```

That would let Hotline remove:

- the custom integration section markup
- the manual `ui.password` mount logic
- the modal-specific split between helper-owned and app-owned settings UI

## Suggested Helper Follow-Through

If accepted, Helper should add:

- `ui.property.editor` support for `kind: "password"`
- internal reuse of the shared `ui.password` control where practical
- demo coverage for a mixed settings editor containing regular fields plus a password field
- regression coverage proving the password property:
  - renders masked
  - toggles visibility correctly
  - still emits the normal property change contract
- README/playbook note so downstream apps stop custom-composing secret settings beside the property editor

## Recommendation To Helper

Treat this as a real shared `ui.property.editor` gap.

The current repeated smell is:

- app uses `ui.property.editor` for settings
- one or two settings are secret-bearing
- editor cannot represent them cleanly
- app manually composes `ui.password` beside the editor

That is a reusable Helper concern, and Hotline Beta is providing a concrete downstream case for fixing it now.
