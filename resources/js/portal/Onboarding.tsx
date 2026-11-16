import { useEffect, useState, type FormEvent } from 'react'
import { ApiError, csrfToken, request } from './api/session'
import type { Page } from './api/management'
import { Loading } from './Loading'
import './Onboarding.css'

type PendingOrganization = { id: string; name: string; can_complete: boolean }
type Details = {
  legal_name: string; organization_type: string; ownership_type: string; owner_name: string
  registration_authority: string; registration_number: string; address_line_1: string
  address_line_2: string; city: string; state_region: string; postal_code: string; country: string
}

const emptyDetails: Details = {
  legal_name: '', organization_type: '', ownership_type: '', owner_name: '',
  registration_authority: '', registration_number: '', address_line_1: '', address_line_2: '',
  city: '', state_region: '', postal_code: '', country: '',
}

export function Onboarding({ onComplete, onSignOut, onPlatformWorkspace }: {
  onComplete: () => Promise<void>; onSignOut: () => Promise<void>; onPlatformWorkspace?: () => void
}) {
  const [pending, setPending] = useState<Page<PendingOrganization> | null>(null)
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState('')
  const [details, setDetails] = useState<Details>(emptyDetails)
  const [authority, setAuthority] = useState(false)
  const [consent, setConsent] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let active = true
    request<Page<PendingOrganization>>(`/api/v1/onboarding?page=${page}`).then((result) => {
      if (!active) return
      setPending(result)
      setSelected(result.data.find((item) => item.can_complete)?.id ?? '')
      setError('')
    }).catch(() => { if (active) setError('Could not load onboarding. Check your connection and try again.') })
    return () => { active = false }
  }, [page, reload])

  function field(key: keyof Details, label: string, required = true, maxLength = 255) {
    return <label className="onboarding-field" htmlFor={key}>{label}
      <input id={key} value={details[key]} required={required} maxLength={maxLength}
        onChange={(event) => setDetails({ ...details, [key]: event.target.value })} />
    </label>
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError('')
    try {
      const token = await csrfToken()
      await request(`/api/v1/organizations/${encodeURIComponent(selected)}/onboarding`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
        body: JSON.stringify({ ...details, authority_confirmed: authority, processing_consent: consent, notice_version: 'synthetic-2026-11-11' }),
      })
      setDetails(emptyDetails); setAuthority(false); setConsent(false)
      await onComplete()
      setPage(1); setReload((value) => value + 1)
    } catch (cause) {
      setError(cause instanceof ApiError && cause.status === 422
        ? 'Check all fields and confirmations. If onboarding was already completed, refresh this page.'
        : 'Could not complete onboarding. Check your access and connection, then try again.')
    } finally { setBusy(false) }
  }

  async function checkStatus() {
    setBusy(true); setError('')
    try {
      await onComplete()
      setPage(1); setReload((value) => value + 1)
    } catch {
      setError('Could not check your onboarding status. Try again.')
    } finally { setBusy(false) }
  }

  return <main className="onboarding-page"><div className="onboarding-card">
    <header className="onboarding-header"><img className="brand-logo" src="/mediflow-logo.png" alt="MediSphere" />
      <div><p className="onboarding-kicker">SYNTHETIC TEST ENVIRONMENT</p><h1>Organization onboarding</h1></div>
    </header>
    <p>Complete onboarding for each assigned organization before accessing its workspace. Use made-up data only.</p>
    {error && <div role="alert" className="auth-error">{error} <button type="button" onClick={() => setReload((value) => value + 1)}>Retry</button></div>}
    {!pending && !error && <Loading message="Loading your organizations..." />}
    {pending && pending.data.length === 0 && <p>No organization is available for onboarding. Contact your platform administrator.</p>}
    {pending && pending.data.some((item) => !item.can_complete) && <p>An organization administrator must complete onboarding for any organization where you have read-only access.</p>}
    {pending && pending.data.some((item) => item.can_complete) && <form onSubmit={submit} className="onboarding-form">
      <label className="onboarding-field" htmlFor="onboarding-organization">Organization
        <select id="onboarding-organization" value={selected} required onChange={(event) => {
          setSelected(event.target.value); setDetails(emptyDetails); setAuthority(false); setConsent(false)
        }}>{pending.data.filter((item) => item.can_complete).map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select>
      </label>
      <fieldset><legend>Organization</legend><div className="onboarding-grid">
        {field('legal_name', 'Registered organization name')}
        {field('organization_type', 'Organization type')}
        <label className="onboarding-field" htmlFor="ownership_type">Ownership type
          <select id="ownership_type" required value={details.ownership_type} onChange={(event) => setDetails({ ...details, ownership_type: event.target.value })}>
            <option value="">Select ownership</option><option value="private">Private</option><option value="public">Public / government</option><option value="nonprofit">Nonprofit</option><option value="other">Other</option>
          </select>
        </label>
        {field('owner_name', 'Owner or controlling entity')}
      </div></fieldset>
      <fieldset><legend>Government or governing-body registration</legend><div className="onboarding-grid">
        {field('registration_authority', 'Registering authority or governing body')}
        {field('registration_number', 'Registration or licence number')}
      </div><p>Registration details are self-reported and are not automatically verified.</p></fieldset>
      <fieldset><legend>Registered address</legend><div className="onboarding-grid">
        {field('address_line_1', 'Address line 1')}{field('address_line_2', 'Address line 2 (optional)', false)}
        {field('city', 'City or town')}{field('state_region', 'State or region')}
        {field('postal_code', 'Postal code (optional)', false, 32)}{field('country', 'Country')}
      </div></fieldset>
      <fieldset><legend>Authority and data processing</legend>
        <p><strong>Test notice (synthetic-2026-11-11):</strong> MediSphere stores and uses the synthetic organization, ownership, registration and address details you submit to set up and administer this test account. Do not enter real organization, patient or other sensitive data. This test notice is not a production privacy policy or consent to clinical data processing.</p>
        <label className="onboarding-check"><input type="checkbox" required checked={authority} onChange={(event) => setAuthority(event.target.checked)} /> I confirm that I am authorized to provide these details for this organization.</label>
        <label className="onboarding-check"><input type="checkbox" required checked={consent} onChange={(event) => setConsent(event.target.checked)} /> I agree to the synthetic test processing described above.</label>
      </fieldset>
      <button className="button button-primary" disabled={busy || !selected} type="submit">{busy ? 'Saving...' : 'Complete onboarding'}</button>
    </form>}
    {pending && pending.last_page > 1 && <div className="onboarding-pagination">
      <button type="button" disabled={page === 1} onClick={() => setPage(page - 1)}>Previous</button>
      <span>Page {page} of {pending.last_page}</span>
      <button type="button" disabled={page === pending.last_page} onClick={() => setPage(page + 1)}>Next</button>
    </div>}
    <div className="onboarding-footer">
      <button type="button" disabled={busy} onClick={() => { void checkStatus() }}>Check onboarding status</button>
      {onPlatformWorkspace && <button type="button" onClick={onPlatformWorkspace}>Platform workspace</button>}
      <button type="button" disabled={busy} onClick={() => { void onSignOut().catch(() => setError('Could not sign out. Try again.')) }}>Sign out</button>
    </div>
  </div></main>
}
