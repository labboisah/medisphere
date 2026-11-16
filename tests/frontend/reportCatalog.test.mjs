import { after, test } from 'node:test'
import assert from 'node:assert/strict'
import { createServer } from 'vite'
import React from 'react'
import { renderToStaticMarkup } from 'react-dom/server'

const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', logLevel: 'error' })
after(async () => { await server.close() })
const { reportCategoriesForLink, reportLinkForTemplate, sampleCountForRange } = await server.ssrLoadModule('/resources/js/portal/reportCatalog.ts')
const { OrganizationReports, ReportSummaryContent } = await server.ssrLoadModule('/resources/js/portal/OrganizationReports.tsx')
const { Dashboard } = await server.ssrLoadModule('/resources/js/portal/Dashboard.tsx')
const { PlatformDashboard } = await server.ssrLoadModule('/resources/js/portal/PlatformDashboard.tsx')
const { Invitation } = await server.ssrLoadModule('/resources/js/portal/Invitation.tsx')

const link = {
  status: 'approved', license_status: 'active', product_code: 'MEDIFLOW',
  verified_at: '2026-01-01T00:00:00Z', entitlements: [],
}
const grant = (key, enabled = true) => ({ key, enabled })
const keys = (entitlements) => reportCategoriesForLink({ ...link, entitlements }).map((item) => item.key)

test('selects only categories supported by enabled current or legacy grants', () => {
  const result = keys([grant('mediflow.module.patient_records'), grant('maternity'), grant('finance', false)])
  assert.ok(result.includes('patients'))
  assert.ok(result.includes('visits'))
  assert.ok(result.includes('deliveries'))
  assert.ok(result.includes('labour_episodes'))
  assert.ok(!result.includes('admissions'))
  assert.ok(!result.includes('finance'))
})

test('current grants override conflicting legacy grants and unsupported grants are ignored', () => {
  assert.deepEqual(keys([grant('maternity'), grant('mediflow.module.maternity', false), grant('other')]), [])
  assert.deepEqual(keys([]), [])
  assert.ok(keys([grant('mediflow.module.laboratory')]).includes('investigations'))
})

test('clinical care includes separate labour and surgery-category service placeholders', () => {
  const result = reportCategoriesForLink({ ...link, entitlements: [grant('mediflow.module.clinical_care')] })
  assert.equal(result.find((category) => category.key === 'labour_services')?.label, 'Labour services')
  assert.equal(result.find((category) => category.key === 'surgery')?.label, 'Surgery-category services')
  assert.ok(!keys([grant('maternity')]).includes('surgery'))
  assert.ok(!keys([grant('maternity')]).includes('labour_services'))
})

test('requires a verified, approved, usable MediFlow link without assuming missing values', () => {
  assert.equal(reportLinkForTemplate(link), link)
  for (const change of [
    { status: 'revoked' }, { license_status: 'expired' }, { license_status: null },
    { product_code: 'OTHER' }, { product_code: undefined }, { verified_at: null },
  ]) {
    assert.equal(reportLinkForTemplate({ ...link, ...change }), null)
  }
  assert.equal(reportLinkForTemplate(null), null)
})

test('sample chart and table follow search and enabled modules', () => {
  const categories = reportCategoriesForLink({ ...link, entitlements: [grant('patient_records'), grant('clinical_care')] })
  const render = (search, from = '', to = '') => renderToStaticMarkup(React.createElement(ReportSummaryContent, { categories, search, from, to }))
  const filtered = render('  SURGERY ')
  assert.match(filtered, /Surgery-category services/)
  assert.match(filtered, /9 sample/)
  assert.match(filtered, /9 \(sample\)/)
  assert.match(filtered, /Unavailable — pending review/)
  assert.doesNotMatch(filtered, /Patient visits|Deliveries/)
  assert.match(render('prescriptions'), /No sample counts are available/)
  assert.match(render('unmatched'), /No summary fields match your search/)
  assert.match(render(''), /Patient visits/)
  assert.doesNotMatch(render(''), /Deliveries/)
  const dated = render('surgery', '2026-11-01', '2026-11-12')
  assert.match(dated, /7 sample/)
  assert.match(dated, /7 \(sample\)/)
  assert.doesNotMatch(dated, /9 \(sample\)/)
  assert.match(render('surgery', '2027-01-01', ''), /0 \(sample\)/)
  const invalid = render('surgery', '2026-11-12', '2026-11-01')
  assert.match(invalid, /From date must be on or before/)
  assert.doesNotMatch(invalid, /9 \(sample\)/)
})

test('inclusive date bounds and open-ended ranges filter only dated sample observations', () => {
  const categories = reportCategoriesForLink({ ...link, entitlements: [grant('patient_records'), grant('clinical_care')] })
  const surgery = categories.find((category) => category.key === 'surgery')
  const prescriptions = categories.find((category) => category.key === 'prescriptions')
  assert.equal(sampleCountForRange(surgery, '', ''), 9)
  assert.equal(sampleCountForRange(surgery, '2026-11-01', '2026-11-01'), 3)
  assert.equal(sampleCountForRange(surgery, '', '2026-11-01'), 5)
  assert.equal(sampleCountForRange(surgery, '2026-11-01', ''), 7)
  assert.equal(sampleCountForRange(surgery, '2026-12-01', ''), 0)
  assert.equal(sampleCountForRange(prescriptions, '', ''), undefined)
})

test('report initially labels sample data and respects license permission', () => {
  const detail = { id: 'one', name: 'Example', status: 'active', can_manage: false, can_view_facilities: false, permissions: [], onboarding: null }
  const render = (permissions) => renderToStaticMarkup(React.createElement(OrganizationReports, { detail: { ...detail, permissions } }))
  const restricted = render([])
  assert.match(restricted, /Sample data only/)
  assert.match(restricted, /License-management permission is required/)
  assert.doesNotMatch(restricted, /Connection health|Report data/)
  assert.match(render(['license.manage']), /Loading linked plan/)
})

test('workspaces and invitations display the MediSphere platform brand', () => {
  const session = { id: 1, name: 'Example' }
  const onSignOut = async () => {}
  const dashboard = renderToStaticMarkup(React.createElement(Dashboard, { session, onSignOut }))
  const platform = renderToStaticMarkup(React.createElement(PlatformDashboard, { session, onSignOut, onOrganizationWorkspace: () => {} }))
  const invitation = renderToStaticMarkup(React.createElement(Invitation, { token: '', session: null, checking: false, onSignOut, onClose: () => {} }))
  for (const html of [dashboard, platform]) {
    assert.match(html, /MEDISPHERE \//)
    assert.match(html, /Medi<span class="brand-accent">Sphere<\/span>/)
    assert.doesNotMatch(html, /MEDIFLOW \//)
  }
  assert.match(invitation, /Join MediSphere/)
  assert.match(invitation, /alt="MediSphere"/)
})
