import { useEffect, useState } from 'react'
import { request } from './api/session'
import { Loading } from './Loading'
import './Onboarding.css'

type PendingLicense = { id: string; name: string; can_link: boolean }

export function LicenseRequired({ onRefresh, onSignOut, onPlatformWorkspace }: {
  onRefresh: () => Promise<void>; onSignOut: () => Promise<void>; onPlatformWorkspace?: () => void
}) {
  const [organizations, setOrganizations] = useState<PendingLicense[] | null>(null)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let active = true
    request<{ data: PendingLicense[] }>('/api/v1/licensing/required')
      .then((result) => { if (active) { setOrganizations(result.data); setError('') } })
      .catch(() => { if (active) setError('Unable to check licenses. Try again.') })
    return () => { active = false }
  }, [reload])

  async function check() {
    setBusy(true); setError('')
    try { await onRefresh(); setReload((value) => value + 1) }
    catch { setError('Unable to check licenses. Try again.') }
    finally { setBusy(false) }
  }

  return <main className="onboarding-page"><div className="onboarding-card">
    <header className="onboarding-header"><img className="brand-logo" src="/mediflow-logo.png" alt="MediSphere" />
      <div><p className="onboarding-kicker">KERNELBRIDGE LICENSING</p><h1>Connect your organization license</h1></div>
    </header>
    <p>Every assigned organization must have an active KernelBridge account link before you can enter the organization workspace.</p>
    {error && <p className="auth-error" role="alert">{error}</p>}
    {!organizations && !error && <Loading message="Checking licenses..." />}
    {organizations?.map((organization) => <section key={organization.id}>
      <h2>{organization.name}</h2>
      {organization.can_link
        ? <a href={`/organization-license/${encodeURIComponent(organization.id)}`}>Open KernelBridge account linking</a>
        : <p>Ask an organization administrator to link or renew its license.</p>}
    </section>)}
    {organizations?.length === 0 && <p>No unlicensed organization was found. Check your status again or contact an administrator.</p>}
    <div className="onboarding-footer">
      <button type="button" disabled={busy} onClick={() => { void check() }}>Check license status</button>
      {onPlatformWorkspace && <button type="button" onClick={onPlatformWorkspace}>Platform workspace</button>}
      <button type="button" onClick={() => { void onSignOut().catch(() => setError('Could not sign out. Try again.')) }}>Sign out</button>
    </div>
  </div></main>
}
