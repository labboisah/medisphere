import { useEffect, useState, type FormEvent } from 'react'
import { Loading } from './Loading'
import { csrfToken, request, ApiError, type CurrentSession } from './api/session'
import { managementError, type Organization, type Page } from './api/management'
import { Pagination } from './Management'
import { PlatformRoles } from './PlatformRoles'
import './Dashboard.css'

export function PlatformDashboard({ session, onSignOut, onOrganizationWorkspace }: { session: CurrentSession; onSignOut: () => Promise<void>; onOrganizationWorkspace: () => void }) {
  const [page, setPage] = useState(1)
  const [list, setList] = useState<Page<Organization> | null>(null)
  const [refresh, setRefresh] = useState(0)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [name, setName] = useState('')
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [view, setView] = useState<'organizations' | 'roles'>('organizations')

  useEffect(() => {
    let active = true
    request<Page<Organization>>(`/api/v1/platform/organizations?page=${page}`).then((result) => {
      if (active) { setList(result); setError('') }
    }).catch((cause: unknown) => { if (active) { setList(null); setError(managementError(cause)) } })
    return () => { active = false }
  }, [page, refresh])

  async function create(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError(''); setNotice('')
    try {
      const csrf = await csrfToken()
      await request('/api/v1/platform/organizations', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ name, email }),
      })
      setName(''); setEmail(''); setPage(1); setRefresh((value) => value + 1)
      setNotice('Organization created and invitation sent. The invitation expires in 24 hours.')
    } catch (cause) {
      setError(cause instanceof ApiError && cause.status === 422 ? 'Check the organization name and email. Invitations are only for new accounts.' : cause instanceof ApiError && cause.status >= 500 ? 'Could not create the organization or send the invitation. Check the SMTP configuration and try again.' : managementError(cause))
    } finally { setBusy(false) }
  }

  return <div className="dashboard">
    <a className="skip-link" href="#platform-main">Skip to dashboard content</a>
    <aside className="dashboard-sidebar">
      <div className="dashboard-brand"><img className="brand-logo" src="/mediflow-logo.png" alt="" /><span>Medi<span className="brand-accent">Sphere</span><small>PLATFORM</small></span></div>
      <p className="dashboard-nav-label">PLATFORM ADMINISTRATION</p>
      <nav className="dashboard-nav" aria-label="Platform navigation"><button type="button" aria-current={view === 'organizations' ? 'page' : undefined} onClick={() => setView('organizations')}><span>Organizations</span></button><button type="button" aria-current={view === 'roles' ? 'page' : undefined} onClick={() => setView('roles')}><span>Roles and permissions</span></button><button type="button" onClick={onOrganizationWorkspace}>Organization workspace</button></nav>
      <div className="dashboard-sidebar-note"><strong>Test environment</strong><p>Use made-up data only. This account cannot access organization facilities.</p></div>
    </aside>
    <div className="dashboard-body">
      <header className="dashboard-header"><div><p>PLATFORM WORKSPACE</p><strong>{view === 'roles' ? 'Roles and permissions' : 'Organizations'}</strong></div><div className="dashboard-account"><span className="dashboard-avatar" aria-hidden="true">{session.name.trim().charAt(0).toUpperCase() || 'U'}</span><span className="dashboard-account-name">{session.name}</span><button type="button" disabled={busy} onClick={() => { setBusy(true); void onSignOut().catch(() => setError('Could not sign out. Please try again.')).finally(() => setBusy(false)) }}>Sign out</button></div></header>
      <main className="dashboard-main" id="platform-main">
        <div className="dashboard-heading"><div><p className="dashboard-kicker">MEDISPHERE / PLATFORM</p><h1>{view === 'roles' ? 'Roles and permissions' : 'Organizations'}</h1><p>{view === 'roles' ? 'Define organization-wide roles and their permissions.' : 'Create a synthetic organization and invite its first administrator.'}</p></div><span className="dashboard-test-badge">SYNTHETIC DATA ONLY</span></div>
        {view === 'roles' ? <PlatformRoles /> : <>
        {error && <p className="auth-error" role="alert">{error}</p>}
        {notice && <p role="status">{notice}</p>}
        <section className="dashboard-panel"><h2>Create organization</h2><p>The invited administrator will create an account and enroll in MFA. Existing accounts cannot be invited.</p>
          <form className="management-form" onSubmit={create}>
            <label htmlFor="platform-org-name">Organization name</label><input id="platform-org-name" required maxLength={255} value={name} onChange={(event) => setName(event.target.value)} />
            <label htmlFor="platform-invite-email">Initial administrator email</label><input id="platform-invite-email" type="email" required maxLength={255} value={email} onChange={(event) => setEmail(event.target.value)} />
            <button type="submit" className="button button-primary" disabled={busy}>{busy ? 'Please wait...' : 'Create and send invitation'}</button>
          </form>
        </section>
        <section className="dashboard-panel" style={{ marginTop: '1rem' }}><h2>Provisioned organizations</h2><p>Only names and recorded statuses are shown. Organization data requires separate membership.</p>
          {!list && !error && <Loading message="Loading organizations..." />}
          {!list && error && <button className="dashboard-action" type="button" onClick={() => setRefresh((value) => value + 1)}>Try again</button>}
          {list && <>{list.data.length === 0 ? <p>No organizations have been created.</p> : <ul className="management-facilities">{list.data.map((item) => <li key={item.id}><strong>{item.name}</strong><span className="dashboard-status">{item.status}</span></li>)}</ul>}<Pagination page={list.current_page} last={list.last_page} onChange={(next) => { setList(null); setPage(next) }} /></>}
        </section>
        </>}
      </main>
    </div>
  </div>
}
