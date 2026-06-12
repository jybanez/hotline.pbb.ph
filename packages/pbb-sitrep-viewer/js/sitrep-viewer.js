const SECTION_NAMES = [
  'header',
  'summary',
  'situation',
  'damage',
  'population',
  'actions',
  'needs',
  'gaps',
  'period_activity',
  'verification_notes',
  'footer',
];

const VALID_LAYOUTS = new Set(['document', 'compact', 'section', 'brief']);

let nextInstanceId = 1;

export function createSitrepViewer(container, options = {}) {
  if (!container || typeof container.replaceChildren !== 'function') {
    throw new TypeError('createSitrepViewer requires a DOM container element.');
  }

  const instanceId = nextInstanceId++;
  let state = normalizeOptions(options);
  let destroyed = false;
  let highlightedRef = null;

  const render = () => {
    if (destroyed) {
      return;
    }

    container.classList.add('pbb-sitrep-js-viewer-host');
    container.dataset.sitrepViewerInstance = String(instanceId);
    container.replaceChildren(renderViewer(state, instanceId));
    applySourceFilter(container, state.sourceFilter);
    if (highlightedRef) {
      highlightEvidenceNode(container, highlightedRef);
    }
  };

  const handleClick = (event) => {
    const target = event.target instanceof Element ? event.target : null;
    const interactive = target?.closest('[data-sitrep-evidence-ref], [data-source-hub-id], [data-concern-group]');
    if (!interactive || !container.contains(interactive)) {
      return;
    }

    const payload = interactionPayload(interactive, event);
    if (payload.ref) {
      state.onEvidenceClick?.(payload);
    }
    if (payload.sourceHubId) {
      state.onSourceClick?.(payload);
    }
    if (payload.concernGroup) {
      state.onConcernClick?.(payload);
    }
    state.onInteraction?.(payload);
  };

  container.addEventListener('click', handleClick);
  render();

  return {
    update(nextOptions = {}) {
      state = normalizeOptions({ ...state, ...nextOptions });
      render();
      return this;
    },
    setSitrep(sitrep) {
      state = normalizeOptions({ ...state, sitrep });
      render();
      return this;
    },
    setLayout(layout) {
      state = normalizeOptions({ ...state, layout });
      render();
      return this;
    },
    setSection(section) {
      state = normalizeOptions({ ...state, section, sections: null });
      render();
      return this;
    },
    setSections(sections) {
      state = normalizeOptions({ ...state, sections, section: null });
      render();
      return this;
    },
    findEvidence(ref) {
      return findEvidenceNode(container, ref);
    },
    scrollToEvidence(ref, options = {}) {
      const node = findEvidenceNode(container, ref);
      node?.scrollIntoView({
        behavior: options.behavior ?? 'smooth',
        block: options.block ?? 'center',
        inline: options.inline ?? 'nearest',
      });
      return node;
    },
    highlightEvidence(ref, options = {}) {
      highlightedRef = ref;
      clearHighlight(container);
      const node = highlightEvidenceNode(container, ref);
      if (node && options.scroll !== false) {
        node.scrollIntoView({
          behavior: options.behavior ?? 'smooth',
          block: options.block ?? 'center',
          inline: options.inline ?? 'nearest',
        });
      }
      return node;
    },
    clearHighlight() {
      highlightedRef = null;
      clearHighlight(container);
      return this;
    },
    filterBySource(sourceHubId) {
      state = normalizeOptions({ ...state, sourceFilter: text(sourceHubId) });
      applySourceFilter(container, state.sourceFilter);
      return this;
    },
    clearSourceFilter() {
      state = normalizeOptions({ ...state, sourceFilter: null });
      applySourceFilter(container, null);
      return this;
    },
    getState() {
      return {
        sitrep: state.sitrep,
        layout: state.layout,
        section: state.section,
        sections: state.sections ? [...state.sections] : null,
        preview: state.preview,
        sourceFilter: state.sourceFilter,
      };
    },
    destroy() {
      destroyed = true;
      container.removeEventListener('click', handleClick);
      container.replaceChildren();
      delete container.dataset.sitrepViewerInstance;
      container.classList.remove('pbb-sitrep-js-viewer-host');
    },
  };
}

export function renderSitrep(sitrep, options = {}) {
  return renderViewer(normalizeOptions({ ...options, sitrep }), 0);
}

export function renderSitrepSection(sitrep, section, options = {}) {
  return renderViewer(normalizeOptions({ ...options, sitrep, section, sections: null }), 0);
}

export function sectionNames() {
  return [...SECTION_NAMES];
}

function normalizeOptions(options) {
  const sitrep = normalizePayload(options.sitrep ?? {});
  const layout = normalizeLayout(options.layout);
  const section = normalizeSectionName(options.section ?? null);
  const sections = Array.isArray(options.sections)
    ? options.sections.map(normalizeSectionName).filter(Boolean)
    : null;
  const rowActions = normalizeRowActions(options.rowActions);

  return {
    sitrep,
    layout,
    section,
    sections,
    preview: Boolean(options.preview),
    sourceFilter: options.sourceFilter ? text(options.sourceFilter) : null,
    rowActions,
    onInteraction: functionOrNull(options.onInteraction),
    onEvidenceClick: functionOrNull(options.onEvidenceClick),
    onSourceClick: functionOrNull(options.onSourceClick),
    onConcernClick: functionOrNull(options.onConcernClick),
  };
}

function normalizePayload(payload) {
  if (typeof payload === 'string') {
    const parsed = JSON.parse(payload);
    if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
      throw new TypeError('SITREP JSON payload must decode to an object.');
    }
    payload = parsed;
  }

  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) {
    payload = {};
  }

  return {
    ...payload,
    status: text(payload.status, 'draft'),
    visibility: text(payload.visibility, 'private'),
    alert_level: text(payload.alert_level, 'Normal'),
    title: text(payload.title, 'PBB SITREP'),
  };
}

function normalizeLayout(layout) {
  const normalized = text(layout, 'document').toLowerCase().replaceAll('-', '_');
  return VALID_LAYOUTS.has(normalized) ? normalized : 'document';
}

function normalizeSectionName(section) {
  if (section === null || section === undefined) {
    return null;
  }

  const normalized = String(section).trim().toLowerCase().replaceAll('-', '_');
  return normalized === '' ? null : normalized;
}

function normalizeRowActions(rowActions) {
  if (!Array.isArray(rowActions)) {
    return [];
  }

  return rowActions
    .filter(isObject)
    .map((action) => ({
      id: text(action.id),
      label: text(action.label),
      title: text(action.title),
      appliesTo: functionOrNull(action.appliesTo),
      onClick: functionOrNull(action.onClick),
    }))
    .filter((action) => action.id && action.label && action.onClick);
}

function renderViewer(state, instanceId) {
  const root = element('main', {
    class: classNames(
      'pbb-sitrep-viewer',
      'sitrep-page',
      'pbb-sitrep-js-viewer',
      state.layout !== 'document' ? `is-layout-${state.layout}` : null,
      state.sitrep.status === 'draft' ? 'is-draft' : null,
    ),
    'data-sitrep-js-instance': String(instanceId),
  });
  const document = element('article', { class: 'sitrep-document' });

  if (state.preview && !(state.sitrep.status === 'published' && state.sitrep.visibility === 'public')) {
    document.append(element('div', { class: 'sitrep-preview-banner' }, 'Preview only. This SITREP is not public unless status is published and visibility is public.'));
  }

  const sections = selectedSections(state);
  sections.forEach((section) => {
    document.append(renderSection(state.sitrep, section, state.layout, state));
  });

  root.append(document);
  return root;
}

function selectedSections(state) {
  if (state.sections?.length) {
    return state.sections;
  }
  if (state.section) {
    return [state.section];
  }

  return SECTION_NAMES;
}

function renderSection(sitrep, section, layout = 'document', state = {}) {
  let node;
  switch (section) {
    case 'header':
      node = renderHeader(sitrep);
      break;
    case 'summary':
      node = renderSummary(sitrep);
      break;
    case 'situation':
      node = renderSituation(sitrep, layout);
      break;
    case 'damage':
      node = renderDamage(sitrep);
      break;
    case 'population':
      node = renderPopulation(sitrep);
      break;
    case 'actions':
      node = renderActions(sitrep, layout);
      break;
    case 'needs':
      node = renderNeeds(sitrep, layout);
      break;
    case 'gaps':
      node = renderGaps(sitrep, layout, state);
      break;
    case 'period_activity':
    case 'period_activity_report':
      node = renderPeriodActivity(sitrep);
      break;
    case 'verification_notes':
    case 'verification':
      node = renderVerificationNotes(sitrep);
      break;
    case 'footer':
    case 'source_snapshot':
    case 'data_quality':
      node = renderFooter(sitrep);
      break;
    default:
      throw new Error(`Unknown SITREP section [${section}]. Supported sections: ${SECTION_NAMES.join(', ')}.`);
  }

  node.setAttribute('data-sitrep-section', section);
  return node;
}

function renderHeader(sitrep) {
  const summary = section(sitrep, 'summary');
  const sourceSnapshot = section(sitrep, 'source_snapshot');
  const generation = object(sourceSnapshot.generation);
  const identity = sitrepIdentity(sitrep);
  const preparedBy = text(generation.prepared_by_label, 'System Generated');
  const sequence = String(sitrep.sequence_number ?? '').padStart(4, '0');
  const header = element('header', { class: 'sitrep-header' });
  const identityBlock = element('div');

  identityBlock.append(
    element('p', { class: 'sitrep-eyebrow' }, 'PBB Hotline Periodic SITREP'),
    element('h1', {}, identity.title),
    element('p', { class: 'sitrep-periodline' }, joined([identity.hub ?? sitrep.coverage_area, identity.period ?? periodLabel(sitrep)])),
    element('p', { class: 'sitrep-headline' }, text(summary.headline, 'Situation report generated from Hotline incident records.')),
  );

  header.append(
    identityBlock,
    element('p', { class: 'sitrep-metaline' }, inlineParts([
      `#${sequence}`,
      `${capitalize(sitrep.status)} / ${capitalize(sitrep.visibility)}`,
      sitrep.alert_level,
      preparedBy,
      formatDate(sitrep.generated_at),
    ])),
  );

  return header;
}

function renderSummary(sitrep) {
  const summary = section(sitrep, 'summary');
  const situation = section(sitrep, 'situation');
  const sourceSnapshot = section(sitrep, 'source_snapshot');
  const targetName = sourceTargetName(sourceSnapshot);
  const wrapper = reportSection('Summary', 'Executive Situation Assessment', 'sitrep-summary');

  wrapper.append(element('p', { class: 'sitrep-narrative' }, text(situation.executive_assessment ?? situation.narrative, 'No executive assessment is available.')));

  if (Array.isArray(summary.gap_cards) && summary.gap_cards.length) {
    wrapper.append(cardRow('Gaps', summary.gap_cards, false, { section: 'summary', refPrefix: 'summary.gap_cards', targetName }));
  }
  if (Array.isArray(summary.accomplishment_cards) && summary.accomplishment_cards.length) {
    wrapper.append(cardRow('Accomplishments', summary.accomplishment_cards, true, { section: 'summary', refPrefix: 'summary.accomplishment_cards', targetName }));
  }
  if (Array.isArray(situation.decision_points) && situation.decision_points.length) {
    const watch = element('div', { class: 'sitrep-watch' }, element('h3', {}, 'Decision Points'));
    situation.decision_points.filter(isObject).forEach((point) => {
      watch.append(element('p', {}, element('strong', {}, `${text(point.title, 'Decision point')}:`), ` ${text(point.body)}`));
    });
    wrapper.append(watch);
  }

  const picture = object(situation.current_operating_picture);
  if (Object.keys(picture).length) {
    wrapper.append(element('p', { class: 'sitrep-source-counts' },
      element('strong', {}, 'Current totals: '),
      inlineParts([
        `${number(picture.open_reports)} open reports`,
        `${number(picture.active_reports)} active`,
        `${number(picture.deferred_reports)} deferred`,
        `${number(picture.current_assignments)} assignments`,
        `${number(picture.current_resource_units)} requested resource units`,
      ]),
    ));
  }

  return wrapper;
}

function renderSituation(sitrep, layout = 'document') {
  const situation = section(sitrep, 'situation');
  const wrapper = reportSection('Situation', 'Current Areas of Concern');
  wrapper.append(element('p', { class: 'sitrep-narrative' }, text(situation.narrative, 'No situation narrative is available.')));

  if (Array.isArray(situation.concern_groups) && situation.concern_groups.length) {
    wrapper.append(table('Grouped Current Concerns', ['Concern', 'Open Reports', 'Areas', 'Main Signals', 'Teams', 'Resources'], situation.concern_groups.map((row) => [
      row.concern,
      row.open_reports,
      listText(row.areas),
      row.main_signals,
      row.current_assignments,
      row.resource_units,
    ]), { section: 'situation', rowPayloads: situation.concern_groups, concernKey: 'concern', layout, className: 'is-concern-groups' }));
    wrapper.append(element('p', { class: 'sitrep-note' }, 'Individual incident references are retained in the source snapshot and supporting tables.'));
  }
  const locations = array(situation.current_locations ?? situation.locations);
  if (locations.length) {
    wrapper.append(table('Current Locations', ['Area', 'Alert Level', 'Incidents'], locations.map((row) => [
      row.area,
      row.alert_level,
      row.incidents ?? row.count,
    ]), { section: 'situation', rowPayloads: locations, locationKey: 'area' }));
  }
  if (Array.isArray(situation.incident_types) && situation.incident_types.length) {
    wrapper.append(table('Current Incident Types', ['Type', 'Locations', 'Mentions'], situation.incident_types.map((row) => [
      row.type,
      row.locations ?? row.location_count,
      row.mentions ?? row.count,
    ]), { section: 'situation', rowPayloads: situation.incident_types }));
  }

  return wrapper;
}

function renderDamage(sitrep) {
  const damage = section(sitrep, 'damage');
  const wrapper = reportSection('Damage', 'Reported Damage');
  const rows = array(damage.damage_summary ?? damage.summary_rows ?? damage.damage_groups);
  if (rows.length) {
    wrapper.append(table('Damage Summary', ['Damage Type', 'Reports', 'Severity / Signal', 'Affected Assets'], rows.map((row) => [
      row.damage_type ?? row.type,
      row.reports ?? row.count,
      row.severity ?? row.signal ?? row.severity_signal,
      listText(row.affected_assets ?? row.assets),
    ]), { section: 'damage', rowPayloads: rows, empty: text(damage.empty_state, 'No damage entries available.') }));
    wrapper.append(element('p', { class: 'sitrep-note' }, 'Individual damage entries are retained in the source snapshot and exports.'));
  } else {
    wrapper.append(empty(text(damage.empty_state, 'No damage entries available.')));
  }
  if (damage.confidence_note) {
    wrapper.append(element('p', { class: 'sitrep-note' }, text(damage.confidence_note)));
  }
  return wrapper;
}

function renderPopulation(sitrep) {
  const population = section(sitrep, 'population');
  const showLocations = Number(sitrep.location_count ?? 1) > 1;
  const wrapper = reportSection('Population', 'Affected People');
  wrapper.append(metricGrid([
    ['People at Risk', population.people_at_risk ?? 0],
    ['People Helped', population.people_helped ?? population.citizens_assisted ?? population.callers_assisted ?? 0, 'is-positive'],
    ['Current Records', population.current_records ?? population.record_count ?? 0],
  ]));
  if (population.numeric_total_note) {
    wrapper.append(element('p', { class: 'sitrep-note' }, text(population.numeric_total_note)));
  }

  const rows = array(population.population_summary ?? population.population_groups);
  if (rows.length) {
    const headers = showLocations ? ['Population Signal', 'Locations', 'Reports', 'People / Families', 'Notes'] : ['Population Signal', 'Reports', 'People / Families', 'Notes'];
    wrapper.append(table('Population Summary', headers, rows.map((row) => {
      const values = [row.signal ?? row.population_signal];
      if (showLocations) values.push(row.locations ?? row.location_count);
      values.push(row.reports, row.people_families ?? row.people_or_families, row.notes);
      return values;
    }), { section: 'population', rowPayloads: rows }));
  }

  const breakdown = array(population.member_breakdown ?? rows.flatMap((row) => array(row.breakdowns).map((breakdownRow) => ({
    signal: row.signal ?? row.population_signal,
    ...breakdownRow,
  }))));
  if (breakdown.length) {
    const headers = showLocations ? ['Population Signal', 'Breakdown', 'Locations', 'Count'] : ['Population Signal', 'Breakdown', 'Count'];
    wrapper.append(table('Declared Member Breakdown', headers, breakdown.map((row) => {
      const values = [row.signal ?? row.population_signal, row.breakdown ?? row.label];
      if (showLocations) values.push(row.locations ?? row.location_count);
      values.push(row.count);
      return values;
    }), { section: 'population', rowPayloads: breakdown }));
  }
  if (rows.length) {
    wrapper.append(element('p', { class: 'sitrep-note' }, 'Individual population entries are retained in the source snapshot and exports.'));
  }
  if (population.confidence_note) {
    wrapper.append(element('p', { class: 'sitrep-note' }, text(population.confidence_note)));
  }

  return wrapper;
}

function renderActions(sitrep, layout = 'document') {
  const actions = section(sitrep, 'actions');
  const wrapper = reportSection('Actions', 'Response Posture');
  const deployment = array(actions.deployment_groups).map((row) => ({
    ...row,
    ...object(row.status_counts),
  }));
  if (deployment.length) {
    wrapper.append(groupedMatrix('Team Deployment', deployment, 'category', 'team', ['requested', 'assigned', 'accepted', 'en_route', 'on_scene'], {
      section: 'actions',
      className: 'sitrep-team-groups',
      groupClassName: 'sitrep-team-group',
      tableClassName: 'sitrep-team-matrix',
      cardsClassName: 'sitrep-team-cards',
      layout,
      zeroAsDash: true,
    }));
  }
  const timing = array(actions.timing_rows);
  if (timing.length) {
    wrapper.append(groupedMatrix('Assignment Timing', timing, 'team', 'incident_id', ['current_status', 'assigned_to_accepted', 'accepted_to_en_route', 'en_route_to_on_scene', 'elapsed_time'], {
      labels: {
        incident_id: 'Incident',
        current_status: 'Status',
        assigned_to_accepted: 'Accepted',
        accepted_to_en_route: 'En Route',
        en_route_to_on_scene: 'On Scene',
        elapsed_time: 'Time In Status',
      },
      formatPrimary: (value) => `#${String(value ?? '').padStart(6, '0')}`,
      section: 'actions',
      className: 'sitrep-assignment-groups',
      groupClassName: 'sitrep-assignment-group',
      tableClassName: 'sitrep-assignment-matrix',
      cardsClassName: 'sitrep-assignment-cards',
      layout,
      zeroAsDash: true,
    }));
  }
  if (deployment.length || timing.length) {
    wrapper.append(element('p', { class: 'sitrep-note' }, 'Timing rows are scenario-specific and derived from team assignment milestone timestamps. Time in Status shows how long an open assignment had been in its current status as of report generation, falling back to assignment time when older records do not have the milestone timestamp.'));
  }
  return wrapper;
}

function renderNeeds(sitrep, layout = 'document') {
  const needs = section(sitrep, 'needs');
  const showLocations = Number(sitrep.location_count ?? 1) > 1;
  const wrapper = reportSection('Needs', 'Current Resource Posture');
  const demand = array(needs.category_demand ?? needs.category_groups);
  if (demand.length) {
    const headers = showLocations ? ['Category', 'Locations', 'Quantity', 'Resources'] : ['Category', 'Quantity', 'Resources'];
    wrapper.append(table('Category Demand', headers, demand.map((row) => {
      const values = [row.category];
      if (showLocations) values.push(row.locations ?? row.location_count);
      values.push(row.quantity ?? row.quantity_requested, listText(row.resources));
      return values;
    }), { section: 'needs', rowPayloads: demand, layout, className: 'is-category-demand' }));
  }
  const resources = array(needs.resource_needs ?? needs.items).map((row) => ({
    ...row,
    quantity: row.quantity ?? row.quantity_requested,
    incidents: row.incidents ?? row.incident_count,
    locations: row.locations ?? row.location_count,
  }));
  if (resources.length) {
    wrapper.append(groupedMatrix('Resource Needs', resources, 'category', 'resource', showLocations ? ['locations', 'quantity', 'incidents'] : ['quantity', 'incidents'], {
      section: 'needs',
      className: 'sitrep-resource-groups',
      groupClassName: 'sitrep-resource-group',
      tableClassName: 'sitrep-resource-matrix',
      cardsClassName: 'sitrep-resource-cards',
      layout,
    }));
  }
  if (needs.confidence_note) {
    wrapper.append(element('p', { class: 'sitrep-note' }, text(needs.confidence_note)));
  }
  return wrapper;
}

function renderGaps(sitrep, layout = 'document', state = {}) {
  const gaps = section(sitrep, 'gaps');
  const needs = section(sitrep, 'needs');
  const population = section(sitrep, 'population');
  const sourceSnapshot = section(sitrep, 'source_snapshot');
  const targetName = sourceTargetName(sourceSnapshot);
  const wrapper = reportSection('Gaps', text(gaps.title, 'Response Constraints and Confidence Gaps'));
  if (gaps.intro || gaps.narrative) {
    wrapper.append(element('p', { class: 'sitrep-narrative' }, text(gaps.intro ?? gaps.narrative)));
  }
  const gapItems = array(gaps.items).filter((gap) => isObject(gap) && !isCountingScopeGap(gap));
  if (!gapItems.length) {
    wrapper.append(empty(text(gaps.empty_state, 'No gaps identified.')));
    return wrapper;
  }
  gapItems.forEach((gap) => {
    const fallbackRef = `gaps:${indexSlug(gap.title ?? gap.category ?? 'gap')}`;
    const card = element('article', {
      class: 'sitrep-gap',
      ...evidenceAttrs(gap, fallbackRef),
      'data-sitrep-section': 'gaps',
      'data-concern-group': text(gap.category),
    });
    const body = gap.decision_relevance ?? gap.body ?? '';
    card.append(
      gap.category ? element('span', {}, text(gap.category)) : null,
      element('strong', {}, text(gap.title, 'Gap')),
      body ? element('p', {}, text(body)) : null,
    );
    const evidence = gapEvidence(gap, targetName, needs, population, {
      sitrep,
      section: 'gaps',
      gap,
      layout,
      rowActions: state.rowActions,
    });
    if (evidence || gap.confidence_note) {
      const details = element('dl', { class: 'sitrep-gap-details' });
      if (evidence) {
        details.append(element('div', {}, element('dt', {}, 'Evidence'), element('dd', {}, evidence)));
      }
      if (gap.confidence_note) {
        details.append(element('div', {}, element('dt', {}, 'Confidence'), element('dd', {}, text(gap.confidence_note))));
      }
      card.append(details);
    }
    wrapper.append(card);
  });
  return wrapper;
}

function renderPeriodActivity(sitrep) {
  const situation = section(sitrep, 'situation');
  const activity = object(situation.period_activity);
  const wrapper = reportSection('Period Activity', 'Report Status History');
  if (!Object.keys(activity).length) {
    return emptySection(wrapper);
  }
  wrapper.append(metricGrid([
    ['Total reports', activity.total_reports ?? 0],
    ['Open at close', activity.open_at_close ?? 0],
    ['Resolved', activity.resolved_during_period ?? 0],
    ['Discarded / excluded', activity.discarded_excluded ?? 0],
  ]));
  wrapper.append(element('p', { class: 'sitrep-note' }, text(activity.note)));
  return wrapper;
}

function renderVerificationNotes(sitrep) {
  const situation = section(sitrep, 'situation');
  const notes = array(situation.verification_notes).map((note) => text(note)).filter(Boolean);
  const wrapper = reportSection('Verification', 'Verification Notes');
  if (!notes.length) {
    return emptySection(wrapper);
  }
  const watch = element('div', { class: 'sitrep-watch' });
  notes.forEach((note) => watch.append(element('p', {}, note)));
  wrapper.append(watch);
  return wrapper;
}

function renderFooter(sitrep) {
  const sourceSnapshot = section(sitrep, 'source_snapshot');
  const dataQuality = section(sitrep, 'data_quality');
  const gaps = section(sitrep, 'gaps');
  const privacyRedactions = section(sitrep, 'privacy_redactions');
  const footer = element('footer', { class: 'sitrep-footer' });
  footer.append(
    element('div', {},
      element('strong', {}, 'Data Quality'),
      element('p', {}, text(dataQuality.global_note ?? dataQuality.note, 'Generated from current PBB data.')),
      countingNotes(dataQuality, gaps),
    ),
  );

  const privacy = Object.entries(privacyRedactions).map(([key, value]) => `${key.replaceAll('_', ' ')}: ${String(value)}`);
  footer.append(element('div', {}, element('strong', {}, 'Privacy Defaults'), element('p', {}, privacy.join(', '))));

  const hotline = object(sourceSnapshot.hotline);
  const build = object(hotline.build);
  const generation = object(sourceSnapshot.generation);
  const target = object(sourceSnapshot.target);
  const hubNode = sourceHubNode(sourceSnapshot);
  const hub = object(hubNode.snapshot ?? hubNode);
  const primaryUplink = firstPrimary(array(hub.uplinks));
  const sourceSitreps = array(sourceSnapshot.source_sitreps);
  const sourceLines = [];
  const hotlineVersion = hotline.display_version ?? hotline.version;
  if (hotlineVersion) {
    sourceLines.push(inlineParts([
      `Hotline: ${hotlineVersion}`,
      build.id ? `Build ${build.id}` : null,
    ]));
  }
  if (hub.name) {
    sourceLines.push(inlineParts([
      `Hub Node: ${formatHubLabel(hub.name)}`,
      hub.deployment ? formatDeploymentLabel(hub.deployment) : null,
      hub.relay_hub_id,
    ]));
  }
  const uplinkName = primaryUplink?.hub?.name ?? primaryUplink?.uplink_domain;
  if (uplinkName) {
    sourceLines.push(`Uplink: ${formatHubLabel(uplinkName)}`);
  }
  if (generation.type === 'consolidated') {
    sourceLines.push(inlineParts([
      `Consolidated by ${formatSdkLabel(generation.sdk ?? 'pbb-sitrep-consolidator')}`,
      generation.sdk_version ? `SDK ${generation.sdk_version}` : null,
      generation.merge_rule_version ? `Merge rule ${generation.merge_rule_version}` : null,
    ]));
  }
  if (Object.keys(target).length) {
    sourceLines.push(inlineParts([
      `Target: ${target.name ?? 'Consolidated coverage'}`,
      target.level ? formatDeploymentLabel(target.level) : null,
      target.hub_id,
    ]));
  }
  if (sourceSitreps.length) {
    sourceLines.push(`Sources: ${sourceSitreps.length} accepted SITREP${sourceSitreps.length === 1 ? '' : 's'}`);
  }

  const snapshot = element('div', {}, element('strong', {}, 'Source Snapshot'));
  const lines = element('p', { class: 'sitrep-source-lines' });
  sourceLines.forEach((line) => lines.append(element('span', {}, line)));
  snapshot.append(lines);
  footer.append(snapshot);

  return footer;
}

function countingNotes(dataQuality, gaps = {}) {
  const notes = array(dataQuality.counting_notes).filter((note) => note && typeof note === 'object');
  array(gaps.items).filter(isCountingScopeGap).forEach((note) => notes.push(note));
  if (!notes.length) {
    return document.createDocumentFragment();
  }

  const wrapper = element('div', { class: 'sitrep-counting-notes' }, element('span', {}, 'Counting Notes'));
  const list = element('ul');
  notes.forEach((note) => {
    const item = element('li', {}, element('strong', {}, text(note.title, 'Counting note')));
    ['body', 'evidence', 'confidence_note'].forEach((field) => {
      const value = text(note[field], '');
      if (value !== '') {
        item.append(element('p', {}, value));
      }
    });
    list.append(item);
  });
  wrapper.append(list);

  return wrapper;
}

function isCountingScopeGap(gap) {
  if (!isObject(gap)) {
    return false;
  }

  return text(gap.type).toLowerCase() === 'counting_scope'
    || text(gap.category).toLowerCase() === 'counting rule';
}

function reportSection(eyebrow, title, extraClass = '') {
  const wrapper = element('section', { class: classNames('sitrep-section', extraClass) });
  wrapper.append(sectionHead(eyebrow, title));
  return wrapper;
}

function sectionHead(eyebrow, title) {
  const fragment = document.createDocumentFragment();
  fragment.append(element('p', { class: 'sitrep-eyebrow' }, eyebrow));
  fragment.append(element('h2', {}, title));
  return fragment;
}

function cardRow(title, cards, positive, options = {}) {
  const wrap = element('div', { class: classNames('sitrep-card-row', positive ? 'is-positive' : null) }, element('h3', {}, title));
  const grid = element('div', { class: 'sitrep-picture-grid' });
  cards.filter(isObject).forEach((card, index) => {
    grid.append(element('article', {
      class: classNames('sitrep-card', positive ? 'is-positive' : null),
      ...evidenceAttrs(card, `${options.refPrefix ?? 'summary.cards'}.${index + 1}`),
      ...sourceAttrs(card),
      'data-sitrep-section': options.section,
    },
      element('span', {}, text(card.label, 'Summary')),
      element('strong', {}, text(card.value)),
      renderSourceValues(card.source_values, options),
      !Array.isArray(card.source_values) || card.source_values.length === 0
        ? element('p', {}, text(card.note, 'Generated from available records.'))
        : null,
    ));
  });
  wrap.append(grid);
  return wrap;
}

function renderSourceValues(sourceValues, options = {}) {
  if (!Array.isArray(sourceValues) || sourceValues.length === 0) {
    return document.createDocumentFragment();
  }
  const list = element('ul', { class: 'sitrep-card-sources' });
  const values = sourceValues.filter(isObject);
  values.slice(0, 5).forEach((source, index) => {
    const attrs = {
      ...evidenceAttrs(source, `${options.refPrefix ?? 'summary.source_values'}.${index + 1}`),
      ...sourceAttrs(source),
      'data-sitrep-section': options.section,
    };
    list.append(element('li', attrs,
      element('strong', {}, shortLocation(text(source.source_hub_name ?? source.location, 'Source'), options.targetName)),
      element('span', {}, text(source.label ?? source.value, 'Reported')),
    ));
  });
  const remaining = values.length - 5;
  if (remaining > 0) {
    list.append(element('li', {}, element('strong', {}, 'More sources'), element('span', {}, `${remaining} additional source${remaining === 1 ? '' : 's'}`)));
  }
  return list;
}

function metricGrid(metrics) {
  const grid = element('div', { class: 'sitrep-metrics is-compact' });
  metrics.forEach(([label, value, extraClass]) => {
    grid.append(element('div', { class: classNames('sitrep-metric', extraClass ?? (label === 'People Helped' ? 'is-positive' : null)) },
      element('span', {}, label),
      element('strong', {}, number(value)),
    ));
  });
  return grid;
}

function applicableRowActions(context, rowActions = []) {
  const actionContext = rowActionContext(context);

  return array(rowActions).filter((action) => {
    if (!action?.appliesTo) {
      return true;
    }

    try {
      return action.appliesTo(actionContext) !== false;
    } catch (error) {
      console.warn('[pbb-sitrep-viewer] row action appliesTo failed.', error);
      return false;
    }
  });
}

function renderRowActions(context, rowActions = []) {
  const actionContext = rowActionContext(context);
  const actions = applicableRowActions(actionContext, rowActions);

  if (!actions.length) {
    return null;
  }

  const wrap = element('div', {
    class: 'sitrep-row-actions',
    'aria-label': 'Row actions',
  });

  actions.forEach((action) => {
    const button = element('button', {
      type: 'button',
      class: 'sitrep-row-action',
      title: action.title,
      'data-sitrep-row-action': action.id,
    }, action.label);

    button.addEventListener('click', (event) => {
      event.preventDefault();
      event.stopPropagation();

      try {
        const result = action.onClick({
          ...actionContext,
          event,
        });
        if (result && typeof result.catch === 'function') {
          result.catch((error) => console.error('[pbb-sitrep-viewer] row action failed.', error));
        }
      } catch (error) {
        console.error('[pbb-sitrep-viewer] row action failed.', error);
      }
    });

    wrap.append(button);
  });

  return wrap;
}

function rowActionContext(context = {}) {
  const row = object(context.row ?? context.gap);

  return {
    sitrep: context.sitrep ?? null,
    section: context.section ?? null,
    gap: context.gap ?? null,
    row,
    rowIndex: Number.isFinite(Number(context.rowIndex ?? context.index)) ? Number(context.rowIndex ?? context.index) : null,
    evidenceRef: text(context.evidenceRef),
    sourceHubId: text(row.source_hub_id ?? row.hub_id) || null,
    sourceRelayHubId: text(row.source_relay_hub_id ?? row.relay_hub_id) || null,
    locationName: text(row.source_hub_name ?? row.location ?? row.area ?? row.name) || null,
  };
}

function table(title, headers, rows, options = {}) {
  const wrap = element('div', { class: 'sitrep-table-card' });
  if (text(title)) {
    wrap.append(element('h3', {}, title));
  }
  if (!rows.length) {
    wrap.append(empty(options.empty ?? 'No records available.'));
    return wrap;
  }
  if (options.layout === 'compact' && (options.className === 'is-category-demand' || headers.length > 4)) {
    wrap.append(propertyRows(headers, rows, options));
    return wrap;
  }
  const tableEl = element('table', { class: classNames('sitrep-table', options.className) });
  const rowContexts = rows.map((row, index) => {
    const payload = object(options.rowPayloads?.[index] ?? row);
    return {
      sitrep: options.sitrep ?? null,
      section: options.section ?? null,
      gap: options.gap ?? null,
      row: payload,
      evidenceRef: evidenceRef(payload, `${options.section ?? 'table'}.${indexSlug(title)}.${index + 1}`),
      rowIndex: index,
      layout: options.layout ?? 'document',
    };
  });
  const hasRowActions = rowContexts.some((context) => applicableRowActions(context, options.rowActions).length > 0);
  tableEl.append(element('thead', {}, element('tr', {},
    ...headers.map((header) => element('th', {}, header)),
    hasRowActions ? element('th', { class: 'sitrep-row-actions-heading' }, 'Actions') : null,
  )));
  const tbody = element('tbody');
  rows.forEach((row, index) => {
    const payload = object(options.rowPayloads?.[index] ?? row);
    const rowContext = rowContexts[index];
    tbody.append(element('tr', {
      ...evidenceAttrs(payload, rowContext.evidenceRef),
      ...sourceAttrs(payload),
      'data-sitrep-section': options.section,
      'data-location-name': payload[options.locationKey] ?? payload.location ?? payload.area,
      'data-concern-group': payload[options.concernKey] ?? payload.concern,
    },
    ...row.map((value) => element('td', {}, tableCellText(value))),
    hasRowActions ? element('td', {}, renderRowActions(rowContext, options.rowActions)) : null));
  });
  tableEl.append(tbody);
  wrap.append(tableEl);
  return wrap;
}

function tableCellText(value) {
  if (Array.isArray(value)) {
    return value.map((item) => text(item)).filter(Boolean).join(', ') || '-';
  }

  return text(value, '-');
}

function propertyRows(headers, rows, options = {}) {
  const wrap = element('div', { class: 'sitrep-property-list' });
  const titleHeader = headers[0] ?? 'Group';
  rows.forEach((row, index) => {
    const payload = object(options.rowPayloads?.[index] ?? {});
    const title = text(row[0], 'Item');
    const group = element('article', {
      class: 'sitrep-property-group',
      ...evidenceAttrs(payload, `${options.section ?? 'property'}.${indexSlug(titleHeader)}.${index + 1}`),
      ...sourceAttrs(payload),
      'data-sitrep-section': options.section,
      'data-location-name': payload.location ?? payload.area ?? title,
      'data-concern-group': payload[options.concernKey] ?? payload.concern,
    }, element('h4', {}, element('span', {}, titleHeader), title));
    const list = element('dl');
    headers.forEach((header, headerIndex) => {
      if (headerIndex === 0 || isEmptyPropertyValue(row[headerIndex])) {
        return;
      }
      list.append(element('div', {}, element('dt', {}, header), element('dd', {}, propertyValue(header, row[headerIndex]))));
    });
    group.append(list);
    const actions = renderRowActions({
      sitrep: options.sitrep ?? null,
      section: options.section ?? null,
      gap: options.gap ?? null,
      row: payload,
      evidenceRef: evidenceRef(payload, `${options.section ?? 'property'}.${indexSlug(titleHeader)}.${index + 1}`),
      rowIndex: index,
      layout: options.layout ?? 'compact',
    }, options.rowActions);
    if (actions) {
      group.append(actions);
    }
    wrap.append(group);
  });
  return wrap;
}

function groupedMatrix(title, rows, groupKey, primaryKey, valueKeys, options = {}) {
  const wrap = element('div', { class: 'sitrep-table-card' }, element('h3', {}, title));
  const groupsWrap = element('div', { class: options.className ?? 'sitrep-team-groups' });
  const groups = new Map();
  rows.filter(isObject).forEach((row) => {
    const group = text(row[groupKey], 'Unspecified');
    if (!groups.has(group)) groups.set(group, []);
    groups.get(group).push(row);
  });

  groups.forEach((items, group) => {
    const groupEl = element('section', {
      class: options.groupClassName ?? 'sitrep-team-group',
      'data-sitrep-section': options.section,
      'data-concern-group': group,
    }, element('h4', {}, group));
    const headers = [options.labels?.[primaryKey] ?? label(primaryKey), ...valueKeys.map((key) => options.labels?.[key] ?? label(key))];
    const tableEl = element('table', { class: options.tableClassName ?? 'sitrep-team-matrix' });
    tableEl.append(element('thead', {}, element('tr', {}, ...headers.map((header) => element('th', {}, header)))));
    const tbody = element('tbody');
    items.forEach((item, index) => {
      tbody.append(element('tr', {
        ...evidenceAttrs(item, `${options.section ?? 'matrix'}.${indexSlug(title)}.${indexSlug(group)}.${index + 1}`),
        ...sourceAttrs(item),
        'data-sitrep-section': options.section,
      },
        element('td', {}, options.formatPrimary ? options.formatPrimary(item[primaryKey], item) : text(item[primaryKey], '-')),
        ...valueKeys.map((key) => element('td', {}, statusValue(item[key], options.zeroAsDash))),
      ));
    });
    tableEl.append(tbody);
    groupEl.append(tableEl);

    const cards = element('div', { class: options.cardsClassName ?? 'sitrep-team-cards' });
    items.forEach((item) => {
      const card = element('article', {}, element('strong', {}, options.formatPrimary ? options.formatPrimary(item[primaryKey], item) : text(item[primaryKey], '-')));
      const list = element('ul');
      valueKeys.forEach((key) => {
        if (isEmptyPropertyValue(item[key])) {
          return;
        }
        list.append(element('li', {}, element('span', {}, options.labels?.[key] ?? label(key)), element('b', {}, statusValue(item[key], options.zeroAsDash))));
      });
      card.append(list);
      cards.append(card);
    });
    groupEl.append(cards);
    groupsWrap.append(groupEl);
  });

  wrap.append(groupsWrap);
  return wrap;
}

function propertyList(title, rows, options = {}) {
  const wrap = element('div', { class: 'sitrep-property-list' });
  if (text(title)) {
    wrap.append(element('h3', {}, title));
  }
  rows.forEach(([name, value, payload], index) => {
    const row = object(payload);
    const group = element('article', {
      class: 'sitrep-property-group',
      ...evidenceAttrs(row, `${options.section ?? 'property'}.${indexSlug(title)}.${index + 1}`),
      ...sourceAttrs({ ...row, location: name }),
      'data-sitrep-section': options.section,
      'data-location-name': name,
    }, element('h4', {}, element('span', {}, options.titleHeader ?? 'Location'), text(name)));
    const list = element('dl', {}, element('dt', {}, 'Evidence'), element('dd', {}, text(value)));
    group.append(list);
    wrap.append(group);
  });
  return wrap;
}

function gapEvidence(gap, targetName, needs = {}, population = {}, options = {}) {
  const evidence = text(gap.evidence);
  const sourceHubs = array(gap.source_hubs).map((source) => text(source)).filter(Boolean);
  if (isResourceSupplyGap(gap)) {
    const groups = resourceEvidenceGroups(gap, needs, targetName);
    if (groups.length) {
      return resourceEvidenceCards(groups, options);
    }
  }

  if (isPopulationConfidenceGap(gap)) {
    const groups = populationEvidenceGroups(gap, population, targetName);
    if (groups.length) {
      return populationEvidenceCards(groups, options);
    }

    const evidenceGroups = populationEvidenceGroupsFromEvidence(evidence, sourceHubs, targetName);
    if (evidenceGroups.length) {
      return populationEvidenceCards(evidenceGroups, options);
    }
  }

  const routeGroups = routeEvidenceGroups(gap, targetName);
  if (routeGroups.length) {
    return routeEvidenceCards(routeGroups, options);
  }

  if (!evidence || !sourceHubs.length) {
    return evidence ? document.createTextNode(evidence) : null;
  }

  const rows = [];
  sourceHubs.forEach((source, index) => {
    const startNeedle = `${source}:`;
    const start = evidence.indexOf(startNeedle);
    if (start === -1) {
      return;
    }
    let end = evidence.length;
    sourceHubs.slice(index + 1).some((nextSource) => {
      const next = evidence.indexOf(`${nextSource}:`, start + startNeedle.length);
      if (next !== -1) {
        end = next;
        return true;
      }
      return false;
    });
    const value = evidence.slice(start + startNeedle.length, end).trim().replace(/[.\s]+$/u, '');
    if (value) {
      rows.push([shortLocation(source, targetName), value, { source_hub_name: source, evidence: value }]);
    }
  });

  if (!rows.length) {
    return document.createTextNode(evidence);
  }

  if (isPopulationConfidenceGap(gap)) {
    const populationGroups = populationEvidenceGroupsFromSourceRows(rows);
    if (populationGroups.length) {
      return populationEvidenceCards(populationGroups, options);
    }
  }

  const resourceRows = resourceEvidenceRowsFromSourceRows(rows);
  if (resourceRows.length) {
    return resourceEvidenceCards(resourceRows.map((row) => ({
      location: row[0],
      headers: ['Units', 'Note'],
      rows: [[row[1], row[2]]],
      rowPayloads: [{
        ...object(row[3]),
        location: row[0],
        quantity_requested: row[1],
        note: row[2],
      }],
    })), options);
  }

  return propertyList('', rows, { section: 'gaps' });
}

function populationEvidenceRows(rows) {
  const output = [];
  for (const row of rows) {
    const location = text(row[0]);
    const evidence = text(row[1]);
    const match = evidence.match(/^(\d+)\s+current\s+population\/life-safety\s+records?\s+reported:\s*(.+)$/i);
    if (!match) {
      return [];
    }
    output.push([location, match[1], match[2].trim(), object(row[2])]);
  }

  return output;
}

function populationEvidenceGroupsFromSourceRows(rows) {
  const populationRows = populationEvidenceRows(rows);
  if (!populationRows.length) {
    return [];
  }

  return populationRows.map((row) => {
    const location = text(row[0], 'Current Location');
    const reports = Number(row[1]) || 0;
    const notes = text(row[2]);
    const payload = object(row[3]);

    return {
      location,
      rows: [[
        'Population/life-safety records',
        reports,
        reports,
        notes,
      ]],
      rowPayloads: [{
        ...payload,
        location,
        signal: 'Population/life-safety records',
        reports,
        people: reports,
        notes,
        source_hub_name: payload.source_hub_name ?? location,
      }],
    };
  });
}

function populationEvidenceGroupsFromEvidence(evidence, sourceHubs, targetName) {
  const match = text(evidence).match(/^(\d+)\s+current\s+population\/life-safety\s+records?\s+reported:\s*(.+)$/i);
  if (!match) {
    return [];
  }

  let location = 'Current Location';
  if (sourceHubs.length === 1) {
    location = shortLocation(sourceHubs[0], targetName);
  } else if (text(targetName)) {
    location = shortLocation(targetName, null);
  }

  return [{
    location,
    rows: [[
      'Population/life-safety records',
      Number(match[1]),
      Number(match[1]),
      match[2].trim(),
    ]],
    rowPayloads: [{
      location,
      signal: 'Population/life-safety records',
      reports: Number(match[1]),
      people: Number(match[1]),
      notes: match[2].trim(),
      source_hub_name: sourceHubs.length === 1 ? sourceHubs[0] : location,
    }],
  }];
}

function isPopulationConfidenceGap(gap) {
  const category = text(gap.category).toLowerCase();
  const title = text(gap.title).toLowerCase();
  return category.includes('data confidence') && title.includes('population');
}

function populationEvidenceGroups(gap, population, targetName) {
  const sourceHubs = array(gap.source_hubs).map((source) => text(source)).filter(Boolean);
  const allowedLocations = sourceHubs.length
    ? new Set(sourceHubs.map((source) => shortLocation(source, targetName)))
    : null;
  const groups = new Map();
  array(population.population_groups).filter(isObject).forEach((populationGroup) => {
    const signal = text(populationGroup.population_signal, 'Population signal');
    const notes = text(populationGroup.notes);
    const sourceValues = array(populationGroup.source_values).filter(isObject);
    const sources = sourceValues.length ? sourceValues : [{
      source_hub_name: targetName || 'Current Location',
      reports: populationGroup.reports,
      people_or_families: populationGroup.people_or_families,
    }];
    sources.forEach((source) => {
      const sourceName = text(source.source_hub_name, targetName || 'Current Location');
      if (!sourceName) {
        return;
      }

      const location = shortLocation(sourceName, targetName);
      if (allowedLocations && !allowedLocations.has(location)) {
        return;
      }

      const reports = Number(source.reports ?? 0) || 0;
      const peopleOrFamilies = text(source.people_or_families);
      const people = populationPeopleValue(peopleOrFamilies, reports);
      const notesText = populationEvidenceNotes(notes, peopleOrFamilies, populationGroup);
      if (!groups.has(location)) {
        groups.set(location, []);
      }
      groups.get(location).push({
        cells: [signal, reports, people, notesText],
        payload: {
          ...source,
          location,
          population_signal: signal,
          reports,
          people,
          notes: notesText,
        },
      });
    });
  });

  return [...groups.entries()].map(([location, rows]) => ({
    location,
    rows: rows.map((row) => row.cells),
    rowPayloads: rows.map((row) => row.payload),
  })).filter((group) => group.rows.length);
}

function populationPeopleValue(peopleOrFamilies, fallback) {
  const source = text(peopleOrFamilies);
  const peopleMatch = source.match(/(\d+)\s+people\b/i);
  if (peopleMatch) return Number(peopleMatch[1]);
  const personMatch = source.match(/(\d+)\s+persons?\b/i);
  if (personMatch) return Number(personMatch[1]);
  const recordMatch = source.match(/(\d+)\s+records?\b/i);
  if (recordMatch) return Number(recordMatch[1]);
  return fallback;
}

function populationEvidenceNotes(notes, peopleOrFamilies, populationGroup) {
  const parts = [];
  const familyMatch = text(peopleOrFamilies).match(/(\d+)\s+famil(?:y|ies)\b/i);
  if (familyMatch) {
    const count = Number(familyMatch[1]);
    parts.push(`${count} ${count === 1 ? 'family' : 'families'}`);
  }
  const noteSummary = compactNoteList(notes);
  if (noteSummary) {
    parts.push(noteSummary);
  }
  const breakdown = populationBreakdownNote(populationGroup);
  if (breakdown) {
    parts.push(breakdown);
  }
  return [...new Set(parts)].join('; ');
}

function compactNoteList(notes) {
  const output = new Map();
  text(notes).split(/\s*;\s*/u).forEach((fragment) => {
    const value = fragment.trim();
    if (!value) {
      return;
    }

    const key = value.toLowerCase();
    if (!output.has(key)) {
      output.set(key, value);
    }
  });

  return [...output.values()].join('; ');
}

function populationBreakdownNote(populationGroup) {
  const breakdowns = array(populationGroup.breakdowns)
    .filter(isObject)
    .map((breakdown) => ({ label: text(breakdown.breakdown), count: Number(breakdown.count ?? 0) || 0 }))
    .filter((breakdown) => breakdown.label && breakdown.count > 0)
    .map((breakdown) => `${breakdown.count} ${breakdown.label}`);

  return breakdowns.length ? `Overall declared breakdown: ${breakdowns.join(', ')}` : '';
}

function populationEvidenceCards(groups, options = {}) {
  if (groups.length === 1) {
    return evidenceTable(['Signal', 'Reports', 'People', 'Notes'], groups[0].rows, {
      ...options,
      rowPayloads: groups[0].rowPayloads,
    });
  }

  const groupsWrap = element('div', { class: 'sitrep-population-evidence-groups' });
  groups.forEach((group) => {
    const card = element('section', { class: 'sitrep-population-evidence-card' }, element('h4', {}, text(group.location, 'Location')));
    card.append(evidenceTable(['Signal', 'Reports', 'People', 'Notes'], group.rows, {
      ...options,
      rowPayloads: group.rowPayloads,
    }));
    groupsWrap.append(card);
  });

  return groupsWrap;
}

function resourceEvidenceRows(gap, needs = {}) {
  const needRows = resourceNeedRows(array(needs.items));
  if (needRows.length) {
    return {
      headers: ['Resource', 'Category', 'Quantity'],
      rows: needRows.map((row) => [row.resource, row.category, row.quantity_requested]),
      rowPayloads: needRows,
    };
  }

  return resourceCategoryRows(array(gap.resource_categories));
}

function resourceCategoryRows(categories) {
  const rowPayloads = [];

  categories.filter(isObject).forEach((row) => {
    const category = text(row.category, 'Uncategorized');
    const resources = array(row.resources).map((resource) => text(resource)).filter(Boolean);
    const quantity = row.quantity_requested ?? row.quantity ?? '';

    if (!resources.length) {
      rowPayloads.push({
        ...row,
        resource: 'Resource unspecified',
        category,
        quantity_requested: quantity,
        resources: [],
      });
      return;
    }

    resources.forEach((resource) => {
      rowPayloads.push({
        ...row,
        resource,
        category,
        quantity_requested: resources.length === 1 ? quantity : null,
        resources: [resource],
      });
    });
  });

  const sortedPayloads = sortResourceEvidencePayloads(rowPayloads);

  return {
    headers: ['Resource', 'Category', 'Quantity'],
    rows: sortedPayloads.map((row) => [row.resource, row.category, row.quantity_requested ?? '']),
    rowPayloads: sortedPayloads,
  };
}

function resourceNeedRows(items) {
  const grouped = new Map();

  items.filter(isObject).forEach((item) => {
    const resource = text(item.resource);
    if (!resource) {
      return;
    }

    const category = text(item.category, 'Uncategorized');
    const key = `${resource}\u0000${category}`;
    const quantity = Number(item.quantity_requested ?? item.quantity ?? 0) || 0;

    if (!grouped.has(key)) {
      grouped.set(key, {
        ...item,
        resource,
        category,
        quantity_requested: 0,
        resources: [resource],
      });
    }

    grouped.get(key).quantity_requested += quantity;
  });

  return sortResourceEvidencePayloads([...grouped.values()]);
}

function sortResourceEvidencePayloads(rows) {
  return rows.sort((a, b) => (
    text(a.resource).localeCompare(text(b.resource), undefined, { sensitivity: 'base' })
    || text(a.category).localeCompare(text(b.category), undefined, { sensitivity: 'base' })
  ));
}

function isResourceSupplyGap(gap) {
  const type = text(gap.type).toLowerCase();
  const title = text(gap.title).toLowerCase();
  return type === 'open_needs' || title.includes('resource supply');
}

function resourceEvidenceGroups(gap, needs, targetName) {
  const canonicalGroups = canonicalResourceEvidenceGroups(gap, targetName);
  if (canonicalGroups.length) {
    return canonicalGroups;
  }

  const sourceHubs = array(gap.source_hubs).map((source) => text(source)).filter(Boolean);
  if (sourceHubs.length) {
    const sourceGroups = resourceEvidenceGroupsFromNeeds(needs, targetName, sourceHubs);
    if (sourceGroups.length) {
      return sourceGroups;
    }
  }

  const evidence = resourceEvidenceRows(gap, needs);
  if (evidence.rows.length) {
    return [{
      location: resourceEvidenceLocation(gap, targetName),
      headers: evidence.headers,
      rows: evidence.rows,
      rowPayloads: evidence.rowPayloads.map((row) => ({
        ...row,
        location: resourceEvidenceLocation(gap, targetName),
      })),
    }];
  }

  return [];
}

function canonicalResourceEvidenceGroups(gap, targetName) {
  const groups = new Map();
  array(gap.resource_needs).filter(isObject).forEach((row) => {
    if (row.kind !== 'resource_need' || !row.resource_type_id) {
      return;
    }

    const location = shortLocation(text(row.location_name ?? row.source_hub_name, 'Current Location'), targetName);
    if (!groups.has(location)) {
      groups.set(location, {
        location,
        headers: ['Resource', 'Category', 'Quantity'],
        rows: [],
        rowPayloads: [],
      });
    }

    const quantity = text(row.quantity ?? row.quantity_requested ?? 0);
    const unit = text(row.unit_label);
    const group = groups.get(location);
    group.rows.push([
      text(row.resource_type_name ?? row.resource, 'Resource'),
      text(row.resource_type_category_name ?? row.category, 'Uncategorized'),
      [quantity, unit].filter(Boolean).join(' '),
    ]);
    group.rowPayloads.push({
      ...row,
      location,
      category: text(row.resource_type_category_name ?? row.category, 'Uncategorized'),
      resource: text(row.resource_type_name ?? row.resource, 'Resource'),
      quantity_requested: row.quantity_requested ?? row.quantity ?? 0,
    });
  });

  return [...groups.values()].filter((group) => group.rows.length);
}

function resourceEvidenceGroupsFromNeeds(needs, targetName, sourceHubs) {
  const locations = new Map();
  const allowedLocations = new Set(sourceHubs.map((source) => shortLocation(source, targetName)));
  array(needs.items).filter(isObject).forEach((item) => {
    const resource = text(item.resource);
    if (!resource) {
      return;
    }

    const category = text(item.category, 'Uncategorized');
    array(item.sources).filter(isObject).forEach((source) => {
      const sourceName = text(source.source_hub_name);
      if (!sourceName) {
        return;
      }

      const location = shortLocation(sourceName, targetName);
      if (!allowedLocations.has(location)) {
        return;
      }
      const quantity = Number(source.quantity_requested ?? 0) || 0;
      if (!locations.has(location)) {
        locations.set(location, new Map());
      }
      const resources = locations.get(location);
      const key = `${resource}\u0000${category}`;
      if (!resources.has(key)) {
        resources.set(key, {
          resource,
          category,
          quantity: 0,
          source,
          source_hub_name: sourceName,
          location,
        });
      }
      const row = resources.get(key);
      row.quantity += quantity;
    });
  });

  return [...locations.entries()].map(([location, resources]) => ({
    location,
    headers: ['Resource', 'Category', 'Quantity'],
    rows: sortResourceEvidencePayloads([...resources.values()]).map((row) => [
      row.resource,
      row.category,
      row.quantity,
    ]),
    rowPayloads: sortResourceEvidencePayloads([...resources.values()]).map((row) => ({
      ...object(row.source),
      resource: row.resource,
      category: row.category,
      quantity_requested: row.quantity,
      resources: [row.resource],
      source_hub_name: row.source_hub_name,
      location: row.location,
    })),
  })).filter((group) => group.rows.length);
}

function resourceEvidenceRowsFromSourceRows(rows) {
  const output = [];
  for (const row of rows) {
    const location = text(row[0]);
    const evidence = text(row[1]);
    const match = evidence.match(/^(\d+)\s+requested\s+resource\s+units?\s+remain\s+tied\s+to\s+active\/deferred\s+incidents\.?\s*(.*)$/i);
    if (!match) {
      return [];
    }
    output.push([location, match[1], match[2].trim()]);
  }

  return output;
}

function resourceEvidenceCards(groups, options = {}) {
  if (groups.length === 1) {
    return evidenceTable(groups[0].headers, groups[0].rows, {
      ...options,
      rowPayloads: groups[0].rowPayloads,
    });
  }

  const groupsWrap = element('div', { class: 'sitrep-resource-evidence-groups' });
  groups.forEach((group) => {
    const card = element('section', { class: 'sitrep-resource-evidence-card' }, element('h4', {}, text(group.location, 'Location')));
    card.append(evidenceTable(group.headers, group.rows, {
      ...options,
      rowPayloads: group.rowPayloads,
    }));
    groupsWrap.append(card);
  });

  return groupsWrap;
}

function resourceEvidenceLocation(gap, targetName) {
  const sourceHubs = array(gap.source_hubs).map((source) => text(source)).filter(Boolean);
  if (sourceHubs.length === 1) {
    return shortLocation(sourceHubs[0], targetName);
  }

  return sourceHubs.length ? 'All Locations' : 'Current Location';
}

function routeEvidenceGroups(gap, targetName = null) {
  const groups = new Map();
  array(gap.items).filter(isObject).forEach((item) => {
    const route = text(item.route_location);
    if (!route) {
      return;
    }

    const location = shortLocation(text(item.source_hub_name ?? item.location, 'Location'), targetName);
    if (!groups.has(location)) {
      groups.set(location, []);
    }
    groups.get(location).push({
      cells: [
        route,
        text(item.status, 'Reported'),
        text(item.obstruction_type),
        text(item.cleared),
      ],
      payload: {
        ...item,
        location,
        route_location: route,
      },
    });
  });

  return [...groups.entries()].map(([location, rows]) => ({
    location,
    rows: rows.map((row) => row.cells),
    rowPayloads: rows.map((row) => row.payload),
  })).filter((group) => group.rows.length);
}

function routeEvidenceCards(groups, options = {}) {
  if (groups.length === 1) {
    return evidenceTable(['Route', 'Status', 'Obstruction', 'Cleared'], groups[0].rows, {
      ...options,
      rowPayloads: groups[0].rowPayloads,
    });
  }

  const groupsWrap = element('div', { class: 'sitrep-route-evidence-groups' });
  groups.forEach((group) => {
    const card = element('section', { class: 'sitrep-route-evidence-card' }, element('h4', {}, text(group.location, 'Location')));
    card.append(evidenceTable(['Route', 'Status', 'Obstruction', 'Cleared'], group.rows, {
      ...options,
      rowPayloads: group.rowPayloads,
    }));
    groupsWrap.append(card);
  });

  return groupsWrap;
}

function evidenceTable(headers, rows, options = {}) {
  const tableEl = element('table', { class: 'sitrep-table sitrep-evidence-table' });
  const rowContexts = rows.map((row, index) => {
    const payload = object(options.rowPayloads?.[index] ?? {});
    return {
      sitrep: options.sitrep ?? null,
      section: options.section ?? 'gaps',
      gap: options.gap ?? null,
      row: payload,
      rowIndex: index,
      evidenceRef: evidenceRef(payload, `${options.section ?? 'gaps'}.evidence.${index + 1}`),
    };
  });
  const hasRowActions = rowContexts.some((context) => applicableRowActions(context, options.rowActions).length > 0);

  tableEl.append(element('thead', {}, element('tr', {},
    ...headers.map((header) => element('th', {}, header)),
    hasRowActions ? element('th', { class: 'sitrep-row-actions-heading' }, 'Actions') : null,
  )));
  tableEl.append(element('tbody', {}, ...rows.map((row, index) => {
    const payload = object(options.rowPayloads?.[index] ?? {});
    const rowAttrs = hasRowActions
      ? {
        ...evidenceAttrs(payload, rowContexts[index].evidenceRef),
        ...sourceAttrs(payload),
        'data-sitrep-section': options.section ?? 'gaps',
      }
      : {};

    return element('tr', rowAttrs,
    ...row.map((cell) => element('td', {}, text(cell, '-'))),
    hasRowActions ? element('td', {}, renderRowActions(rowContexts[index], options.rowActions)) : null);
  })));
  return tableEl;
}

function statusValue(value, zeroAsDash = false) {
  if (zeroAsDash && Number(value) === 0) {
    return '-';
  }
  return isEmptyPropertyValue(value) ? '-' : text(value);
}

function propertyValue(header, value) {
  const output = text(value);
  if (!output) {
    return document.createTextNode('-');
  }
  if (header === 'Main Signals') {
    return textOrList(output);
  }
  if (output.includes(';')) {
    return textOrList(output);
  }
  return document.createTextNode(output);
}

function isEmptyPropertyValue(value) {
  return value === null || value === undefined || text(value) === '';
}

function emptySection(sectionNode) {
  sectionNode.replaceChildren();
  return element('span');
}

function evidenceAttrs(item, fallbackRef) {
  const payload = object(item);
  return {
    'data-sitrep-evidence-ref': evidenceRef(payload, fallbackRef),
  };
}

function evidenceRef(item, fallbackRef) {
  const payload = object(item);
  return text(
    payload.evidence_ref
      ?? payload.evidenceRef
      ?? payload.ref
      ?? array(payload.evidence_refs)[0],
    fallbackRef,
  );
}

function sourceAttrs(source) {
  const payload = object(source);
  return {
    'data-source-hub-id': payload.source_hub_id ?? payload.hub_id,
    'data-source-relay-hub-id': payload.source_relay_hub_id ?? payload.relay_hub_id,
    'data-location-name': payload.source_hub_name ?? payload.location ?? payload.area ?? payload.name,
  };
}

function sitrepIdentity(sitrep) {
  const sourceSnapshot = section(sitrep, 'source_snapshot');
  const generation = object(sourceSnapshot.generation);
  const target = object(sourceSnapshot.target);
  const hubNode = sourceHubNode(sourceSnapshot);
  const hub = object(hubNode.snapshot ?? hubNode);
  const deployment = text(hub.deployment);
  const hubName = text(hub.name);
  let title = text(sitrep.title, 'PBB SITREP').replace(/\s+-\s+\d{4}-\d{2}-\d{2}\s*$/, '');

  if (generation.type === 'consolidated' && Object.keys(target).length) {
    const targetLevel = text(target.level);
    if (targetLevel) {
      title = `${label(targetLevel).replaceAll('-', ' ')} SITREP`;
    }

    return {
      title: title || 'Consolidated SITREP',
      hub: target.name ? formatHubLabel(target.name) : null,
      period: periodLabel(sitrep),
    };
  }

  if (deployment && hubName) {
    title = `${label(deployment).replaceAll('-', ' ')} SITREP`;
  }

  return {
    title: title || 'Daily SITREP',
    hub: hubName ? formatHubLabel(hubName) : null,
    period: periodLabel(sitrep),
  };
}

function sourceTargetName(sourceSnapshot) {
  const target = object(sourceSnapshot.target);
  if (text(target.name)) {
    return text(target.name);
  }
  const hubNode = sourceHubNode(sourceSnapshot);
  const hub = object(hubNode.snapshot ?? hubNode);
  return text(hub.name) || null;
}

function sourceHubNode(sourceSnapshot) {
  const snapshot = object(sourceSnapshot);
  if (isObject(snapshot.hub_node)) {
    return snapshot.hub_node;
  }
  const hubNodes = array(snapshot.hub_nodes).filter(isObject);
  return hubNodes[0] ?? {};
}

function formatHubLabel(value) {
  return text(value)
    .split(',')
    .map((part) => part.trim())
    .filter(Boolean)
    .map((part) => part.toLowerCase().replace(/\b\p{L}/gu, (char) => char.toUpperCase()))
    .join(', ');
}

function formatDeploymentLabel(value) {
  const output = text(value).replaceAll('_', ' ').replaceAll('-', ' ');
  return output ? output.toLowerCase().replace(/\b\p{L}/gu, (char) => char.toUpperCase()) : '';
}

function formatSdkLabel(value) {
  return text(value, 'SITREP consolidator').replaceAll('_', ' ').replaceAll('-', ' ');
}

function shortLocation(location, targetName) {
  const original = text(location);
  let output = original;
  const target = text(targetName);
  if (target) {
    const suffix = new RegExp(`,\\s*${escapeRegExp(target)}$`, 'i');
    output = output.replace(suffix, '');
  }
  output = output.replace(/^Barangay\s+/i, '').trim();
  return output || original;
}

function firstPrimary(uplinks) {
  const valid = array(uplinks).filter(isObject);
  return valid.find((uplink) => uplink.is_primary) ?? valid[0] ?? null;
}

function findEvidenceNode(container, ref) {
  const needle = text(ref);
  if (!needle) {
    return null;
  }
  return container.querySelector(`[data-sitrep-evidence-ref="${cssEscape(needle)}"]`);
}

function highlightEvidenceNode(container, ref) {
  const node = findEvidenceNode(container, ref);
  if (!node) {
    return null;
  }
  node.classList.add('is-sitrep-highlighted');
  node.setAttribute('data-sitrep-highlighted', 'true');
  return node;
}

function clearHighlight(container) {
  container.querySelectorAll('[data-sitrep-highlighted="true"]').forEach((node) => {
    node.classList.remove('is-sitrep-highlighted');
    node.removeAttribute('data-sitrep-highlighted');
  });
}

function applySourceFilter(container, sourceHubId) {
  const selected = text(sourceHubId);
  container.classList.toggle('is-source-filtered', Boolean(selected));
  container.querySelectorAll('[data-source-hub-id]').forEach((node) => {
    const matches = !selected || node.getAttribute('data-source-hub-id') === selected;
    node.hidden = !matches;
    node.classList.toggle('is-source-filtered-out', !matches);
  });
}

function interactionPayload(node, event) {
  const dataset = { ...node.dataset };
  const ref = dataset.sitrepEvidenceRef || null;
  const sourceHubId = dataset.sourceHubId || null;
  const concernGroup = dataset.concernGroup || null;
  let type = 'evidence';
  if (!ref && sourceHubId) {
    type = 'source';
  } else if (!ref && !sourceHubId && concernGroup) {
    type = 'concern';
  }

  return {
    type,
    ref,
    sourceHubId,
    sourceRelayHubId: dataset.sourceRelayHubId || null,
    section: dataset.sitrepSection || node.closest('[data-sitrep-section]')?.dataset.sitrepSection || null,
    locationName: dataset.locationName || null,
    concernGroup,
    payload: dataset,
    event,
  };
}

function functionOrNull(value) {
  return typeof value === 'function' ? value : null;
}

function cssEscape(value) {
  if (globalThis.CSS && typeof globalThis.CSS.escape === 'function') {
    return globalThis.CSS.escape(String(value));
  }
  return String(value).replace(/["\\]/g, '\\$&');
}

function indexSlug(value) {
  return text(value, 'item')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    || 'item';
}

function empty(message) {
  return element('p', { class: 'sitrep-empty' }, message);
}

function section(payload, key) {
  const value = object(payload[key]);
  return isObject(value.rollup) ? value.rollup : value;
}

function object(value) {
  return isObject(value) ? value : {};
}

function array(value) {
  return Array.isArray(value) ? value : [];
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function element(tag, attrs = {}, ...children) {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([key, value]) => {
    if (value === false || value === null || value === undefined || value === '') {
      return;
    }
    if (key === 'class') {
      node.className = String(value);
    } else {
      node.setAttribute(key, String(value));
    }
  });
  children.flat().forEach((child) => {
    if (child === null || child === undefined || child === false) {
      return;
    }
    node.append(child instanceof Node ? child : document.createTextNode(String(child)));
  });
  return node;
}

function text(value, fallback = '') {
  const output = value === null || value === undefined ? fallback : String(value);
  return output.trim() === '' ? fallback : output;
}

function textOrList(value) {
  if (Array.isArray(value)) {
    const list = element('ul', { class: 'sitrep-property-bullets' });
    value
      .flatMap((item) => text(item).split(';'))
      .map((item) => item.trim())
      .filter(Boolean)
      .forEach((item) => list.append(element('li', {}, item)));
    return list;
  }
  if (typeof value === 'string' && value.includes(';')) {
    const parts = value.split(';').map((part) => part.trim()).filter(Boolean);
    if (parts.length > 1) {
      return textOrList(parts);
    }
  }
  return text(value, '-');
}

function listText(value) {
  return Array.isArray(value) ? value.filter(Boolean).join(', ') : text(value);
}

function joined(parts) {
  return parts.map((part) => text(part)).filter(Boolean).join(' · ');
}

function inlineParts(parts) {
  return joined(parts);
}

function classNames(...classes) {
  return classes.filter(Boolean).join(' ');
}

function label(key) {
  return key.replaceAll('_', ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function capitalize(value) {
  const output = text(value);
  return output ? output.charAt(0).toUpperCase() + output.slice(1) : output;
}

function number(value) {
  const parsed = Number(value ?? 0);
  return Number.isFinite(parsed) ? new Intl.NumberFormat('en').format(parsed) : text(value, '0');
}

function formatDate(value) {
  if (!value) {
    return '';
  }
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return text(value);
  }
  return new Intl.DateTimeFormat('en', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(date);
}

function periodLabel(sitrep) {
  const start = formatDate(sitrep.period_started_at);
  const end = formatDate(sitrep.period_ended_at);
  return start && end ? `${start} to ${end}` : '';
}

function truthy(value) {
  return value === true || String(value).toLowerCase() === 'yes' || String(value).toLowerCase() === 'true';
}

function escapeRegExp(value) {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}
