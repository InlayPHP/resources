export type RelationCapabilities = {
  softDeletes?: boolean
  create: boolean
  edit: boolean
  delete: boolean
  attach: boolean
  detach: boolean
  associate?: boolean
  dissociate?: boolean
}

export type RelationEndpoints = {
  create: string
  update: string
  delete: string
  attach: string
  detach: string
  attachOptions?: string
  associate?: string
  dissociate?: string
  associateOptions?: string
}

export type RelationGroupResource = {
  contract: 'inlay.resources.relation-group.v1'
  id: string
  label: string
  description: string | null
  icon: string | null
  defaultRelation: string
  contained: boolean
}

export type RelationManagerResource<TableResource = unknown, FormResource = unknown> = {
  contract: 'inlay.resources.relation-manager.v1'
  name: string
  title: string
  recordTitleAttribute: string | null
  readOnly: boolean
  group?: RelationGroupResource | null
  table: TableResource
  createForm: FormResource | null
  editForm?: FormResource | null
  attachForm?: FormResource | null
  associateForm?: FormResource | null
  capabilities: RelationCapabilities
  endpoints: RelationEndpoints | null
}

export type RelationMutationResponse = {
  contract: 'inlay.resources.relation-mutation.v1'
  operation: 'create' | 'edit' | 'attach' | 'associate' | 'delete' | 'restore' | 'force-delete'
  record: Record<string, unknown>
}

export type RelationMutationRequest = {
  url: string
  method: 'post' | 'patch' | 'delete'
  data?: Record<string, unknown>
  signal?: AbortSignal
}

export class RelationMutationError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly errors: Record<string, string[]> = {},
  ) {
    super(message)
    this.name = 'RelationMutationError'
  }
}

export function relationEndpoint(template: string, record: string | number): string {
  return template.replace('{record}', encodeURIComponent(String(record)))
}

export async function mutateRelation({
  url,
  method,
  data = {},
  signal,
}: RelationMutationRequest): Promise<RelationMutationResponse | null> {
  const response = await fetch(url, {
    method: method.toUpperCase(),
    body: method === 'delete' ? undefined : JSON.stringify(data),
    credentials: 'same-origin',
    signal,
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() as string } : {}),
    },
  })
  if (!response.ok) {
    const payload = await json(response)
    throw new RelationMutationError(
      typeof payload?.message === 'string' ? payload.message : 'The relationship mutation failed.',
      response.status,
      isErrorBag(payload?.errors) ? payload.errors : {},
    )
  }
  if (response.status === 204) return null

  return await response.json() as RelationMutationResponse
}

function csrfToken(): string | null {
  if (typeof document === 'undefined') return null
  return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? null
}

async function json(response: Response): Promise<Record<string, unknown> | null> {
  try {
    return await response.json() as Record<string, unknown>
  } catch {
    return null
  }
}

function isErrorBag(value: unknown): value is Record<string, string[]> {
  return typeof value === 'object' && value !== null && Object.values(value).every(
    messages => Array.isArray(messages) && messages.every(message => typeof message === 'string'),
  )
}
