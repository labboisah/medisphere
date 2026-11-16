import { useState, type FormEvent } from 'react'
import { beginMfaSetup, confirmMfaSetup, getRecoveryCodes, mfaQrCode, recoveryCodes } from './api/session'

type Props = {
  onComplete: () => Promise<void>
  onSignOut: () => Promise<void>
}

export function MfaSetup({ onComplete, onSignOut }: Props) {
  const [password, setPassword] = useState('')
  const [secret, setSecret] = useState('')
  const [qrCode, setQrCode] = useState('')
  const [qrError, setQrError] = useState('')
  const [code, setCode] = useState('')
  const [codes, setCodes] = useState<string[] | null>(null)
  const [confirmed, setConfirmed] = useState(false)
  const [error, setError] = useState('')
  const [busy, setBusy] = useState(false)

  async function begin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    try {
      setSecret(await beginMfaSetup(password))
      setPassword('')
      try {
        setQrCode(await mfaQrCode())
      } catch {
        setQrError('Could not load the QR code. Use the setup key below or try loading it again.')
      }
    } catch {
      setError('Could not start setup. Check your password and try again.')
    } finally {
      setBusy(false)
    }
  }

  async function confirm(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    try {
      await confirmMfaSetup(code)
      setConfirmed(true)
      setSecret('')
      setQrCode('')
      setQrError('')
      setCode('')
      setCodes(await getRecoveryCodes())
    } catch {
      setError('Could not complete setup or retrieve recovery codes. Check your code, then try again.')
    } finally {
      setBusy(false)
    }
  }

  async function retryQrCode() {
    setBusy(true)
    setQrError('')
    try {
      setQrCode(await mfaQrCode())
    } catch {
      setQrError('Could not load the QR code. Use the setup key below or try loading it again.')
    } finally {
      setBusy(false)
    }
  }

  async function retrieveCodes(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError('')
    setBusy(true)
    try {
      setCodes(await recoveryCodes(password))
      setPassword('')
    } catch {
      setError('Could not retrieve recovery codes. Check your password and try again.')
    } finally {
      setBusy(false)
    }
  }

  async function finish() {
    setBusy(true)
    setError('')
    try {
      await onComplete()
    } catch {
      setError('Could not refresh your session. Sign out and sign in again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <>
      <h3>Protect your account</h3>
      {codes ? (
        <>
          <p className="auth-intro">Save these recovery codes somewhere safe. Each code can be used once if you lose access to your authenticator.</p>
          <ul className="recovery-list">{codes.map((item) => <li key={item}><code>{item}</code></li>)}</ul>
          <button className="button button-primary auth-submit" disabled={busy} onClick={finish} type="button">I saved my codes</button>
        </>
      ) : confirmed ? (
        <form className="auth-form" onSubmit={retrieveCodes}>
          <p className="auth-intro">Confirm your password to view your recovery codes.</p>
          <label htmlFor="recovery-password">Password</label>
          <input autoComplete="current-password" id="recovery-password" onChange={(event) => setPassword(event.target.value)} required type="password" value={password} />
          <button className="button button-primary auth-submit" disabled={busy} type="submit">Show recovery codes</button>
        </form>
      ) : secret ? (
        <form className="auth-form" onSubmit={confirm}>
          <p className="auth-intro">Scan this QR code with your authenticator app, or enter the setup key manually. Keep both private, then enter the code from your app.</p>
          {qrCode && <img className="mfa-qr" src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrCode)}`} alt="Authenticator setup QR code" />}
          {qrError && <><p className="auth-error" role="alert">{qrError}</p><button type="button" disabled={busy} onClick={() => { void retryQrCode() }}>Try loading QR code again</button></>}
          <p className="auth-intro">Manual setup key</p>
          <code className="mfa-secret">{secret}</code>
          <label htmlFor="setup-code">Authenticator code</label>
          <input autoComplete="one-time-code" id="setup-code" onChange={(event) => setCode(event.target.value)} required value={code} />
          <button className="button button-primary auth-submit" disabled={busy} type="submit">{busy ? 'Checking...' : 'Confirm setup'}</button>
        </form>
      ) : (
        <form className="auth-form" onSubmit={begin}>
          <p className="auth-intro">Multi-factor authentication is required. Confirm your password to set up an authenticator app.</p>
          <label htmlFor="setup-password">Password</label>
          <input autoComplete="current-password" id="setup-password" onChange={(event) => setPassword(event.target.value)} required type="password" value={password} />
          <button className="button button-primary auth-submit" disabled={busy} type="submit">{busy ? 'Starting...' : 'Start setup'}</button>
        </form>
      )}
      {error && <p className="auth-error" role="alert">{error}</p>}
      <button className="text-link auth-sign-out" disabled={busy} onClick={() => { void onSignOut().catch(() => setError('Could not sign out. Try again.')) }} type="button">Sign out</button>
    </>
  )
}
