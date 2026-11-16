import { useEffect, useState, type FormEvent } from 'react'
import { csrfToken, request } from './api/session'
import { managementError, type OrganizationRole } from './api/management'
import { Loading } from './Loading'

export function PlatformRoles() {
  const [roles, setRoles] = useState<OrganizationRole[] | null>(null)
  const [permissions, setPermissions] = useState<string[]>([])
  const [selected, setSelected] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [grants, setGrants] = useState<string[]>([])
  const [error, setError] = useState('')
  const [notice, setNotice] = useState('')
  const [busy, setBusy] = useState(false)
  const [reload, setReload] = useState(0)

  useEffect(() => {
    let active = true
    request<{ data: OrganizationRole[]; permissions: string[] }>('/api/v1/platform/roles')
      .then((result) => { if (active) { setRoles(result.data); setPermissions(result.permissions); setError('') } })
      .catch((cause: unknown) => { if (active) setError(managementError(cause)) })
    return () => { active = false }
  }, [reload])

  function edit(role: OrganizationRole) {
    setSelected(role.id); setName(role.name); setGrants(role.permissions); setNotice(''); setError('')
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()
    if (selected && !window.confirm('This permission change applies immediately to every member assigned this role in every organization. Continue?')) return
    setBusy(true); setError(''); setNotice('')
    try {
      const csrf = await csrfToken()
      await request(`/api/v1/platform/roles${selected ? `/${encodeURIComponent(selected)}` : ''}`, {
        method: selected ? 'PATCH' : 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
        body: JSON.stringify({ name, permissions: grants }),
      })
      setSelected(null); setName(''); setGrants([]); setReload((value) => value + 1)
      setNotice('Role saved. Changes apply immediately to assigned users.')
    } catch (cause) { setError(managementError(cause)) } finally { setBusy(false) }
  }

  return <div className="dashboard-data">
    {error && <p className="auth-error" role="alert">{error}</p>}
    {notice && <p role="status">{notice}</p>}
    <section className="dashboard-panel"><h2>{selected ? 'Edit role' : 'Create role'}</h2>
      <p>Roles apply across organizations. Administrators may assign only roles within their own permissions.</p>
      <form className="management-form" onSubmit={save}>
        <label htmlFor="role-name">Role name</label>
        <input id="role-name" required maxLength={100} value={name} onChange={(event) => setName(event.target.value)} />
        <fieldset><legend>Permissions</legend>
          {permissions.map((permission) => <label key={permission} style={{ display: 'block' }}>
            <input type="checkbox" checked={grants.includes(permission)} onChange={(event) => setGrants(event.target.checked ? [...grants, permission] : grants.filter((item) => item !== permission))} /> {permission.replace('.', ' — ')}
          </label>)}
        </fieldset>
        <div className="management-actions"><button className="button button-primary" disabled={busy || permissions.length === 0} type="submit">Save role</button>
          {selected && <button type="button" onClick={() => { setSelected(null); setName(''); setGrants([]) }}>Cancel</button>}</div>
      </form>
    </section>
    <section className="dashboard-panel"><h2>Organization roles</h2>
      {!roles && !error && <Loading message="Loading roles..." />}
      {roles && (roles.length ? <ul className="management-facilities">{roles.map((role) => <li key={role.id}><div><strong>{role.name}</strong><br /><span>{role.permissions.join(', ') || 'No permissions'}</span></div><button type="button" onClick={() => edit(role)}>Edit</button></li>)}</ul> : <p>No custom roles configured.</p>)}
      {!roles && error && <button type="button" onClick={() => setReload((value) => value + 1)}>Try again</button>}
    </section>
  </div>
}
