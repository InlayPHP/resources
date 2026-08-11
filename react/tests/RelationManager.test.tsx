import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RelationManager, RelationManagers } from '../src'
import type { RelationManagerResource } from '../src'

vi.mock('@inertiajs/react', () => ({ router: { reload: vi.fn(), visit: vi.fn() } }))

afterEach(cleanup)
afterEach(() => vi.unstubAllGlobals())

const form = {
  contract: 'inlay.forms.v1' as const,
  type: 'form' as const,
  name: 'posts.create',
  action: '/users/1/relations/posts',
  method: 'post' as const,
  columns: 1,
  submitLabel: 'Create',
  validation: null,
  data: {},
  schema: [{
    type: 'text',
    rendererCategory: 'field',
    name: 'title',
    label: 'Title',
    statePath: 'title',
    required: true,
    disabled: false,
    readOnly: false,
    hidden: false,
    saved: true,
    columnSpan: 1,
    extraAttributes: {},
  }],
} as unknown as RelationManagerResource['createForm']

const attachForm = {
  ...form,
  name: 'posts.attach',
  submitLabel: 'Attach',
  data: {},
  schema: [
    {
      type: 'select',
      rendererCategory: 'field',
      name: 'record',
      label: 'Record',
      required: true,
      disabled: false,
      readOnly: false,
      hidden: false,
      saved: true,
      columnSpan: 1,
      extraAttributes: {},
      options: [{ value: 12, label: 'Available post' }],
      searchable: true,
      multiple: false,
      remoteOptions: null,
    },
    {
      type: 'text',
      rendererCategory: 'field',
      name: 'assignment_note',
      label: 'Assignment note',
      statePath: 'assignment_note',
      required: true,
      disabled: false,
      readOnly: false,
      hidden: false,
      saved: true,
      columnSpan: 1,
      extraAttributes: {},
    },
  ],
} as unknown as RelationManagerResource['attachForm']

const editForm = {
  ...form!,
  name: 'posts.edit',
  method: 'patch',
  submitLabel: 'Save',
  schema: [
    ...form!.schema,
    (attachForm as NonNullable<RelationManagerResource['attachForm']>).schema[1],
  ],
} as RelationManagerResource['editForm']

const resource: RelationManagerResource = {
  contract: 'inlay.resources.relation-manager.v1',
  name: 'posts',
  title: 'Posts',
  recordTitleAttribute: 'title',
  readOnly: false,
  table: {
    contract: 'inlay.tables.v1',
    type: 'table',
    name: 'posts',
    primaryKey: 'id',
    searchPlaceholder: 'Search',
    columns: [{ type: 'text', name: 'title', label: 'Title', sortable: false, searchable: false, toggleable: false, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false }],
    filters: [],
    actions: [],
    headerActions: [],
    bulkActions: [],
    rows: [{ id: 10, title: 'First post', assignment_note: 'Initial assignment' }],
    pagination: null,
    selectable: false,
    deferFilters: true,
    query: null,
    emptyState: { heading: 'No posts', description: null },
  },
  createForm: form,
  editForm,
  attachForm,
  capabilities: { create: true, edit: true, delete: true, attach: true, detach: true },
  endpoints: {
    create: '/users/1/relations/posts',
    update: '/users/1/relations/posts/{record}',
    delete: '/users/1/relations/posts/{record}',
    attach: '/users/1/relations/posts/{record}/attach',
    detach: '/users/1/relations/posts/{record}/detach',
    attachOptions: '/users/1/relations/posts/attach-options',
  },
}

const associationResource: RelationManagerResource = {
  ...resource,
  attachForm: null,
  associateForm: {
    ...attachForm!,
    name: 'posts.associate',
    submitLabel: 'Associate',
    schema: [{
      ...(attachForm as NonNullable<RelationManagerResource['attachForm']>).schema[0],
      options: [{ value: 13, label: 'Unowned post' }],
    }],
  } as RelationManagerResource['associateForm'],
  capabilities: {
    ...resource.capabilities,
    attach: false,
    detach: false,
    associate: true,
    dissociate: true,
  },
  endpoints: {
    ...resource.endpoints!,
    associate: '/users/1/relations/posts/{record}/associate',
    dissociate: '/users/1/relations/posts/{record}/dissociate',
    associateOptions: '/users/1/relations/posts/associate-options',
  },
}

describe('RelationManager', () => {
  it('renders PHP-defined relation groups as accessible tabs with the configured default', async () => {
    const group = {
      contract: 'inlay.resources.relation-group.v1' as const,
      id: 'user-data',
      label: 'User data',
      description: 'Manage connected records.',
      icon: 'heroicon-o-link',
      defaultRelation: 'roles',
      contained: true,
    }
    const roles: RelationManagerResource = {
      ...associationResource,
      name: 'roles',
      title: 'Roles',
      group,
      table: {
        ...associationResource.table,
        name: 'roles',
        rows: [{ id: 20, title: 'Reviewer', assignment_note: null }],
      },
    }
    render(<RelationManagers resources={[{ ...resource, group }, roles]} />)

    expect(screen.getByRole('heading', { name: 'User data' })).toBeInTheDocument()
    expect(screen.getByText('Manage connected records.')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Roles' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Reviewer')).toBeInTheDocument()
    expect(screen.queryByText('First post')).not.toBeInTheDocument()

    screen.getByRole('tab', { name: 'Roles' }).focus()
    await userEvent.keyboard('{ArrowLeft}')
    expect(screen.getByRole('tab', { name: 'Posts' })).toHaveAttribute('aria-selected', 'true')
    await waitFor(() => expect(screen.getByRole('tab', { name: 'Posts' })).toHaveFocus())
    expect(screen.getByText('First post')).toBeInTheDocument()
    expect(screen.queryByText('Reviewer')).not.toBeInTheDocument()
  })

  it('renders the shared table and creates a related record through an accessible dialog', async () => {
    const mutate = vi.fn().mockResolvedValue({ contract: 'inlay.resources.relation-mutation.v1', operation: 'create', record: { id: 11, title: 'New post' } })
    const changed = vi.fn()
    render(<RelationManager mutate={mutate} onChanged={changed} resource={resource} />)

    expect(screen.getByRole('heading', { name: 'Posts' })).toBeInTheDocument()
    expect(screen.getByText('First post')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Create' }))
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Title *'), 'New post')
    await userEvent.click(screen.getByRole('button', { name: 'Create Post' }))

    expect(mutate).toHaveBeenCalledWith(expect.objectContaining({ method: 'post', data: expect.objectContaining({ title: 'New post' }) }))
    expect(changed).toHaveBeenCalledWith('create', expect.objectContaining({ id: 11 }))
  })

  it('edits related and pivot attributes through the edit form', async () => {
    const mutate = vi.fn().mockResolvedValue(null)
    render(<RelationManager mutate={mutate} resource={resource} />)

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(screen.getByRole('dialog')).toHaveTextContent('Edit Post')
    expect(screen.getByLabelText('Title *')).toHaveValue('First post')
    expect(screen.getByLabelText('Assignment note *')).toHaveValue('Initial assignment')
    await userEvent.clear(screen.getByLabelText('Assignment note *'))
    await userEvent.type(screen.getByLabelText('Assignment note *'), 'Updated assignment')
    await userEvent.click(screen.getByRole('button', { name: 'Save Post' }))

    expect(mutate).toHaveBeenCalledWith({
      url: '/users/1/relations/posts/10',
      method: 'patch',
      data: { id: 10, title: 'First post', assignment_note: 'Updated assignment' },
    })
  })

  it('attaches from the shared Select form and detaches through a confirmed row action', async () => {
    const mutate = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.resources.relation-mutation.v1', operation: 'attach', record: { id: 12, title: 'Available post' } })
      .mockResolvedValueOnce(null)
    const changed = vi.fn()
    render(<RelationManager mutate={mutate} onChanged={changed} resource={resource} />)

    await userEvent.click(screen.getByRole('button', { name: 'Attach' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Record' }))
    await userEvent.click(screen.getByRole('option', { name: 'Available post' }))
    await userEvent.type(screen.getByLabelText('Assignment note *'), 'Primary assignment')
    await userEvent.click(screen.getByRole('button', { name: 'Attach Post' }))

    expect(mutate).toHaveBeenNthCalledWith(1, {
      url: '/users/1/relations/posts/12/attach',
      method: 'post',
      data: { record: '12', assignment_note: 'Primary assignment' },
    })
    expect(changed).toHaveBeenCalledWith('attach', expect.objectContaining({ id: 12 }))

    await userEvent.click(screen.getByRole('button', { name: 'Detach' }))
    await userEvent.click(within(
      screen.getByRole('dialog', { name: 'Detach this related record?' }),
    ).getByRole('button', { name: 'Detach' }))
    expect(mutate).toHaveBeenNthCalledWith(2, {
      url: '/users/1/relations/posts/10/detach',
      method: 'delete',
    })
    expect(changed).toHaveBeenCalledWith('detach', expect.objectContaining({ id: 10 }))
  })

  it('associates from the shared Select form and dissociates through confirmation', async () => {
    const mutate = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.resources.relation-mutation.v1', operation: 'associate', record: { id: 13, title: 'Unowned post' } })
      .mockResolvedValueOnce(null)
    const changed = vi.fn()
    render(<RelationManager mutate={mutate} onChanged={changed} resource={associationResource} />)

    await userEvent.click(screen.getByRole('button', { name: 'Associate' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Record' }))
    await userEvent.click(screen.getByRole('option', { name: 'Unowned post' }))
    await userEvent.click(screen.getByRole('button', { name: 'Associate Post' }))

    expect(mutate).toHaveBeenNthCalledWith(1, {
      url: '/users/1/relations/posts/13/associate',
      method: 'post',
      data: { record: '13' },
    })
    expect(changed).toHaveBeenCalledWith('associate', expect.objectContaining({ id: 13 }))

    await userEvent.click(screen.getByRole('button', { name: 'Dissociate' }))
    await userEvent.click(within(
      screen.getByRole('dialog', { name: 'Dissociate this related record?' }),
    ).getByRole('button', { name: 'Dissociate' }))
    expect(mutate).toHaveBeenNthCalledWith(2, {
      url: '/users/1/relations/posts/10/dissociate',
      method: 'delete',
    })
    expect(changed).toHaveBeenCalledWith('dissociate', expect.objectContaining({ id: 10 }))
  })

  it('uses hosted relation lifecycle actions for trashed records without duplicate manual actions', async () => {
    const fetcher = vi.fn<typeof fetch>().mockResolvedValue(new Response(JSON.stringify({
      contract: 'inlay.actions.result.v1',
      status: 'succeeded',
      close: true,
      message: 'Related record restored.',
      result: null,
    })))
    vi.stubGlobal('fetch', fetcher)
    const changed = vi.fn()
    const restore = {
      name: 'restore',
      label: 'Restore',
      url: '/users/1/relations/posts?table=posts&_inlay_action=restore&_inlay_action_scope=row&record={id}',
      method: 'post' as const,
      color: 'success',
      requiresConfirmation: true,
      icon: null,
      modalHeading: 'Restore related record?',
      lifecycle: true,
      visibleWhen: { path: 'deleted_at', operator: 'filled' as const, value: null },
    }
    const softDeleteResource: RelationManagerResource = {
      ...resource,
      capabilities: { ...resource.capabilities, softDeletes: true, dissociate: true },
      endpoints: { ...resource.endpoints!, dissociate: '/users/1/relations/posts/{record}/dissociate' },
      table: {
        ...resource.table,
        actions: [restore],
        rows: [{ ...resource.table.rows[0], deleted_at: '2026-07-28T00:00:00Z' }],
      },
    }
    render(<RelationManager onChanged={changed} resource={softDeleteResource} />)

    expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Detach' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Dissociate' })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Restore' }))
    await userEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Restore' }))

    await waitFor(() => expect(fetcher).toHaveBeenCalledWith(
      '/users/1/relations/posts?table=posts&_inlay_action=restore&_inlay_action_scope=row&record=10',
      expect.objectContaining({ method: 'POST', body: '{}' }),
    ))
    expect(changed).toHaveBeenCalledWith('restore', expect.objectContaining({ id: 10 }))
  })
})
