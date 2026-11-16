import './Loading.css'

export function Loading({ message, page = false }: { message: string; page?: boolean }) {
  return <div className={`loading${page ? ' loading-page' : ''}`} role="status" aria-live="polite">
    <span className="loading-spinner" aria-hidden="true" />
    <span>{message}</span>
  </div>
}
