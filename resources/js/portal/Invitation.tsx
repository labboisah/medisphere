import { useState, type FormEvent } from 'react'
import { Loading } from './Loading'
import { acceptInvitation, ApiError, type CurrentSession } from './api/session'

export function Invitation({ token, session, checking, onSignOut, onClose }: { token: string; session: CurrentSession | null; checking: boolean; onSignOut: () => Promise<void>; onClose: () => void }) {
  const [name, setName] = useState('')
  const [password, setPassword] = useState('')
  const [confirmation, setConfirmation] = useState('')
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)
  const [complete, setComplete] = useState(false)

  async function accept(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError('')
    try {
      await acceptInvitation(token, name, password, confirmation)
      setPassword(''); setConfirmation(''); setComplete(true)
    } catch (cause) {
      setError(cause instanceof ApiError && cause.status === 422
        ? 'Check your name and matching passwords, or ask for a new invitation if this one has expired or was used.'
        : 'Could not accept the invitation. Check your connection and try again.')
    } finally { setBusy(false) }
  }

  return <main className="invitation-page"><div className="auth-card invitation-card">
    <img className="brand-logo" src="/mediflow-logo.png" alt="MediSphere" />
    <h1>Join MediSphere</h1>
    <p className="auth-intro">Synthetic test environment only. Do not enter real patient or facility data.</p>
    {checking ? <Loading message="Checking your session..." /> : complete ? <>
      <p role="status">Your account is ready. Sign in and set up your authenticator to continue.</p>
      <button className="button button-primary" type="button" onClick={onClose}>Go to sign in</button>
    </> : session ? <>
      <p>Sign out of your current account before accepting this invitation.</p>
      <button className="button button-primary" disabled={busy} type="button" onClick={() => {
        setBusy(true); setError('')
        void onSignOut().catch(() => setError('Could not sign out. Try again.')).finally(() => setBusy(false))
      }}>Sign out</button>
    </> : <form className="auth-form" onSubmit={accept}>
      <label htmlFor="invite-name">Your name</label>
      <input id="invite-name" autoComplete="name" maxLength={255} required value={name} onChange={(event) => setName(event.target.value)} />
      <label htmlFor="invite-password">Password</label>
      <input id="invite-password" type="password" autoComplete="new-password" minLength={8} required value={password} onChange={(event) => setPassword(event.target.value)} />
      <label htmlFor="invite-confirmation">Confirm password</label>
      <input id="invite-confirmation" type="password" autoComplete="new-password" minLength={8} required value={confirmation} onChange={(event) => setConfirmation(event.target.value)} />
      <button className="button button-primary" disabled={busy} type="submit">{busy ? 'Creating account...' : 'Create account'}</button>
    </form>}
    {error && <p className="auth-error" role="alert">{error}</p>}
  </div></main>
}
