import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import {
  ApiError,
  completeTwoFactorChallenge,
  currentSession,
  login,
  logout,
  type CurrentSession,
} from './api/session'
import { MfaSetup } from './MfaSetup'
import { Dashboard } from './Dashboard'
import { Invitation } from './Invitation'
import { PlatformDashboard } from './PlatformDashboard'
import { Onboarding } from './Onboarding'
import { LicenseRequired } from './LicenseRequired'
import { Loading } from './Loading'
import './App.css'

function takeInvitationToken(): string {
  const match = /^#invite=([a-f0-9]{64})$/.exec(window.location.hash)
  if (match) window.history.replaceState(null, '', window.location.pathname + window.location.search)
  return match?.[1] ?? ''
}

const portalRoot = document.getElementById('root')
const syntheticPortalEnabled = portalRoot?.dataset.portalAccess === 'synthetic'
const isProductionDeployment = portalRoot?.dataset.publicEnvironment === 'production'
const invitationToken = syntheticPortalEnabled ? takeInvitationToken() : ''
const currentYear = new Date().getFullYear()

function Icon({ children, className = '' }: { children: ReactNode; className?: string }) {
  return (
    <svg aria-hidden="true" className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round">
      {children}
    </svg>
  )
}

const features = [
  {
    number: '01',
    icon: <Icon><rect x="3" y="4" width="18" height="16" rx="2" /><path d="M3 9h18M8 13h3M8 16h6" /></Icon>,
    title: 'Connect facility EHRs',
    description: 'We are working towards interoperable connections between participating facilities and compatible health systems.',
  },
  {
    number: '02',
    icon: <Icon><path d="M12 3 4 6v5c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-3Z" /><path d="m9 12 2 2 4-4" /></Icon>,
    title: 'Build a digital medical passport',
    description: 'We plan to connect MedPass with participating facilities so people can access and share records with consent, subject to clinical and privacy approval.',
  },
  {
    number: '03',
    icon: <Icon><path d="M4 13a8 8 0 0 1 14-5M20 11a8 8 0 0 1-14 5" /><path d="M18 4v4h-4M6 20v-4h4" /></Icon>,
    title: 'Keep health records person-centered',
    description: 'We aim to help people access and share their health information across participating care settings, with appropriate consent and safeguards.',
  },
] as const

const benefits = [
  {
    icon: <Icon><rect x="3" y="4" width="18" height="14" rx="2" /><path d="M8 21h8M12 18v3M6 13l3-3 3 2 4-4 2 2" /></Icon>,
    title: 'Designed for healthcare teams',
    description: 'We are shaping MediSphere around the everyday needs of facilities, from independent clinics and pharmacies to hospital groups.',
  },
  {
    icon: <Icon><path d="M12 20s-8-4.5-8-10a4 4 0 0 1 8-1 4 4 0 0 1 8 1c0 5.5-8 10-8 10Z" /><path d="M9 12h6M12 9v6" /></Icon>,
    title: 'Keep people at the center',
    description: 'Our vision is person-centered health record management, with information shared only through approved workflows.',
  },
  {
    icon: <Icon><path d="M3 20h18M5 20V8l5-3v15M10 20V4h8v16M7 11h1M7 14h1M13 8h2M13 11h2M13 14h2" /></Icon>,
    title: 'Plan for connected care',
    description: 'We are working toward interoperable connections between participating facilities and compatible health systems.',
  },
] as const

const facilityTypes = [
  {
    icon: <Icon><path d="M8 3h8v3l2 2v12H6V8l2-2V3ZM8 6h8M9 14h6M12 11v6" /></Icon>,
    title: 'Pharmacies',
    description: 'For teams that serve patients and manage medicines.',
  },
  {
    icon: <Icon><path d="M4 20h16M6 20V7l6-3 6 3v13M9 20v-5h6v5M10 10h4M12 8v4" /></Icon>,
    title: 'Clinics',
    description: 'For clinics providing everyday consultations and care.',
  },
  {
    icon: <Icon><path d="M9 3h6M10 3v6l-5 8a3 3 0 0 0 3 4h8a3 3 0 0 0 3-4l-5-8V3M8 15h8" /></Icon>,
    title: 'Diagnostic centers',
    description: 'For centers providing tests and diagnostic services.',
  },
  {
    icon: <Icon><path d="M12 20s-8-4.5-8-10a4 4 0 0 1 8-1 4 4 0 0 1 8 1c0 5.5-8 10-8 10Z" /><path d="M12 10v5M9.5 12.5h5" /></Icon>,
    title: 'Maternity clinics',
    description: 'For teams caring for mothers and babies.',
  },
  {
    icon: <Icon><path d="M3 20h18M5 20V5h14v15M9 20v-5h6v5M10 9h4M12 7v4" /></Icon>,
    title: 'Hospitals',
    description: 'For hospitals bringing different care teams and services together.',
  },
  {
    icon: <Icon><path d="M3 20h18M5 20V8l5-3v15M10 20V4h8v16M7 11h1M7 14h1M13 8h2M13 11h2M13 14h2" /></Icon>,
    title: 'Hospital groups',
    description: 'For organizations managing a hospital with multiple branches.',
  },
] as const

function App() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [challengeCode, setChallengeCode] = useState('')
  const [useRecoveryCode, setUseRecoveryCode] = useState(false)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [requiresTwoFactor, setRequiresTwoFactor] = useState(false)
  const [session, setSession] = useState<CurrentSession | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [checkingSession, setCheckingSession] = useState(syntheticPortalEnabled)
  const [inviteToken, setInviteToken] = useState(invitationToken)
  const [mobileMenuOpen, setMobileMenuOpen] = useState(false)
  const [organizationWorkspace, setOrganizationWorkspace] = useState(false)
  const [refreshingOnboarding, setRefreshingOnboarding] = useState(false)
  const [onboardingRefreshError, setOnboardingRefreshError] = useState(false)

  useEffect(() => {
    if (!syntheticPortalEnabled) return

    function handleInvitationLink() {
      const token = takeInvitationToken()
      if (token) setInviteToken(token)
    }
    window.addEventListener('hashchange', handleInvitationLink)
    handleInvitationLink()
    return () => window.removeEventListener('hashchange', handleInvitationLink)
  }, [])

  useEffect(() => {
    if (!syntheticPortalEnabled) {
      return
    }

    let active = true
    currentSession()
      .then((current) => { if (active) setSession(current) })
      .catch((cause: unknown) => {
        if (active && !(cause instanceof ApiError && cause.status === 401)) {
          setError('Could not check your session. Check your connection and try again.')
        }
      })
      .finally(() => { if (active) setCheckingSession(false) })
    return () => { active = false }
  }, [])

  useEffect(() => {
    if (!syntheticPortalEnabled) return

    let active = true
    let running = false
    function refreshOnboarding() {
      if (running) return
      running = true
      setRefreshingOnboarding(true)
      setOnboardingRefreshError(false)
      currentSession().then((current) => {
        if (active) setSession(current)
      }).catch(() => {
        if (active) setOnboardingRefreshError(true)
      }).finally(() => {
        running = false
        if (active) setRefreshingOnboarding(false)
      })
    }
    window.addEventListener('mediflow:onboarding-required', refreshOnboarding)
    return () => { active = false; window.removeEventListener('mediflow:onboarding-required', refreshOnboarding) }
  }, [])

  async function handleLogin(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      const response = await login(email, password)

      if (response.two_factor) {
        setRequiresTwoFactor(true)
        setPassword('')
        return
      }

      setSession(await currentSession())
      setPassword('')
    } catch {
      setError('Sign-in could not be completed. Check your details and try again.')
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleTwoFactorChallenge(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    setError(null)
    setIsSubmitting(true)

    try {
      await completeTwoFactorChallenge(challengeCode, useRecoveryCode)
      setSession(await currentSession())
      setChallengeCode('')
    } catch {
      setError('The verification code could not be confirmed. Try again.')
    } finally {
      setIsSubmitting(false)
    }
  }

  async function handleSignOut() {
    try {
      await logout()
    } catch (cause) {
      if (!(cause instanceof ApiError && cause.status === 401)) throw cause
    }
    setSession(null)
    setOrganizationWorkspace(false)
    setRequiresTwoFactor(false)
    setChallengeCode('')
    setError(null)
    setOnboardingRefreshError(false)
  }

  if (refreshingOnboarding || onboardingRefreshError) {
    return <main className="invitation-page"><div className="auth-card invitation-card">
      {refreshingOnboarding ? <Loading message="Checking organization onboarding..." /> : <>
        <p className="auth-error" role="alert">Could not check your onboarding status. Try again or sign out.</p>
        <button type="button" onClick={() => window.dispatchEvent(new Event('mediflow:onboarding-required'))}>Try again</button>
        <button type="button" onClick={() => { void handleSignOut().catch(() => setError('Could not sign out. Try again.')) }}>Sign out</button>
        {error && <p className="auth-error" role="alert">{error}</p>}
      </>}
    </div></main>
  }

  if (inviteToken) {
    return <Invitation token={inviteToken} session={session} checking={checkingSession} onSignOut={handleSignOut} onClose={() => { setInviteToken(''); window.location.hash = 'access' }} />
  }

  if (checkingSession) return <main><Loading page message="Checking your session..." /></main>

  if (session?.mfa === 'setup_required') {
    return <main className="invitation-page"><div className="auth-card invitation-card">
      <img className="brand-logo" src="/mediflow-logo.png" alt="MediSphere" />
      <MfaSetup onComplete={async () => setSession(await currentSession())} onSignOut={handleSignOut} />
    </div></main>
  }

  if (session?.mfa === 'verified') {
    if (session.synthetic_platform_admin && !organizationWorkspace) return <PlatformDashboard key={session.id} session={session} onSignOut={handleSignOut} onOrganizationWorkspace={() => setOrganizationWorkspace(true)} />
    if (session.onboarding_required) return <Onboarding key={session.id} onComplete={async () => setSession(await currentSession())} onSignOut={handleSignOut} onPlatformWorkspace={session.synthetic_platform_admin ? () => setOrganizationWorkspace(false) : undefined} />
    if (session.license_required) return <LicenseRequired key={session.id} onRefresh={async () => setSession(await currentSession())} onSignOut={handleSignOut} onPlatformWorkspace={session.synthetic_platform_admin ? () => setOrganizationWorkspace(false) : undefined} />
    return <Dashboard key={session.id} session={session} onSignOut={handleSignOut} onPlatformWorkspace={session.synthetic_platform_admin ? () => setOrganizationWorkspace(false) : undefined} />
  }

  return (
    <>
      <a className="skip-link" href="#main-content">Skip to content</a>
      <header className="site-header" onKeyDown={(event) => {
        if (event.key === 'Escape') setMobileMenuOpen(false)
      }}>
        <div className="container header-inner">
          <a className="brand" href="#top" aria-label="MediSphere home">
            <img className="brand-logo" src="/mediflow-logo.png" alt="" />
            <span>Medi<span className="brand-accent">Sphere</span><small>PLATFORM</small></span>
          </a>
          <nav className="desktop-nav" aria-label="Main navigation">
            <a href="#approach">Our purpose</a>
            <a href="#why-medisphere">Why MediSphere</a>
            <a href="#who-its-for">Who it&apos;s for</a>
            <a href="#possibilities">The platform</a>
            <a href="#contact">Contact us</a>
          </nav>
          <button
            className="mobile-menu-toggle"
            type="button"
            aria-controls="mobile-navigation"
            aria-expanded={mobileMenuOpen}
            aria-label={mobileMenuOpen ? 'Close navigation menu' : 'Open navigation menu'}
            onClick={() => setMobileMenuOpen((isOpen) => !isOpen)}
          >
            <span>{mobileMenuOpen ? 'Close' : 'Menu'}</span>
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round">
              {mobileMenuOpen ? <path d="m6 6 12 12M18 6 6 18" /> : <path d="M4 6h16M4 12h16M4 18h16" />}
            </svg>
          </button>
          {syntheticPortalEnabled && (
            <a className="header-link" href="#access" aria-label="Portal sign in, test environment" onClick={() => setMobileMenuOpen(false)}>
              <span className="header-label">Portal sign in (test)</span><span className="header-arrow" aria-hidden="true">↗</span>
            </a>
          )}
        </div>
        <nav id="mobile-navigation" className={`mobile-nav${mobileMenuOpen ? ' is-open' : ''}`} aria-label="Mobile navigation">
          <a href="#approach" onClick={() => setMobileMenuOpen(false)}>Our purpose</a>
          <a href="#why-medisphere" onClick={() => setMobileMenuOpen(false)}>Why MediSphere</a>
          <a href="#who-its-for" onClick={() => setMobileMenuOpen(false)}>Who it&apos;s for</a>
          <a href="#possibilities" onClick={() => setMobileMenuOpen(false)}>The platform</a>
          <a href="#contact" onClick={() => setMobileMenuOpen(false)}>Contact us</a>
        </nav>
      </header>

      <main id="main-content" tabIndex={-1}>
        <section className="hero-section" id="top" aria-labelledby="hero-title">
          <div className="container hero-grid">
            <div className="hero-copy">
              <p className="eyebrow"><span className="eyebrow-dot" /> HEALTHCARE SOFTWARE FOR SOKOTO AND BEYOND</p>
              <p className="development-tag"><span aria-hidden="true" />{isProductionDeployment ? 'Platform in development' : 'Development preview'}</p>
              <h1 id="hero-title">Where Every Medical Record <span className="heading-accent">Connects.</span></h1>
              <p className="hero-description">
                We are building connected healthcare technology to bring patients, hospitals, specialists,
                laboratories, and pharmacies together—starting in Sokoto and reaching beyond.
              </p>
              <div className="hero-actions">
                <a className="button button-primary" href="#contact">Talk to our team <span aria-hidden="true">↗</span></a>
                {syntheticPortalEnabled && <a className="text-link" href="#access">Portal sign in (test) <span aria-hidden="true">↓</span></a>}
              </div>
              <div className="hero-note">
                <span className="note-icon"><Icon><path d="M12 3 4 6v5c0 5 3.5 8 8 10 4.5-2 8-5 8-10V6l-8-3Z" /><path d="m9 12 2 2 4-4" /></Icon></span>
                <span>Designed for healthcare facilities<br /><strong>Built with patients and care teams in mind.</strong></span>
              </div>
            </div>

          </div>
        </section>

        <section className="intro-section" id="approach" aria-labelledby="approach-title">
          <div className="container intro-grid">
            <div><p className="eyebrow"><span className="section-index">01 /</span> OUR PURPOSE</p></div>
            <div><h2 id="approach-title">Better healthcare starts here<span className="accent-period">.</span></h2>
              <p>Healthcare teams need simple software that supports their daily work and helps them care for patients. That is why we are building MediSphere.</p>
              <div className="purpose-grid">
                <article className="purpose-card">
                  <h3>Our mission</h3>
                  <p>To help healthcare facilities use simple digital tools, starting in Sokoto and reaching communities beyond.</p>
                </article>
                <article className="purpose-card">
                  <h3>Our vision</h3>
                  <p>A future where healthcare facilities and patients in Sokoto and beyond can use digital services to connect and access the information they need for better care.</p>
                </article>
              </div>
            </div>
          </div>
        </section>

        <section className="why-section" id="why-medisphere" aria-labelledby="why-title">
          <div className="container">
            <div className="section-heading">
              <div><p className="eyebrow"><span className="section-index">02 /</span> OUR APPROACH</p><h2 id="why-title">Designed around people.<br /><span className="heading-accent">Built for connected care.</span></h2></div>
                <p>MediSphere is being developed to support care teams and patients through thoughtful, interoperable digital tools.</p>
            </div>
            <div className="benefit-grid">
              {benefits.map((benefit) => (
                <article className="benefit-card" key={benefit.title}>
                  <span className="feature-icon">{benefit.icon}</span>
                  <h3>{benefit.title}</h3>
                  <p>{benefit.description}</p>
                </article>
              ))}
            </div>
            <p className="availability-note"><strong>In development:</strong> These principles guide the platform we are building; they are not a statement that all services are currently available.</p>
          </div>
        </section>

        <section className="facilities-section" id="who-its-for" aria-labelledby="facilities-title">
          <div className="container">
            <div className="section-heading">
              <div><p className="eyebrow"><span className="section-index">03 /</span> WHO IT&apos;S FOR</p><h2 id="facilities-title">For the people <span className="heading-accent">providing care.</span></h2></div>
              <p>We are designing MediSphere for different types of healthcare organizations, from a single facility to a hospital with many branches.</p>
            </div>
            <div className="benefit-grid">
              {facilityTypes.map((facility) => (
                <article className="benefit-card facility-card" key={facility.title}>
                  <span className="feature-icon">{facility.icon}</span>
                  <h3>{facility.title}</h3>
                  <p>{facility.description}</p>
                </article>
              ))}
            </div>
            <p className="availability-note"><strong>In development:</strong> Support for these facility types is planned. Features and availability will be confirmed before launch.</p>
          </div>
        </section>

        <section className="features-section" id="possibilities" aria-labelledby="features-title">
          <div className="container">
            <div className="section-heading">
              <div><p className="eyebrow"><span className="section-index">04 /</span> WHAT WE ARE BUILDING</p><h2 id="features-title">Simple tools.<br /><span className="heading-accent">Better connected care.</span></h2></div>
              <p>We are planning an interoperable ecosystem that brings facility EHRs, a digital medical passport, and person-centered health records together.</p>
            </div>
            <div className="feature-grid">
              {features.map((feature) => (
                <article className="feature-card" key={feature.number}>
                  <div className="feature-top"><span className="feature-icon">{feature.icon}</span><span className="feature-number">{feature.number}</span></div>
                  <h3>{feature.title}</h3><p>{feature.description}</p>
                </article>
              ))}
            </div>
            <p className="availability-note"><strong>{isProductionDeployment ? 'Not available in this deployment:' : 'Not available in this preview:'}</strong> Facility monitoring, MedPass connections, backups, license management, and provider services are still planned. Patient-data exchange will remain off until release requirements are met.</p>
          </div>
        </section>

        <section className="access-section" id="access" aria-labelledby="access-title">
          <div className="container access-grid">
            <div className="access-copy">
              <p className="eyebrow"><span className="section-index">05 /</span> PORTAL ACCESS</p>
              <h2 id="access-title">Welcome to <span className="heading-accent">MediSphere.</span></h2>
              {syntheticPortalEnabled ? (
                <>
                  <p>Have an administrator account? Sign in to access the current test environment.</p>
                  <div className="access-reminder"><Icon><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></Icon><span>This is a test environment. Use made-up data only, not real patient or facility information.</span></div>
                </>
              ) : (
                <p>Portal sign-in is not enabled for this deployment. Contact us for product and rollout updates.</p>
              )}
            </div>
            {syntheticPortalEnabled ? (
              <div className="auth-card">
              <div className="auth-card-top"><span className="auth-icon"><Icon><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></Icon></span><span className="environment-tag">TEST ENVIRONMENT</span></div>
              {checkingSession ? (
                <Loading message="Checking your session..." />
              ) : session?.mfa === 'sign_in_again' ? (
                <div className="signed-in"><h3>Sign in again</h3><p>To protect your account, sign out and sign in with your authenticator code.</p><button className="text-link auth-sign-out" onClick={() => { void handleSignOut().catch(() => setError('Could not sign out. Try again.')) }} type="button">Sign out</button></div>
              ) : session ? (
                <div role="status" className="signed-in"><h3>Welcome, {session.name}.</h3><p>You are signed in.</p><button className="text-link auth-sign-out" onClick={() => { void handleSignOut().catch(() => setError('Could not sign out. Try again.')) }} type="button">Sign out</button></div>
              ) : requiresTwoFactor ? (
                <>
                  <h3>Verify your sign-in</h3><p className="auth-intro">Enter your {useRecoveryCode ? 'recovery code' : 'authenticator code'} to continue.</p>
                  <form className="auth-form" onSubmit={handleTwoFactorChallenge}>
                    <label htmlFor="verification-code">{useRecoveryCode ? 'Recovery code' : 'Authenticator code'}</label>
                    <input autoComplete="one-time-code" id="verification-code" onChange={(event) => setChallengeCode(event.target.value)} required value={challengeCode} />
                    <label className="checkbox" htmlFor="recovery-code"><input checked={useRecoveryCode} id="recovery-code" onChange={(event) => { setUseRecoveryCode(event.target.checked); setChallengeCode(''); setError(null) }} type="checkbox" /> Use a recovery code</label>
                    <button className="button button-primary auth-submit" disabled={isSubmitting} type="submit">{isSubmitting ? 'Verifying...' : 'Verify sign-in'} <span aria-hidden="true">→</span></button>
                  </form>
                </>
              ) : (
                <>
                  <h3>Sign in to MediSphere</h3><p className="auth-intro">Use your administrator account to continue.</p>
                  <form className="auth-form" onSubmit={handleLogin}>
                    <label htmlFor="email">Email address</label>
                    <input autoComplete="username" id="email" onChange={(event) => setEmail(event.target.value)} placeholder="you@organization.com" required type="email" value={email} />
                    <label htmlFor="password">Password</label>
                    <input autoComplete="current-password" id="password" onChange={(event) => setPassword(event.target.value)} placeholder="Enter your password" required type="password" value={password} />
                    <button className="button button-primary auth-submit" disabled={isSubmitting} type="submit">{isSubmitting ? 'Signing in...' : 'Sign in'} <span aria-hidden="true">→</span></button>
                  </form>
                </>
              )}
              {error && <p className="auth-error" role="alert">{error}</p>}
              <p className="auth-footer">Need access? Contact your organization administrator.</p>
              </div>
            ) : (
              <aside className="auth-card portal-unavailable" aria-label="Portal availability">
                <div className="auth-card-top"><span className="auth-icon"><Icon><rect x="5" y="10" width="14" height="11" rx="2" /><path d="M8 10V7a4 4 0 0 1 8 0v3M12 14v3" /></Icon></span><span className="environment-tag">ACCESS DISABLED</span></div>
                <h3>Portal access is not available here.</h3>
                <p className="auth-intro">This public page does not provide production account access. Services and integrations remain subject to release approval.</p>
                <a className="button button-primary auth-submit" href="#contact">Contact our team <span aria-hidden="true">→</span></a>
              </aside>
            )}
          </div>
        </section>

        <section className="contact-section" id="contact" aria-labelledby="contact-title">
          <div className="container">
            <div className="contact-panel">
              <div className="contact-copy">
                <p className="eyebrow"><span className="section-index">06 /</span> CONTACT US</p>
                <h2 id="contact-title">Let&apos;s connect<span className="heading-accent">.</span></h2>
                <p>Have a question or want to explore how MediSphere could support your organization? Get in touch with our team.</p>
              </div>
              <div className="contact-actions">
                <a className="contact-link" href="mailto:isahlabbo22@gmail.com">
                  <span className="contact-icon" aria-hidden="true"><Icon><rect x="3" y="5" width="18" height="14" rx="2" /><path d="m3 7 9 6 9-6" /></Icon></span>
                  <span><small>Email us</small><strong>isahlabbo22@gmail.com</strong></span>
                </a>
                <a className="contact-link" href="tel:+238162463010">
                  <span className="contact-icon" aria-hidden="true"><Icon><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.4 19.4 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 2.8a2 2 0 0 1-.6 1.8L7.7 9.6a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 1.8-.6l2.8.5a2 2 0 0 1 1.7 2Z" /></Icon></span>
                  <span><small>Call us</small><strong>+238 162 463 010</strong></span>
                </a>
                <p className="contact-privacy-note">Please do not send patient records or sensitive health information by email or phone.</p>
              </div>
            </div>
          </div>
        </section>
      </main>
      <footer className="site-footer">
        <div className="container footer-inner">
          <a className="brand footer-brand" href="#top" aria-label="MediSphere home"><img className="brand-logo" src="/mediflow-logo.png" alt="" /><span>Medi<span className="brand-accent">Sphere</span><small>PLATFORM</small></span></a>
          <p>Working towards better healthcare in Sokoto and beyond.</p>
          <a className="footer-contact-link" href="#contact">Contact us</a>
          <span>&copy; {currentYear} MediSphere</span>
        </div>
      </footer>
    </>
  )
}

export default App
