import { cleanup, render, screen, waitFor, within } from '@testing-library/vue'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { RelationManager, RelationManagers } from '../src'
import type { RelationManagerResource } from '../src'

vi.mock('@inertiajs/vue3', () => ({ router: { reload: vi.fn(), visit: vi.fn() } }))

afterEach(cleanup)
afterEach(() => vi.unstubAllGlobals())

const editForm = {
  contract: 'inlay.forms.v1', type: 'form', name: 'posts.edit', action: '/posts/{record}', method: 'patch', columns: 1, submitLabel: 'Save', validation: null, data: {},
  schema: [
    { type: 'text', rendererCategory: 'field', name: 'title', label: 'Title', statePath: 'title', required: true, disabled: false, readOnly: false, hidden: false, saved: true, columnSpan: 1, extraAttributes: {} },
    { type: 'text', rendererCategory: 'field', name: 'assignment_note', label: 'Assignment note', statePath: 'assignment_note', required: true, disabled: false, readOnly: false, hidden: false, saved: true, columnSpan: 1, extraAttributes: {} },
  ],
}

const resource = {
  contract: 'inlay.resources.relation-manager.v1',
  name: 'posts',
  title: 'Posts',
  recordTitleAttribute: 'title',
  readOnly: false,
  table: {
    contract: 'inlay.tables.v1', type: 'table', name: 'posts', primaryKey: 'id', searchPlaceholder: 'Search',
    columns: [{ type: 'text', name: 'title', label: 'Title', sortable: false, searchable: false, toggleable: false, visible: true, alignment: 'left', tooltip: null, url: null, openUrlInNewTab: false }],
    filters: [], actions: [], headerActions: [], bulkActions: [], rows: [{ id: 10, title: 'First post', assignment_note: 'Initial assignment' }], pagination: null, selectable: false, deferFilters: true, query: null, emptyState: { heading: 'No posts', description: null },
  },
  createForm: {
    contract: 'inlay.forms.v1', type: 'form', name: 'posts.create', action: '/posts', method: 'post', columns: 1, submitLabel: 'Create', validation: null, data: {},
    schema: [{ type: 'text', rendererCategory: 'field', name: 'title', label: 'Title', statePath: 'title', required: true, disabled: false, readOnly: false, hidden: false, saved: true, columnSpan: 1, extraAttributes: {} }],
  },
  editForm,
  attachForm: {
    contract: 'inlay.forms.v1', type: 'form', name: 'posts.attach', action: '/posts/attach-options', method: 'post', columns: 1, submitLabel: 'Attach', validation: null, data: {},
    schema: [
      { type: 'select', rendererCategory: 'field', name: 'record', label: 'Record', required: true, disabled: false, readOnly: false, hidden: false, saved: true, columnSpan: 1, extraAttributes: {}, options: [{ value: 12, label: 'Available post' }], searchable: true, multiple: false, remoteOptions: null },
      { type: 'text', rendererCategory: 'field', name: 'assignment_note', label: 'Assignment note', statePath: 'assignment_note', required: true, disabled: false, readOnly: false, hidden: false, saved: true, columnSpan: 1, extraAttributes: {} },
    ],
  },
  capabilities: { create: true, edit: true, delete: true, attach: true, detach: true },
  endpoints: { create: '/posts', update: '/posts/{record}', delete: '/posts/{record}', attach: '/posts/{record}/attach', detach: '/posts/{record}/detach', attachOptions: '/posts/attach-options' },
} as unknown as RelationManagerResource

const associationResource = {
  ...resource,
  attachForm: null,
  associateForm: {
    ...resource.attachForm,
    name: 'posts.associate',
    submitLabel: 'Associate',
    schema: [{ ...resource.attachForm!.schema[0], options: [{ value: 13, label: 'Unowned post' }] }],
  },
  capabilities: { ...resource.capabilities, attach: false, detach: false, associate: true, dissociate: true },
  endpoints: {
    ...resource.endpoints!,
    associate: '/posts/{record}/associate',
    dissociate: '/posts/{record}/dissociate',
    associateOptions: '/posts/associate-options',
  },
} as unknown as RelationManagerResource

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
    const roles = {
      ...associationResource,
      name: 'roles',
      title: 'Roles',
      group,
      table: {
        ...associationResource.table,
        name: 'roles',
        rows: [{ id: 20, title: 'Reviewer', assignment_note: null }],
      },
    } as RelationManagerResource
    render(RelationManagers, { props: { resources: [{ ...resource, group }, roles] } })

    expect(screen.getByRole('heading', { name: 'User data' })).toBeInTheDocument()
    expect(screen.getByText('Manage connected records.')).toBeInTheDocument()
    expect(screen.getByRole('tab', { name: 'Roles' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByText('Reviewer')).toBeInTheDocument()
    expect(screen.queryByText('First post')).not.toBeInTheDocument()

    screen.getByRole('tab', { name: 'Roles' }).focus()
    await userEvent.keyboard('{ArrowLeft}')
    expect(screen.getByRole('tab', { name: 'Posts' })).toHaveAttribute('aria-selected', 'true')
    expect(screen.getByRole('tab', { name: 'Posts' })).toHaveFocus()
    expect(screen.getByText('First post')).toBeInTheDocument()
    expect(screen.queryByText('Reviewer')).not.toBeInTheDocument()
  })

  it('renders a shared table and creates through a modal form', async () => {
    const mutate = vi.fn().mockResolvedValue({ contract: 'inlay.resources.relation-mutation.v1', operation: 'create', record: { id: 11, title: 'New post' } })
    const view = render(RelationManager, { props: { resource, mutate } })
    expect(screen.getByText('First post')).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Create' }))
    expect(screen.getByRole('dialog')).toBeInTheDocument()
    await userEvent.type(screen.getByLabelText('Title *'), 'New post')
    await userEvent.click(screen.getByRole('button', { name: 'Create Post' }))
    expect(mutate).toHaveBeenCalledWith(expect.objectContaining({ method: 'post', data: expect.objectContaining({ title: 'New post' }) }))
    const changed = view.emitted().changed as unknown[][] | undefined
    expect(changed?.[0]?.[0]).toBe('create')
  })

  it('edits related and pivot attributes through the edit form', async () => {
    const mutate = vi.fn().mockResolvedValue(null)
    render(RelationManager, { props: { resource, mutate } })

    await userEvent.click(screen.getByRole('button', { name: 'Edit' }))
    expect(screen.getByRole('dialog')).toHaveTextContent('Edit Post')
    expect(screen.getByLabelText('Title *')).toHaveValue('First post')
    expect(screen.getByLabelText('Assignment note *')).toHaveValue('Initial assignment')
    await userEvent.clear(screen.getByLabelText('Assignment note *'))
    await userEvent.type(screen.getByLabelText('Assignment note *'), 'Updated assignment')
    await userEvent.click(screen.getByRole('button', { name: 'Save Post' }))

    expect(mutate).toHaveBeenCalledWith({
      url: '/posts/10',
      method: 'patch',
      data: { id: 10, title: 'First post', assignment_note: 'Updated assignment' },
    })
  })

  it('attaches a selected record through the shared form', async () => {
    const mutate = vi.fn().mockResolvedValue({ contract: 'inlay.resources.relation-mutation.v1', operation: 'attach', record: { id: 12, title: 'Available post' } })
    const view = render(RelationManager, { props: { resource, mutate } })

    await userEvent.click(screen.getByRole('button', { name: 'Attach' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Record' }))
    await userEvent.click(screen.getByRole('option', { name: 'Available post' }))
    await userEvent.type(screen.getByLabelText('Assignment note *'), 'Primary assignment')
    await userEvent.click(screen.getByRole('button', { name: 'Attach Post' }))

    expect(mutate).toHaveBeenCalledWith({ url: '/posts/12/attach', method: 'post', data: { record: '12', assignment_note: 'Primary assignment' } })
    const changed = view.emitted().changed as unknown[][] | undefined
    expect(changed?.[0]?.[0]).toBe('attach')
  })

  it('associates and dissociates records through shared relation actions', async () => {
    const mutate = vi.fn()
      .mockResolvedValueOnce({ contract: 'inlay.resources.relation-mutation.v1', operation: 'associate', record: { id: 13, title: 'Unowned post' } })
      .mockResolvedValueOnce(null)
    const view = render(RelationManager, { props: { resource: associationResource, mutate } })

    await userEvent.click(screen.getByRole('button', { name: 'Associate' }))
    await userEvent.click(screen.getByRole('combobox', { name: 'Record' }))
    await userEvent.click(screen.getByRole('option', { name: 'Unowned post' }))
    await userEvent.click(screen.getByRole('button', { name: 'Associate Post' }))
    expect(mutate).toHaveBeenNthCalledWith(1, { url: '/posts/13/associate', method: 'post', data: { record: '13' } })

    await userEvent.click(screen.getByRole('button', { name: 'Dissociate' }))
    const confirmation = screen.getByRole('dialog', { name: 'Dissociate this related record?' })
    await userEvent.click(screen.getAllByRole('button', { name: 'Dissociate' }).find(button => confirmation.contains(button))!)
    expect(mutate).toHaveBeenNthCalledWith(2, { url: '/posts/10/dissociate', method: 'delete' })

    const changed = view.emitted().changed as unknown[][] | undefined
    expect(changed?.map(event => event[0])).toEqual(['associate', 'dissociate'])
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
    const softDeleteResource = {
      ...resource,
      capabilities: { ...resource.capabilities, softDeletes: true, dissociate: true },
      endpoints: { ...resource.endpoints!, dissociate: '/posts/{record}/dissociate' },
      table: {
        ...resource.table,
        actions: [restore],
        rows: [{ ...resource.table.rows[0], deleted_at: '2026-07-28T00:00:00Z' }],
      },
    } as RelationManagerResource
    const view = render(RelationManager, { props: { resource: softDeleteResource } })

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
    const changed = view.emitted().changed as unknown[][] | undefined
    expect(changed?.[0]?.[0]).toBe('restore')
  })
})
