import { useEffect, useState, type ReactNode } from 'react'
import { managementError, organization, organizations, type Organization, type OrganizationDetail } from './api/management'
import type { CurrentSession } from './api/session'
import { OrganizationManagement } from './Management'
import { OrganizationReports } from './OrganizationReports'
import { SecuritySettings } from './SecuritySettings'
import { SyntheticSharingDemo } from './SyntheticSharingDemo'
import { Loading } from './Loading'
import './Dashboard.css'

type View = 'overview' | 'reports' | 'facilities' | 'organization' | 'license' | 'servers' | 'sync' | 'invitations' | 'users' | 'security' | 'synthetic-sharing'

function Icon({ children }: { children: ReactNode }) {
  return <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">{children}</svg>
}

const navigation: { id: View; label: string; icon: ReactNode }[] = [
  { id: 'overview', label: 'Overview', icon: <Icon><rect x="3" y="3" width="7" height="7" rx="1" /><rect x="14" y="3" width="7" height="7" rx="1" /><rect x="3" y="14" width="7" height="7" rx="1" /><rect x="14" y="14" width="7" height="7" rx="1" /></Icon> },
  { id: 'reports', label: 'Reports', icon: <Icon><path d="M4 20V4M4 20h16M8 16v-5M12 16V7M16 16v-3M20 16V5" /></Icon> },
  { id: 'facilities', label: 'Facilities', icon: <Icon><path d="M3 21h18M5 21V7l7-4 7 4v14M9 21v-5h6v5M10 10h4M12 8v4" /></Icon> },
  { id: 'organization', label: 'Organization', icon: <Icon><path d="M3 21h18M5 21V5h14v16M9 9h2M13 9h2M9 13h2M13 13h2M10 21v-4h4v4" /></Icon> },
  { id: 'license', label: 'License', icon: <Icon><path d="M5 3h14v18l-7-3-7 3zM9 8h6M9 12h6" /></Icon> },
  { id: 'servers', label: 'Servers', icon: <Icon><rect x="3" y="4" width="18" height="7" rx="1" /><rect x="3" y="13" width="18" height="7" rx="1" /><path d="M7 7h.01M7 16h.01" /></Icon> },
  { id: 'sync', label: 'Operational sync', icon: <Icon><path d="M20 7h-5V2M4 17h5v5M20 7a8 8 0 0 0-14-2L4 7M4 17a8 8 0 0 0 14 2l2-2" /></Icon> },
  { id: 'invitations', label: 'Invitations', icon: <Icon><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></Icon> },
  { id: 'users', label: 'Users', icon: <Icon><circle cx="9" cy="8" r="3" /><path d="M3 20v-2a6 6 0 0 1 12 0v2M16 5a3 3 0 0 1 0 6M18 14a5 5 0 0 1 3 5v1" /></Icon> },
  { id: 'security', label: 'Account security', icon: <Icon><rect x="4" y="10" width="16" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></Icon> },
  { id: 'synthetic-sharing', label: 'Synthetic sharing demo', icon: <Icon><path d="M12 3 4 6v5c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-3Z" /><path d="M9 12h6M12 9v6" /></Icon> },
]

export function Dashboard({ session, onSignOut, onPlatformWorkspace }: { session: CurrentSession; onSignOut: () => Promise<void>; onPlatformWorkspace?: () => void }) {
  const [view, setView] = useState<View>('overview')
  const [list, setList] = useState<Organization[] | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [refresh, setRefresh] = useState(0)
  const [error, setError] = useState('')
  const [accessError, setAccessError] = useState<{ id: string; message: string } | null>(null)
  const [signOutError, setSignOutError] = useState('')
  const [signingOut, setSigningOut] = useState(false)
  const [access, setAccess] = useState<OrganizationDetail | null>(null)

  useEffect(() => { window.scrollTo(0, 0) }, [])

  useEffect(() => {
    let active = true
    async function loadOrganizations() {
      const all: Organization[] = []
      let page = 1
      let lastPage = 1
      do {
        const result = await organizations(page)
        if (!active) return
        all.push(...result.data)
        setList([...all])
        setSelected((current) => current ?? all[0]?.id ?? null)
        lastPage = result.last_page
        page += 1
      } while (page <= lastPage)
      setList(all)
      setError('')
      setSelected((current) => all.some((item) => item.id === current) ? current : all[0]?.id ?? null)
    }
    loadOrganizations().catch((cause: unknown) => {
      if (active) setError(managementError(cause))
    })
    return () => { active = false }
  }, [refresh])

  useEffect(() => {
    let active = true
    if (selected) {
      organization(selected).then((detail) => { if (active) { setAccess(detail); setAccessError(null) } })
        .catch((cause: unknown) => { if (active) setAccessError({ id: selected, message: managementError(cause) }) })
    }
    return () => { active = false }
  }, [selected, refresh])

  async function signOut() {
    setSigningOut(true)
    setSignOutError('')
    try { await onSignOut() } catch { setSignOutError('Could not sign out. Please try again.') } finally { setSigningOut(false) }
  }

  const title = navigation.find((item) => item.id === view)?.label ?? 'Overview'
  const currentAccess = access?.id === selected ? access : null
  const selectedOrganization = list?.find((item) => item.id === selected) ?? null
  const currentError = error || (accessError?.id === selected ? accessError.message : '')
  return <div className="dashboard">
    <a className="skip-link" href="#dashboard-main">Skip to dashboard content</a>
    <aside className="dashboard-sidebar">
      <div className="dashboard-brand"><img className="brand-logo" src="/mediflow-logo.png" alt="" /><span>Medi<span className="brand-accent">Sphere</span><small>PLATFORM</small></span></div>
      <p className="dashboard-nav-label">WORKSPACE</p>
      <nav className="dashboard-nav" aria-label="Dashboard navigation">
        {navigation.filter((item) => {
          if (item.id === 'facilities') return currentAccess !== null && !currentAccess.can_manage && currentAccess.can_view_facilities
          const permission = ({ license: 'license.manage', servers: 'servers.manage', sync: 'servers.manage', invitations: 'invitations.manage', users: 'users.manage', 'synthetic-sharing': 'synthetic.exchange.manage' } as Partial<Record<View, string>>)[item.id]
          return permission ? currentAccess?.permissions.includes(permission) : true
        }).map((item) => <button key={item.id} type="button" aria-current={view === item.id ? 'page' : undefined} onClick={() => setView(item.id)}>{item.icon}<span>{item.label}</span></button>)}
        {onPlatformWorkspace && <button type="button" onClick={onPlatformWorkspace}>Platform workspace</button>}
      </nav>
      <div className="dashboard-sidebar-note"><strong>Test environment</strong><p>Use made-up data only. Real patient or facility data must not be used.</p></div>
    </aside>
    <div className="dashboard-body">
      <header className="dashboard-header">
        <div><p>ORGANIZATION WORKSPACE</p><strong>{title}</strong></div>
        <div className="dashboard-header-actions">
          <select className="dashboard-org-select" aria-label="Select organization" value={selected ?? ''} disabled={!list || list.length === 0} onChange={(event) => { setView('overview'); setSelected(event.target.value) }}>
            {!list && <option value="">{error ? 'Organizations unavailable' : 'Loading organizations...'}</option>}
            {list?.length === 0 && <option value="">No organizations assigned</option>}
            {selected && list && !list.some((item) => item.id === selected) && <option value={selected}>Selected organization</option>}
            {list?.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}
          </select>
          <div className="dashboard-account"><span className="dashboard-avatar" aria-hidden="true">{session.name.trim().charAt(0).toUpperCase() || 'U'}</span><span className="dashboard-account-name">{session.name}</span><button type="button" disabled={signingOut} onClick={() => { void signOut() }}>{signingOut ? 'Signing out...' : 'Sign out'}</button></div>
        </div>
      </header>
      <main className="dashboard-main" id="dashboard-main">
        {signOutError && <p className="auth-error" role="alert">{signOutError}</p>}
        <div className="dashboard-heading"><div><p className="dashboard-kicker">MEDISPHERE / {title.toUpperCase()}</p><h1>{title}</h1><p>{view === 'overview' ? `Welcome, ${session.name}. Here is your workspace at a glance.` : view === 'facilities' ? 'Your facilities, all in one place.' : view === 'security' ? 'Manage your own MFA recovery codes.' : `View ${title.toLowerCase()} for your selected organization.`}</p></div><span className="dashboard-test-badge">SYNTHETIC DATA ONLY</span></div>
        {currentError && <div className="dashboard-panel"><p className="auth-error" role="alert">{currentError}</p><button className="dashboard-action" type="button" onClick={() => setRefresh((value) => value + 1)}>Try again</button></div>}
        {view === 'overview' && !currentAccess && selectedOrganization && <div className="dashboard-welcome">
          <div><p className="dashboard-kicker">YOUR WORKSPACE</p><h2>{selectedOrganization.name}</h2><p>Loading organization details and workspace summaries...</p></div>
          <span className="dashboard-status">{selectedOrganization.status}</span>
        </div>}
        {view === 'security' ? <SecuritySettings /> : <>
          {!list && !error && <Loading message="Loading organizations..." />}
          {list && list.length === 0 && <div className="dashboard-panel"><h2>No organizations assigned</h2><p>Contact your organization administrator for access.</p></div>}
          {list && list.length > 0 && <>
            {view === 'reports' && selected && !currentAccess && !currentError && <Loading message="Loading organization details..." />}
            {selected && currentAccess && (view === 'reports'
              ? <OrganizationReports key={`${selected}:${currentAccess.permissions.includes('license.manage')}`} detail={currentAccess} />
              : view === 'synthetic-sharing'
                ? <SyntheticSharingDemo key={selected} organizationId={selected} />
                : <OrganizationManagement key={selected} id={selected} initialDetail={currentAccess} view={view} onNavigate={setView} />)}
          </>}
        </>}
      </main>
    </div>
  </div>
}
