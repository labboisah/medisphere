import { useEffect, useState } from 'react'
import { licensingOverview, managementError, type KernelBridgeLicenseLink, type OrganizationDetail } from './api/management'
import { Loading } from './Loading'
import { reportCategoriesForLink, reportLinkForTemplate, sampleCountForRange, searchReportCategories, type ReportCategory } from './reportCatalog'

type LicenseState = { status: 'loading' } | { status: 'error'; message: string } | { status: 'loaded'; data: KernelBridgeLicenseLink | null }

export function OrganizationReports({ detail }: { detail: OrganizationDetail }) {
  const [refresh, setRefresh] = useState(0)
  const [search, setSearch] = useState('')
  const [from, setFrom] = useState('')
  const [to, setTo] = useState('')
  const [licenseState, setLicenseState] = useState<LicenseState>({ status: 'loading' })
  const canManageLicense = detail.permissions.includes('license.manage')

  useEffect(() => {
    let active = true
    if (canManageLicense) {
      licensingOverview(detail.id).then((result) => {
        if (active) setLicenseState({ status: 'loaded', data: result.link })
      }).catch((error: unknown) => {
        if (active) setLicenseState({ status: 'error', message: managementError(error) })
      })
    }
    return () => { active = false }
  }, [detail.id, canManageLicense, refresh])

  const link = canManageLicense && licenseState.status === 'loaded' ? licenseState.data : null
  const usableLink = reportLinkForTemplate(link)
  const categories = usableLink ? reportCategoriesForLink(usableLink) : []

  return <div className="dashboard-data">
    <section className="dashboard-panel">
      <div className="dashboard-panel-heading">
        <div><h2>{detail.name} summary format</h2><p>Proposed summary fields for the linked plan.</p></div>
        {canManageLicense && <button className="dashboard-action" type="button" onClick={() => {
          setLicenseState({ status: 'loading' })
          setRefresh((value) => value + 1)
        }}>Refresh</button>}
      </div>
      <p><strong>Sample data only:</strong> The counts and chart below are illustrative and are not Clinic data. Actual summaries are unavailable pending privacy and definition review; a dash does not mean zero.</p>
      {!canManageLicense && <p>License-management permission is required to view the linked plan's report format.</p>}
      {canManageLicense && licenseState.status === 'loading' && <Loading message="Loading linked plan..." />}
      {canManageLicense && licenseState.status === 'error' && <p className="auth-error" role="alert">Could not load the linked plan: {licenseState.message} Use Refresh to try again.</p>}
      {canManageLicense && licenseState.status === 'loaded' && !usableLink && <p>No usable, verified MediFlow account-link snapshot is available. Check the License screen and refresh the link from KernelBridge.</p>}
      {usableLink && <>
        <p>Linked plan: <strong>{usableLink.plan_name || 'Name not available'}</strong> · Last verified: {usableLink.verified_at && new Date(usableLink.verified_at).toLocaleString()} · License status at verification: {usableLink.license_status}.</p>
        <p>Based on enabled grants in the last verified organization account-link snapshot, which may be outdated. It is not a verified list of modules active on an individual Clinic installation. Refreshing this page does not reverify the license; use the License screen to refresh from KernelBridge.</p>
        {categories.length === 0 ? <p>No recognized enabled reporting categories were found in this snapshot. Check the linked plan's entitlements on the License screen.</p> : <>
          <label className="report-search">Search summary fields
            <input type="search" value={search} onChange={(event) => setSearch(event.target.value)} />
          </label>
          <div className="report-date-filters" role="group" aria-label="Sample date range">
            <label>From <input type="date" value={from} aria-describedby="report-date-help" aria-invalid={Boolean(from && to && from > to)} onChange={(event) => setFrom(event.target.value)} /></label>
            <label>To <input type="date" value={to} aria-describedby="report-date-help" aria-invalid={Boolean(from && to && from > to)} onChange={(event) => setTo(event.target.value)} /></label>
            <button type="button" className="dashboard-action" onClick={() => { setFrom(''); setTo('') }}>Clear dates</button>
          </div>
          <p id="report-date-help">Inclusive dates filter only the illustrative counts. Leave either date blank for an open-ended range. Sample entries are dated 1 October, 1 November and 12 November 2026.</p>
          <ReportSummaryContent categories={categories} search={search} from={from} to={to} />
        </>}
      </>}
    </section>
  </div>
}

export function ReportSummaryContent({ categories, search, from, to }: { categories: ReportCategory[]; search: string; from: string; to: string }) {
  const filtered = searchReportCategories(categories, search).map((category) => ({
    ...category,
    sampleCount: sampleCountForRange(category, from, to),
  }))
  const sampleRows = filtered.filter((category) => category.sampleCount !== undefined)
  const maxSample = Math.max(0, ...sampleRows.map((category) => category.sampleCount ?? 0))

  if (from && to && from > to) return <p className="auth-error" role="alert">The From date must be on or before the To date.</p>
  if (filtered.length === 0) return <p>No summary fields match your search.</p>

  return <>
    <h3>Sample count visualization</h3>
    <p>Illustrative counts only for the selected dates. Zero means no sample entries in the range, not zero Clinic activity. Bar lengths are relative to the largest displayed sample; different measures may overlap.</p>
    {sampleRows.length === 0 ? <p>No sample counts are available for the matching fields.</p> :
      <ul className="report-bars" aria-label="Illustrative sample counts">
        {sampleRows.map((category) => <li key={category.key}>
          <span>{category.label}</span>
          <div className="report-bar-track" aria-hidden="true"><div className="report-bar-fill" style={{ width: `${maxSample ? (category.sampleCount ?? 0) / maxSample * 100 : 0}%` }} /></div>
          <strong>{category.sampleCount} sample</strong>
        </li>)}
      </ul>}
    <div className="report-table-container"><table className="report-table">
      <caption>Proposed summary fields and illustrative sample counts</caption>
      <thead><tr><th scope="col">Summary</th><th scope="col">Sample count</th><th scope="col">Actual data status</th></tr></thead>
      <tbody>{filtered.map((category) => <tr key={category.key}>
        <th scope="row">{category.label}</th>
        <td>{category.sampleCount === undefined ? '—' : `${category.sampleCount} (sample)`}</td>
        <td>Unavailable — pending review</td>
      </tr>)}</tbody>
    </table></div>
  </>
}
