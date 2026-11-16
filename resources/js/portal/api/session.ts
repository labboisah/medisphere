export type CurrentSession = {
  id: number
  name: string
  synthetic_platform_admin: boolean
  mfa: 'verified' | 'setup_required' | 'sign_in_again'
  onboarding_required: boolean
  license_required: boolean
}

type ApiEnvelope<T> = {
  data: T
}

type LoginResponse = {
  two_factor: boolean
}

export class ApiError extends Error {
  readonly status: number

  constructor(
    message: string,
    status: number,
  ) {
    super(message)
    this.status = status
  }
}

async function fetchApi(path: string, init: RequestInit = {}): Promise<Response> {
  const response = await fetch(path, {
    ...init,
    credentials: 'include',
    headers: {
      Accept: 'application/json',
      ...init.headers,
    },
  })

  if (!response.ok) {
    if (response.status === 403 && response.headers.get('X-Onboarding-Required') === '1') {
      window.dispatchEvent(new Event('mediflow:onboarding-required'))
    }
    if (response.status === 403 && response.headers.get('X-License-Required') === '1') {
      window.dispatchEvent(new Event('mediflow:onboarding-required'))
    }
    throw new ApiError('The request could not be completed.', response.status)
  }

  return response
}

export async function request<T>(path: string, init: RequestInit = {}): Promise<T> {
  const response = await fetchApi(path, init)

  return (await response.json()) as T
}

async function submit(path: string, init: RequestInit = {}): Promise<void> {
  await fetchApi(path, init)
}

export async function csrfToken(): Promise<string> {
  const response = await request<ApiEnvelope<{ token: string }>>('/api/v1/csrf-token')

  return response.data.token
}

export async function login(email: string, password: string): Promise<LoginResponse> {
  const token = await csrfToken()

  return request<LoginResponse>('/api/v1/auth/login', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
    },
    body: JSON.stringify({ email, password }),
  })
}

export async function completeTwoFactorChallenge(
  code: string,
  useRecoveryCode: boolean,
): Promise<void> {
  const token = await csrfToken()
  const body = useRecoveryCode ? { recovery_code: code } : { code }

  await submit('/api/v1/auth/two-factor-challenge', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': token,
    },
    body: JSON.stringify(body),
  })
}

export async function currentSession(): Promise<CurrentSession> {
  const response = await request<ApiEnvelope<CurrentSession>>('/api/v1/session')

  return response.data
}

async function postAuth(path: string, body?: object): Promise<void> {
  const token = await csrfToken()
  await submit(`/api/v1/auth/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
}

export async function beginMfaSetup(password: string): Promise<string> {
  await postAuth('user/confirm-password', { password })
  await postAuth('user/two-factor-authentication')
  const result = await request<{ secretKey: string }>('/api/v1/auth/user/two-factor-secret-key')
  return result.secretKey
}

export async function mfaQrCode(): Promise<string> {
  const result = await request<{ svg?: string }>('/api/v1/auth/user/two-factor-qr-code', { cache: 'no-store' })
  if (!result.svg) throw new Error('The authenticator QR code is unavailable.')
  return result.svg
}

export async function confirmMfaSetup(code: string): Promise<void> {
  await postAuth('user/confirmed-two-factor-authentication', { code })
}

export async function getRecoveryCodes(): Promise<string[]> {
  return request<string[]>('/api/v1/auth/user/two-factor-recovery-codes', { cache: 'no-store' })
}

export async function recoveryCodes(password: string): Promise<string[]> {
  await postAuth('user/confirm-password', { password })
  return getRecoveryCodes()
}

export async function regenerateRecoveryCodes(password: string): Promise<string[]> {
  await postAuth('user/confirm-password', { password })
  await postAuth('user/two-factor-recovery-codes')
  return getRecoveryCodes()
}

export async function logout(): Promise<void> {
  await postAuth('logout')
}

export async function acceptInvitation(token: string, name: string, password: string, confirmation: string): Promise<void> {
  const csrf = await csrfToken()
  await submit('/api/v1/invitations/accept', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
    body: JSON.stringify({ token, name, password, password_confirmation: confirmation }),
  })
}
