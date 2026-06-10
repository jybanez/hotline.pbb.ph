import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

const moduleSource = await readFile(new URL('../../packages/pbb-sitrep-viewer/js/sitrep-viewer.js', import.meta.url), 'utf8');
const moduleUrl = 'http://sitrep-viewer.test/js/sitrep-viewer.js';
const fixture = JSON.parse(await readFile(new URL('../../packages/pbb-sitrep-viewer/demo/input/sitrep.json', import.meta.url), 'utf8'));

const browser = await chromium.launch();
const page = await browser.newPage();

try {
  await page.route('http://sitrep-viewer.test/js/sitrep-viewer.js', (route) => route.fulfill({
    status: 200,
    contentType: 'text/javascript',
    body: moduleSource,
  }));
  await page.route('http://sitrep-viewer.test/', (route) => route.fulfill({
    status: 200,
    contentType: 'text/html',
    body: `
      <!doctype html>
      <html>
        <body>
          <div id="viewer-a"></div>
          <div id="viewer-b"></div>
        </body>
      </html>
    `,
  }));
  await page.goto('http://sitrep-viewer.test/');

  await page.addScriptTag({
    type: 'module',
    content: `
      import { createSitrepViewer, renderSitrep, renderSitrepSection, sectionNames } from '${moduleUrl}';
      window.pbbSitrepViewerTest = { createSitrepViewer, renderSitrep, renderSitrepSection, sectionNames };
    `,
  });
  await page.waitForFunction(() => window.pbbSitrepViewerTest);

  const result = await page.evaluate((sitrep) => {
    const api = window.pbbSitrepViewerTest;
    const interactiveSitrep = structuredClone(sitrep);
    const interactiveSummary = interactiveSitrep.summary?.rollup ?? interactiveSitrep.summary;
    interactiveSummary.gap_cards ??= [];
    interactiveSummary.gap_cards[0] ??= {
      label: 'People at Risk',
      value: 0,
      note: 'No current people-at-risk signal.',
    };
    interactiveSummary.gap_cards[0].evidence_ref = 'summary.gap_cards.people_at_risk';
    interactiveSummary.gap_cards[0].source_values = [
      {
        evidence_ref: 'summary.gap_cards.people_at_risk.guadalupe',
        source_hub_id: '12',
        source_relay_hub_id: '072217029',
        source_hub_name: 'Guadalupe',
        value: '2 people',
      },
      {
        evidence_ref: 'summary.gap_cards.people_at_risk.apas',
        source_hub_id: '13',
        source_relay_hub_id: '072217030',
        source_hub_name: 'Apas',
        value: '10 people',
      },
    ];
    const altered = {
      ...sitrep,
      title: 'Second SITREP',
      situation: {
        ...sitrep.situation,
        narrative: '<script>alert(1)</script>',
        executive_assessment: '<script>alert(1)</script>',
      },
    };

    const viewerA = api.createSitrepViewer(document.querySelector('#viewer-a'), {
      sitrep: interactiveSitrep,
      layout: 'compact',
      sections: ['header', 'summary'],
      preview: true,
      onInteraction: (payload) => {
        window.interactions ??= [];
        window.interactions.push({
          type: payload.type,
          ref: payload.ref,
          sourceHubId: payload.sourceHubId,
          sourceRelayHubId: payload.sourceRelayHubId,
          section: payload.section,
          locationName: payload.locationName,
          concernGroup: payload.concernGroup,
        });
      },
      onEvidenceClick: (payload) => {
        window.evidenceClicks ??= [];
        window.evidenceClicks.push(payload.ref);
      },
      onSourceClick: (payload) => {
        window.sourceClicks ??= [];
        window.sourceClicks.push(payload.sourceHubId);
      },
    });
    const viewerB = api.createSitrepViewer(document.querySelector('#viewer-b'), {
      sitrep: altered,
      layout: 'section',
      section: 'population',
    });

    const before = {
      aText: document.querySelector('#viewer-a').textContent,
      bText: document.querySelector('#viewer-b').textContent,
      aHtml: document.querySelector('#viewer-a').innerHTML,
      bHtml: document.querySelector('#viewer-b').innerHTML,
      aInstance: document.querySelector('#viewer-a').dataset.sitrepViewerInstance,
      bInstance: document.querySelector('#viewer-b').dataset.sitrepViewerInstance,
      sections: api.sectionNames(),
      sectionText: api.renderSitrepSection(sitrep, 'needs', { layout: 'compact' }).textContent,
    };

    const evidenceNode = viewerA.findEvidence('summary.gap_cards.people_at_risk.guadalupe');
    const sectionNode = document.querySelector('#viewer-a [data-sitrep-section="summary"]');
    evidenceNode.click();
    const highlightedNode = viewerA.highlightEvidence('summary.gap_cards.people_at_risk.guadalupe', { scroll: false });
    const afterHighlight = {
      foundEvidence: Boolean(evidenceNode),
      sectionTagged: Boolean(sectionNode),
      highlighted: highlightedNode?.dataset.sitrepHighlighted ?? null,
      interactions: window.interactions ?? [],
      evidenceClicks: window.evidenceClicks ?? [],
      sourceClicks: window.sourceClicks ?? [],
    };
    viewerA.clearHighlight();
    viewerA.filterBySource('12');
    const sourceNodes = [...document.querySelectorAll('#viewer-a [data-source-hub-id]')].map((node) => ({
      sourceHubId: node.dataset.sourceHubId,
      hidden: node.hidden,
    }));
    const filteredState = viewerA.getState().sourceFilter;
    viewerA.clearSourceFilter();
    const sourceFilterCleared = [...document.querySelectorAll('#viewer-a [data-source-hub-id]')].every((node) => !node.hidden);

    viewerB.setSection('summary');
    const afterSectionChange = {
      text: document.querySelector('#viewer-b').textContent,
      html: document.querySelector('#viewer-b').innerHTML,
    };

    viewerA.destroy();
    const afterDestroy = {
      aText: document.querySelector('#viewer-a').textContent,
      aInstance: document.querySelector('#viewer-a').dataset.sitrepViewerInstance ?? null,
      bText: document.querySelector('#viewer-b').textContent,
    };

    return { before, afterHighlight, sourceNodes, filteredState, sourceFilterCleared, afterSectionChange, afterDestroy };
  }, fixture);

  const canonicalResult = await page.evaluate(() => {
    const api = window.pbbSitrepViewerTest;
    const canonical = {
      title: 'City SITREP - CEBU CITY, CEBU',
      coverage_area: 'CEBU CITY, CEBU',
      location_count: 2,
      generated_at: '2026-06-07T04:39:00+08:00',
      status: 'draft',
      visibility: 'private',
      alert_level: 'Critical',
      summary: {
        rollup: {
          headline: 'Consolidated source reports.',
          gap_cards: [{
            label: 'People at Risk',
            value: 61,
            note: 'Leadership signal.',
            source_values: [{ source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu', label: '2 people', source_hub_id: '12' }],
          }],
          accomplishment_cards: [{
            label: 'People Helped',
            value: 8,
            note: 'Assisted people.',
            source_values: [{ source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu', label: '2 patient records addressed', source_hub_id: '12' }],
          }],
        },
      },
      situation: {
        rollup: {
          executive_assessment: 'Executive assessment.',
          current_operating_picture: {
            open_reports: 40,
            active_reports: 15,
            deferred_reports: 25,
            current_assignments: 40,
            current_resource_units: 256,
          },
          locations: [{ area: 'Guadalupe', alert_level: 'Elevated', count: 8 }],
          incident_types: [{ type: 'Flood', location_count: 2, count: 9 }],
          concern_groups: [{ concern: 'Flood', open_reports: 9, areas: ['Guadalupe'], main_signals: '2 limited access points; Types: Flood; Key needs: Rescue Team', current_assignments: 9, resource_units: 20 }],
          decision_points: [{ title: 'Life safety', body: 'Prioritize source hubs.' }],
        },
      },
      damage: { rollup: { damage_groups: [{ damage_type: 'Infrastructure damage', reports: 2, severity_signal: 'major', affected_assets: ['road'] }], confidence_note: 'Damage validation remains app-owned.' } },
      population: {
        rollup: {
          people_at_risk: 61,
          citizens_assisted: 8,
          record_count: 42,
          numeric_total_note: 'Population fields may overlap.',
          confidence_note: 'Population validation remains app-owned.',
          population_groups: [{
            population_signal: 'People injured',
            reports: 3,
            people_or_families: '3 people',
            notes: 'Details reported; verification required.',
            location_count: 2,
            source_values: [{
              source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu',
              reports: 1,
              people_or_families: '1 person',
            }, {
              source_hub_name: 'Barangay Apas, Cebu City, Cebu',
              reports: 2,
              people_or_families: '2 people',
            }],
          }, {
            population_signal: 'Patient or injured person',
            reports: 3,
            people_or_families: '3 people',
            notes: 'serious; guarded; stable; serious; stable',
            location_count: 1,
            source_values: [{
              source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu',
              reports: 3,
              people_or_families: '3 people',
            }],
          }, {
            population_signal: 'Affected family',
            reports: 2,
            people_or_families: '3 families / 15 people',
            notes: '1 displacement signal',
            location_count: 2,
            breakdowns: [{ breakdown: 'Children', count: 6, location_count: 2 }, { breakdown: 'Pregnant', count: 2, location_count: 2 }],
            source_values: [{
              source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu',
              reports: 1,
              people_or_families: '1 family / 5 people',
            }, {
              source_hub_name: 'Barangay Apas, Cebu City, Cebu',
              reports: 1,
              people_or_families: '2 families / 10 people',
            }],
          }],
        },
      },
      actions: {
        rollup: {
          deployment_groups: [{ category: 'Rescue', team: 'Rescue Team', status_counts: { requested: 1, assigned: 2, accepted: 0, en_route: 1, on_scene: 3 } }],
          timing_rows: [{ team: 'Rescue Team', incident_id: 123, current_status: 'On Scene', elapsed_time: '15m' }],
        },
      },
      needs: {
        rollup: {
          category_groups: [{ category: 'Rescue and Extraction', location_count: 2, quantity_requested: 17, resources: ['Body Harness'] }],
          items: [{
            resource: 'Body Harness',
            category: 'Rescue and Extraction',
            location_count: 2,
            quantity_requested: 17,
            incident_count: 4,
            sources: [{
              source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu',
              quantity_requested: 7,
            }, {
              source_hub_name: 'Barangay Apas, Cebu City, Cebu',
              quantity_requested: 10,
            }],
          }, {
            resource: 'Structural Assessment Team',
            category: 'Search and Damage Assessment',
            location_count: 2,
            quantity_requested: 13,
            incident_count: 3,
            sources: [{
              source_hub_name: 'Barangay Guadalupe, Cebu City, Cebu',
              quantity_requested: 5,
            }, {
              source_hub_name: 'Barangay Apas, Cebu City, Cebu',
              quantity_requested: 8,
            }],
          }],
        },
      },
      gaps: {
        rollup: {
          intro: 'Gap rollup.',
          items: [{
            type: 'counting_scope',
            category: 'Counting Rule',
            title: 'Closed and discarded reports are not current pressure',
            body: 'This keeps the operating picture focused on reports that still need leadership visibility.',
            evidence: '4 resolved reports were treated as addressed history.',
            confidence_note: 'Resolved reports cannot carry pending entries under current Hotline rules.',
          }, {
            category: 'Data Confidence',
            title: 'Population figures require verification',
            decision_relevance: 'Population fields require validation before operational use.',
            evidence: 'Barangay Guadalupe, Cebu City, Cebu: 6 current population/life-safety records reported: People injured, Patient or injured person, Evacuation Needed. Barangay Apas, Cebu City, Cebu: 10 current population/life-safety records reported: Patients, Patient or injured person, Evacuation Needed, Estimated people involved.',
            source_hubs: [
              'Barangay Guadalupe, Cebu City, Cebu',
              'Barangay Apas, Cebu City, Cebu',
            ],
          }, {
            type: 'open_needs',
            category: 'Operational constraint',
            title: 'Resource supply not confirmed',
            decision_relevance: 'Leadership should verify whether requested resources are available.',
            evidence: '17 requested resource units remain tied to active/deferred incidents. Category detail is shown in Current Resource Posture.',
            resource_categories: [{
              category: 'Rescue and Extraction',
              quantity_requested: 17,
              resources: ['Body Harness', 'Rescue Boat'],
            }],
          }, {
            type: 'open_needs',
            category: 'Operational constraint',
            title: 'Resource supply not confirmed',
            decision_relevance: 'Leadership should verify whether requested resources are available.',
            evidence: 'Barangay Guadalupe, Cebu City, Cebu: 49 requested resource units remain tied to active/deferred incidents. Category detail is shown in Current Resource Posture. Barangay Apas, Cebu City, Cebu: 58 requested resource units remain tied to active/deferred incidents. Category detail is shown in Current Resource Posture.',
            source_hubs: [
              'Barangay Guadalupe, Cebu City, Cebu',
              'Barangay Apas, Cebu City, Cebu',
            ],
          }],
        },
      },
      source_snapshot: {
        rollup: {
          generation: { type: 'consolidated', prepared_by_label: 'System Generated' },
          target: { level: 'city', name: 'Cebu City, Cebu' },
          hub_node: { available: true, snapshot: { name: 'Cebu City, Cebu', deployment: 'city', hub_id: '11' } },
        },
      },
      privacy_redactions: { inherited: true, note: 'Preserved.' },
      data_quality: {
        global_note: 'Verify before operational use.',
        counting_notes: [{
          type: 'counting_scope',
          title: 'Resolved and discarded reports are excluded from current pressure',
          body: 'This keeps the operating picture focused on reports that still need leadership visibility.',
          evidence: '5 resolved reports were treated as addressed history; 2 discarded reports were excluded from posture, demand, and severity.',
          confidence_note: 'Resolved reports cannot carry pending entries under current Hotline rules.',
        }],
      },
    };

    const node = api.renderSitrep(canonical, { layout: 'compact' });
    const documentNode = api.renderSitrep(canonical, { layout: 'document' });
    const demoDocumentNode = api.renderSitrep(canonical, {
      layout: 'document',
      sections: ['header', 'summary', 'situation', 'damage', 'population', 'actions', 'needs', 'gaps', 'footer'],
    });
    const groupedConcernTable = [...documentNode.querySelectorAll('.sitrep-table-card')]
      .find((item) => item.querySelector('h3')?.textContent === 'Grouped Current Concerns');
    const gapsNode = api.renderSitrepSection(canonical, 'gaps', { layout: 'document' });
    const singleLocation = structuredClone(canonical);
    singleLocation.location_count = 1;
    singleLocation.source_snapshot.rollup = {
      generation: { type: 'manual', prepared_by_label: 'System Generated' },
      hub_node: { available: true, snapshot: { name: 'Barangay Guadalupe, Cebu City, Cebu', deployment: 'barangay', hub_id: '12' } },
    };
    singleLocation.gaps.rollup.items = [{
      category: 'Data Confidence',
      title: 'Population figures require verification',
      decision_relevance: 'Population fields require validation before operational use.',
      evidence: '6 current population/life-safety records reported: People injured, Patient or injured person, Estimated people involved, Evacuation Needed.',
      confidence_note: 'Family, shelter, evacuation, patient, missing-person, and affected-person fields may overlap.',
    }];
    const singleGapsNode = api.renderSitrepSection(singleLocation, 'gaps', { layout: 'document' });
    const text = node.textContent.replace(/\s+/g, ' ').trim();
    return {
      h1: node.querySelector('h1')?.textContent,
      text,
      h2Titles: [...node.querySelectorAll('h2')].map((item) => item.textContent),
      tableTitles: [...node.querySelectorAll('.sitrep-table-card > h3')].map((item) => item.textContent),
      sectionEyebrows: [...node.querySelectorAll('.sitrep-eyebrow')].map((item) => item.textContent),
      usesOfficialTableClass: node.querySelectorAll('.sitrep-table').length > 0,
      usesLegacyJsTableClass: node.querySelectorAll('.sitrep-simple-table, .sitrep-section-label').length > 0,
      sourceNames: [...node.querySelectorAll('.sitrep-card-sources strong')].map((item) => item.textContent),
      mainSignalsBulletCount: node.querySelectorAll('.sitrep-property-bullets li').length,
      documentConcernBulletCount: groupedConcernTable?.querySelectorAll('.sitrep-property-bullets li').length ?? null,
      documentConcernText: groupedConcernTable?.textContent.replace(/\s+/g, ' ').trim() ?? '',
      gapsText: gapsNode.textContent.replace(/\s+/g, ' ').trim(),
      gapTableTitles: [...gapsNode.querySelectorAll('.sitrep-table-card > h3')].map((item) => item.textContent),
      populationEvidenceHeaders: [...gapsNode.querySelectorAll('.sitrep-table-card th')].map((item) => item.textContent),
      populationEvidenceRows: [...gapsNode.querySelectorAll('.sitrep-table-card tbody tr')]
        .map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent)),
      locationPopulationEvidenceCards: [...gapsNode.querySelectorAll('.sitrep-population-evidence-card')]
        .map((card) => ({
          title: card.querySelector('h4')?.textContent,
          rows: [...card.querySelectorAll('tbody tr')].map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent)),
        })),
      singlePopulationEvidenceCards: singleGapsNode.querySelectorAll('.sitrep-population-evidence-card').length,
      singlePopulationEvidenceRows: [...singleGapsNode.querySelectorAll('.sitrep-evidence-table tbody tr')]
        .map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent)),
      singleGapsText: singleGapsNode.textContent.replace(/\s+/g, ' ').trim(),
      hasResourceEvidenceWrapper: [...gapsNode.querySelectorAll('.sitrep-table-card > h3')]
        .some((item) => item.textContent === 'Resource Evidence'),
      locationResourceEvidenceCards: [...gapsNode.querySelectorAll('.sitrep-resource-evidence-card')]
        .map((card) => ({
          title: card.querySelector('h4')?.textContent,
          rows: [...card.querySelectorAll('tbody tr')].map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent)),
        })),
      directResourceEvidenceRows: [...gapsNode.querySelectorAll('.sitrep-evidence-table tbody tr')]
        .map((row) => [...row.querySelectorAll('td')].map((cell) => cell.textContent)),
      demoSectionEyebrows: [...demoDocumentNode.querySelectorAll('.sitrep-eyebrow')].map((item) => item.textContent),
      actionValues: [...node.querySelectorAll('.sitrep-team-matrix td, .sitrep-assignment-matrix td')].map((item) => item.textContent.trim()),
      countingNotesTitle: node.querySelector('.sitrep-counting-notes strong')?.textContent,
    };
  });

  assert.match(result.before.aText, /Executive Situation Assessment/);
  assert.match(result.before.aText, /PBB Hotline Periodic SITREP/);
  assert.match(result.before.bText, /Affected People/);
  assert.doesNotMatch(result.before.bText, /Executive Situation Assessment/);
  assert.notEqual(result.before.aInstance, result.before.bInstance);
  assert.match(result.before.aHtml, /is-layout-compact/);
  assert.match(result.before.bHtml, /is-layout-section/);
  assert.deepEqual(result.before.sections.slice(0, 4), ['header', 'summary', 'situation', 'damage']);
  assert.match(result.before.sectionText, /Current Resource Posture/);
  assert.equal(result.afterHighlight.foundEvidence, true);
  assert.equal(result.afterHighlight.sectionTagged, true);
  assert.equal(result.afterHighlight.highlighted, 'true');
  assert.deepEqual(result.afterHighlight.evidenceClicks, ['summary.gap_cards.people_at_risk.guadalupe']);
  assert.deepEqual(result.afterHighlight.sourceClicks, ['12']);
  assert.deepEqual(result.afterHighlight.interactions[0], {
    type: 'evidence',
    ref: 'summary.gap_cards.people_at_risk.guadalupe',
    sourceHubId: '12',
    sourceRelayHubId: '072217029',
    section: 'summary',
    locationName: 'Guadalupe',
    concernGroup: null,
  });
  assert.equal(result.filteredState, '12');
  assert.equal(result.sourceNodes.some((node) => node.sourceHubId === '12' && node.hidden === false), true);
  assert.equal(result.sourceNodes.some((node) => node.sourceHubId === '13' && node.hidden === true), true);
  assert.equal(result.sourceFilterCleared, true);

  assert.match(result.afterSectionChange.text, /Executive Situation Assessment/);
  assert.match(result.afterSectionChange.text, /<script>alert\(1\)<\/script>/);
  assert.match(result.afterSectionChange.html, /&lt;script&gt;alert\(1\)&lt;\/script&gt;/);
  assert.doesNotMatch(result.afterSectionChange.html, /<script>alert\(1\)<\/script>/);

  assert.equal(result.afterDestroy.aText, '');
  assert.equal(result.afterDestroy.aInstance, null);
  assert.match(result.afterDestroy.bText, /Executive Situation Assessment/);

  assert.equal(canonicalResult.h1, 'City SITREP');
  assert.match(canonicalResult.text, /Privacy Defaults/);
  assert.match(canonicalResult.text, /Counting Notes/);
  assert.equal(canonicalResult.countingNotesTitle, 'Resolved and discarded reports are excluded from current pressure');
  assert.doesNotMatch(canonicalResult.gapsText, /Closed and discarded reports are not current pressure/);
  assert.ok(!canonicalResult.gapTableTitles.includes('Population Evidence'));
  assert.equal(canonicalResult.hasResourceEvidenceWrapper, false);
  assert.ok(canonicalResult.locationPopulationEvidenceCards.some((card) => card.title === 'Guadalupe' && card.rows[0]?.join('|') === 'People injured|1|1|Details reported; verification required.'));
  assert.ok(canonicalResult.locationPopulationEvidenceCards.some((card) => card.title === 'Guadalupe' && card.rows[1]?.join('|') === 'Patient or injured person|3|3|serious; guarded; stable'));
  assert.ok(canonicalResult.locationPopulationEvidenceCards.some((card) => card.title === 'Apas' && card.rows[1]?.join('|') === 'Affected family|1|10|2 families; 1 displacement signal; Overall declared breakdown: 6 Children, 2 Pregnant'));
  assert.equal(canonicalResult.singlePopulationEvidenceCards, 0);
  assert.ok(canonicalResult.singlePopulationEvidenceRows.some((row) => row.join('|') === 'Population/life-safety records|6|6|People injured, Patient or injured person, Estimated people involved, Evacuation Needed.'));
  assert.doesNotMatch(canonicalResult.singleGapsText, /Evidence 6 current population\/life-safety records reported/);
  assert.ok(canonicalResult.directResourceEvidenceRows.some((row) => row.join('|') === 'Rescue and Extraction|17|Body Harness, Rescue Boat'));
  assert.ok(canonicalResult.locationResourceEvidenceCards.some((card) => card.title === 'Guadalupe' && card.rows[0]?.join('|') === 'Rescue and Extraction|7|Body Harness'));
  assert.ok(canonicalResult.locationResourceEvidenceCards.some((card) => card.title === 'Apas' && card.rows[1]?.join('|') === 'Search and Damage Assessment|8|Structural Assessment Team'));
  assert.match(canonicalResult.text, /Closed and discarded reports are not current pressure/);
  assert.match(canonicalResult.text, /4 resolved reports were treated as addressed history/);
  assert.match(canonicalResult.text, /5 resolved reports were treated as addressed history/);
  assert.match(canonicalResult.text, /Current Locations/);
  assert.match(canonicalResult.text, /Damage Summary/);
  assert.match(canonicalResult.text, /Population Summary/);
  assert.match(canonicalResult.text, /Declared Member Breakdown/);
  assert.match(canonicalResult.text, /Category Demand/);
  assert.match(canonicalResult.text, /Resource Needs/);
  assert.match(canonicalResult.text, /Rescue Team/);
  assert.match(canonicalResult.text, /17/);
  assert.equal(canonicalResult.usesOfficialTableClass, true);
  assert.equal(canonicalResult.usesLegacyJsTableClass, false);
  assert.ok(canonicalResult.sectionEyebrows.includes('Summary'));
  assert.ok(canonicalResult.sectionEyebrows.includes('Situation'));
  assert.ok(canonicalResult.sourceNames.includes('Guadalupe'));
  assert.ok(!canonicalResult.sourceNames.includes('Barangay Guadalupe, Cebu City, Cebu'));
  assert.doesNotMatch(canonicalResult.text, /Leadership signal/);
  assert.deepEqual(canonicalResult.h2Titles.slice(1, 4), ['Current Areas of Concern', 'Reported Damage', 'Affected People']);
  assert.deepEqual(canonicalResult.demoSectionEyebrows.slice(1, 5), ['Summary', 'Situation', 'Damage', 'Population']);
  assert.match(canonicalResult.text, /Individual incident references are retained in the source snapshot and supporting tables/);
  assert.match(canonicalResult.text, /Individual damage entries are retained in the source snapshot and exports/);
  assert.match(canonicalResult.text, /Individual population entries are retained in the source snapshot and exports/);
  assert.ok(canonicalResult.mainSignalsBulletCount >= 3);
  assert.equal(canonicalResult.documentConcernBulletCount, 0);
  assert.match(canonicalResult.documentConcernText, /2 limited access points; Types: Flood; Key needs: Rescue Team/);
  assert.equal(canonicalResult.actionValues.includes('0'), false);
  assert.equal(canonicalResult.actionValues.includes('-'), true);
  assert.deepEqual(canonicalResult.tableTitles.slice(0, 10), [
    'Grouped Current Concerns',
    'Current Locations',
    'Current Incident Types',
    'Damage Summary',
    'Population Summary',
    'Declared Member Breakdown',
    'Team Deployment',
    'Assignment Timing',
    'Category Demand',
    'Resource Needs',
  ]);
} finally {
  await browser.close();
}
