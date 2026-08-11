import { useEffect, useRef } from 'react'
import { Form } from '@inlayphp/forms-react'
import type { FormErrors, FormResource } from '@inlayphp/forms-react'

export type RelationDialogProps = {
  form: FormResource
  errors: FormErrors
  heading: string
  name: string
  processing: boolean
  onClose: () => void
  onSubmit: (data: Record<string, unknown>) => void | Promise<void>
}

export function RelationDialog({
  form,
  errors,
  heading,
  name,
  processing,
  onClose,
  onSubmit,
}: RelationDialogProps) {
  const closeButton = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    closeButton.current?.focus()
    const close = (event: KeyboardEvent) => {
      if (event.key === 'Escape' && !processing) onClose()
    }
    document.addEventListener('keydown', close)
    return () => document.removeEventListener('keydown', close)
  }, [onClose, processing])

  return <div
    className="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-(--inlay-overlay) p-4"
    data-slot="relation-dialog-backdrop"
    onMouseDown={event => {
      if (event.target === event.currentTarget && !processing) onClose()
    }}
  >
    <section
      aria-labelledby={`inlay-relation-dialog-${name}`}
      aria-modal="true"
      className="w-full max-w-xl rounded-(--inlay-radius) bg-(--inlay-surface) p-5 text-(--inlay-text) shadow-xl ring-1 ring-(--inlay-border) sm:p-6"
      role="dialog"
    >
      <header className="flex items-start justify-between gap-4 border-b border-(--inlay-border) pb-4">
        <div>
          <h3 className="text-xl font-semibold tracking-tight" id={`inlay-relation-dialog-${name}`}>{heading}</h3>
          <p className="text-base/7 text-(--inlay-muted) sm:text-sm/6">Changes are validated and saved through the owner relationship.</p>
        </div>
        <button
          aria-label="Close"
          className="relative rounded-(--inlay-radius) p-2 text-(--inlay-muted) hover:bg-(--inlay-surface-muted) hover:text-(--inlay-text) focus-visible:outline-2 focus-visible:outline-(--inlay-accent)"
          disabled={processing}
          onClick={onClose}
          ref={closeButton}
          type="button"
        >
          <span aria-hidden="true">×</span>
          <span aria-hidden="true" className="absolute top-1/2 left-1/2 size-[max(100%,3rem)] -translate-1/2 pointer-fine:hidden" />
        </button>
      </header>
      <div className="pt-5">
        <Form errors={errors} onSubmit={onSubmit} processing={processing} resource={form} />
      </div>
    </section>
  </div>
}
