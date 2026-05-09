# PBB Helper UI Form Modal Conditional Visibility Proposal

## Summary

`ui.form.modal` needs a supported way to declare field visibility rules based on current form values.

This proposal is based on a real downstream need in `PBB Hotline Beta`, where the Admin `Incident Type Setup` flow includes a field-definition modal with rows that should appear only for certain `input_type` values.

Current Hotline rules:

- `unit`, `min`, `max`, `step` only when `input_type === "number"`
- `default_value` only when `input_type` is `text`, `textarea`, or `number`
- `options` only when `input_type` is `select` or `checkbox`

The behavior is valid and reusable, but the current implementation has to rebuild the modal row schema manually on every relevant change.

## Current Gap

`ui.form.modal` already handles:

- field rendering
- validation
- initial values
- change events
- submit lifecycle

But it does not currently expose a declarative contract for:

- showing a field only when another field has a specific value
- hiding a field when a value no longer applies
- automatically excluding hidden fields from validation and stale submission semantics

Practical downstream effect:

- apps must detect changes themselves
- apps must rebuild rows manually with `modal.update({ rows })`
- field visibility rules become app-local orchestration instead of shared form behavior

## Current Hotline Workaround

Current implementation in `c:\wamp64\www\pbb\hotline\resources\js\surfaces\adminSurface.js`:

- `incidentTypeFieldFormRows(inputType)` builds different row arrays based on the current `input_type`
- `openIncidentTypeFieldForm(...)` listens to `onChange`
- when `input_type` changes, Hotline calls:

```js
modal.update({
  rows: incidentTypeFieldFormRows(values?.input_type ?? 'text'),
});
```

- `normalizeIncidentTypeFieldPayload(...)` must also strip values that no longer apply, such as:
  - removing `options` for non-option fields
  - removing `min/max/step/unit` for non-number fields
  - removing `default_value` for unsupported field types

This works, but it is a clear shared form gap rather than a Hotline-only preference.

## Why This Belongs In Helper

This is a common admin-form pattern, not a one-off Hotline trick.

Shared examples that will likely recur across PBB apps:

- show numeric constraints only for numeric inputs
- show select options only for choice-based fields
- show integration settings only when a feature toggle is enabled
- show advanced sections only when an admin opts into them
- show follow-up fields only when a prior answer makes them relevant

If Helper does not support this directly, every app will eventually implement:

- local visibility checks
- local row regeneration
- local hidden-field cleanup rules

That is exactly the kind of repeated infrastructure behavior that should move into Helper.

## Proposed Direction

Add narrow declarative visibility support to `ui.form.modal`.

Recommended V1:

- `visibleWhen`

Optional companion, only if Helper finds it useful:

- `hiddenWhen`

The goal is not to turn `ui.form.modal` into a full rules engine. The goal is to cover the common case cleanly.

## Recommended API Shape

Example:

```js
createFormModal({
  rows: [
    [
      {
        type: 'ui.select',
        name: 'input_type',
        label: 'Input Type',
        options: [
          { value: 'text', label: 'Text' },
          { value: 'textarea', label: 'Textarea' },
          { value: 'number', label: 'Number' },
          { value: 'select', label: 'Select' },
          { value: 'checkbox', label: 'Checkbox' },
        ],
      },
    ],
    [
      {
        type: 'input',
        input: 'text',
        name: 'default_value',
        label: 'Default Value',
        visibleWhen: {
          input_type: ['text', 'textarea', 'number'],
        },
      },
    ],
    [
      {
        type: 'input',
        input: 'number',
        name: 'min',
        label: 'Min',
        visibleWhen: {
          input_type: 'number',
        },
      },
      {
        type: 'input',
        input: 'number',
        name: 'max',
        label: 'Max',
        visibleWhen: {
          input_type: 'number',
        },
      },
    ],
    [
      {
        type: 'textarea',
        name: 'options_text',
        label: 'Options',
        visibleWhen: {
          input_type: ['select', 'checkbox'],
        },
      },
    ],
  ],
});
```

## Suggested V1 Semantics

Recommended matching semantics:

- string value means exact equality
- array value means inclusion
- all properties in the `visibleWhen` object must match

Examples:

```js
visibleWhen: { input_type: 'number' }
```

```js
visibleWhen: { input_type: ['select', 'checkbox'] }
```

This keeps the first Helper contract simple and easy to document.

## Required Behavior

If a field is hidden by a visibility rule, Helper should handle all of this consistently:

- the field is not rendered
- the field does not validate
- the layout reflows automatically
- the field value remains available if the user switches back, unless Helper intentionally documents a different policy

Recommended submit behavior:

- hidden fields should either be omitted from form values, or clearly marked as excluded from built-in validation/error application

The key requirement is consistency. Apps should not need to guess whether hidden values will still validate or leak into submission.

## Nice-To-Have But Not Required For V1

These would be useful later, but are not required for the first Helper pass:

- function-based predicates
- row-level or section-level visibility rules
- visibility callbacks for custom/content rows
- explicit `clearOnHide` behavior

The first win is field-level declarative visibility for ordinary form rows.

## Concrete Hotline Example

Desired Hotline schema after Helper support:

```js
[
  [
    {
      type: 'ui.select',
      name: 'input_type',
      label: 'Input Type',
      required: true,
      options: INCIDENT_FIELD_INPUT_TYPE_OPTIONS,
    },
  ],
  [
    {
      type: 'input',
      input: 'text',
      name: 'default_value',
      label: 'Default Value',
      visibleWhen: {
        input_type: ['text', 'textarea', 'number'],
      },
    },
  ],
  [
    {
      type: 'input',
      input: 'number',
      name: 'min',
      label: 'Min',
      visibleWhen: {
        input_type: 'number',
      },
    },
    {
      type: 'input',
      input: 'number',
      name: 'max',
      label: 'Max',
      visibleWhen: {
        input_type: 'number',
      },
    },
  ],
]
```

That would let Hotline remove:

- manual row regeneration for this modal
- change-handler-specific schema rebuilding
- some hidden-field cleanup logic that exists only because the modal itself cannot express visibility

## Non-Goals

This proposal does not ask Helper to:

- build a general-purpose rules engine
- introduce computed expressions or templating
- replace app-level payload normalization entirely
- broaden into workflow logic beyond field visibility

The ask is intentionally narrow: make common dependent-field visibility a first-class shared form behavior.

## Suggested Helper Follow-Through

If accepted, Helper should add:

- `visibleWhen` support in `ui.form.modal`
- demo coverage for a modal with dependent fields
- regression coverage proving:
  - fields appear/disappear as controlling values change
  - hidden fields do not validate
  - layout reflows cleanly
  - submit values stay predictable
- README/playbook guidance so downstream apps stop rebuilding row schemas for basic visibility cases

## Recommendation To Helper

Treat this as a real `ui.form.modal` gap.

Hotline has a working local solution, but it is solving a generic repeated form problem at the app layer. A narrow declarative visibility contract in Helper would reduce local orchestration, improve consistency across projects, and better match the Helper-first / proposal-first direction already being used elsewhere.
