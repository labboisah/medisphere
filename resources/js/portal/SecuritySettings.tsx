import { useState, type FormEvent } from 'react'
import { ApiError } from './api/session'
import { recoveryCodes, regenerateRecoveryCodes } from './api/session'

export function SecuritySettings() {
  const [password, setPassword] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)

  function explainError(cause: unknown): string {
    if (cause instanceof ApiError) {
      if (cause.status === 401) return 'Your session has ended. Sign in again.'
      if (cause.status === 403) return 'Your MFA verification has expired. Sign out and sign in again before managing recovery codes.'
      if (cause.status === 423) return 'Confirm your password again before accessing recovery codes.'
      if (cause.status === 429) return 'Recovery codes were recently rotated. Wait a minute before trying again.'
    }
    return 'The request could not be completed. Check your connection and try again.'
  }

  async function showCodes(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setBusy(true); setError(''); setNotice('')
    try {
      setCodes(await recoveryCodes(password))
      setPassword('')
    } catch (cause) {
      setError(explainError(cause))
    } finally { setBusy(false) }
  }

  async function rotateCodes() {
    if (!window.confirm('Generate new recovery codes? All current recovery codes will stop working.')) return
    setBusy(true); setError(''); setNotice('')
    try {
      setCodes(await regenerateRecoveryCodes(password))
      setPassword('')
      setNotice('New recovery codes generated. All previous codes are invalid.')
    } catch (cause) {
      setError(explainError(cause))
    } finally { setBusy(false) }
  }

  return <section className="dashboard-panel security-settings">
    <h2>Account security</h2>
    <p>Recovery codes are single-use alternatives to your authenticator code. Keep them private and store them separately from your password.</p>
    {notice && <p role="status">{notice}</p>}
    {error && <p className="auth-error" role="alert">{error}</p>}
    <form className="management-form" onSubmit={showCodes}>
      <label htmlFor="security-password">Confirm your password</label>
      <input autoComplete="current-password" id="security-password" onChange={(event) => setPassword(event.target.value)} required type="password" value={password} />
      <div className="management-actions">
        <button className="button button-primary" disabled={busy} type="submit">{busy ? 'Please wait...' : 'Show current recovery codes'}</button>
        <button type="button" disabled={busy || !password} onClick={() => { void rotateCodes() }}>Generate new codes</button>
      </div>
    </form>
    {codes && <div className="security-codes" aria-live="polite">
      <h3>Recovery codes</h3>
      <p>Each code can be used once. If you generated new codes, these replace the previous set.</p>
      <ul className="recovery-list">{codes.map((code) => <li key={code}><code>{code}</code></li>)}</ul>
      <button className="dashboard-action" type="button" onClick={() => setCodes(null)}>Hide codes</button>
    </div>}
    <div className="security-recovery-note">
      <strong>Lost your authenticator?</strong>
      <p>Use an unused recovery code at sign-in. If you have lost both your authenticator and all recovery codes, contact the deployment administrator through an independently verified support process. Organization administrators cannot bypass or reset your MFA.</p>
    </div>
  </section>
}
