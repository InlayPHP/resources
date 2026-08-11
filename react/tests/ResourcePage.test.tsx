import { cleanup, fireEvent, render, screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { ActionExecutor, ActionResource } from '@inlayphp/actions'
import type { WidgetDashboardResource } from '@inlayphp/widgets-react'
import { ResourcePage } from '../src'

afterEach(cleanup)

const action = (values: Partial<ActionResource> = {}): ActionResource => ({
  name: 'publish',
  label: 'Publish',
  url: '/users/{id}/publish',
  method: 'post',
  color: 'primary',
  requiresConfirmation: false,
  icon: null,
  modalHeading: null,
  modal: null,
  ...values,
})

const widgets = (name: string): WidgetDashboardResource => ({
  contract: 'inlay.widget-dashboard.v1',
  columns: 12,
  widgets: [{
    contract: 'inlay.widgets.v1',
    name,
    label: `${name} metrics`,
    description: null,
    type: 'stats-overview',
    columns: 1,
    stats: [{ label: 'Orders', value: 42, description: null, icon: null, color: 'primary', url: null, trend: null, chart: [] }],
    columnSpan: 'full',
    sort: 0,
    visible: true,
    pollingInterval: null,
    lazy: false,
    headerActions: [],
    footerActions: [],
  }],
})

describe('ResourcePage', () => {
  it('composes server-authored chrome around host content', () => {
    render(
      <ResourcePage
        actions={<button type="button">Create</button>}
        afterContent={<p>After</p>}
        beforeContent={<p>Before</p>}
        breadcrumbs={[{ label: 'Users', url: '/users' }, { label: 'Ada', url: null }]}
        heading="Ada Lovelace"
        subNavigation={[{ name: 'overview', label: 'Overview', url: '/users/1', active: true }]}
        subheading="Account details"
      >
        <p>Form content</p>
      </ResourcePage>,
    )

    expect(screen.getByRole('heading', { name: 'Ada Lovelace' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Create' })).toBeInTheDocument()
    expect(screen.getByText('Before')).toBeInTheDocument()
    expect(screen.getByText('Form content')).toBeInTheDocument()
    expect(screen.getByText('After')).toBeInTheDocument()
    expect(document.querySelector('[data-slot="resource-page"]')).toBeInTheDocument()
  })

  it('keeps named views server-driven and reports a selected tab', () => {
    const onTabSelect = vi.fn()
    render(
      <ResourcePage
        heading="Users"
        onTabSelect={onTabSelect}
        tabs={{ active: 'all', items: [{ name: 'all', label: 'All' }, { name: 'admins', label: 'Admins', badge: 2 }] }}
      />,
    )

    fireEvent.click(screen.getByRole('tab', { name: 'Admins 2' }))
    expect(onTabSelect).toHaveBeenCalledWith('admins')
  })

  it('renders PHP header and footer widget dashboards through the shared renderer', () => {
    render(
      <ResourcePage
        footerWidgets={widgets('footer')}
        headerWidgets={widgets('header')}
        heading="Users"
        widgetProps={{ classNames: { root: 'page-widgets' } }}
      >
        <p>Table content</p>
      </ResourcePage>,
    )

    const dashboards = screen.getAllByRole('region', { name: 'Dashboard widgets' })
    expect(dashboards).toHaveLength(2)
    expect(document.querySelector('[data-slot="resource-page-header-widgets"]')).toContainElement(dashboards[0])
    expect(document.querySelector('[data-slot="resource-page-footer-widgets"]')).toContainElement(dashboards[1])
    expect(dashboards[0]).toHaveAttribute('data-contract', 'inlay.widget-dashboard.v1')
    expect(dashboards[0]).toHaveClass('page-widgets')
    expect(within(dashboards[0]).getByText('Orders')).toBeInTheDocument()
    expect(within(dashboards[1]).getByText('Orders')).toBeInTheDocument()
    expect(screen.getByText('Table content')).toBeInTheDocument()
  })

  it('renders PHP-declared header actions through the shared action runtime', async () => {
    const executor = vi.fn() as unknown as ActionExecutor

    render(
      <ResourcePage
        actionExecutor={executor}
        actionInput={{ data: { source: 'page' }, parameters: { id: 7 } }}
        headerActions={[action()]}
        heading="User"
      />,
    )

    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))

    await vi.waitFor(() => expect(executor).toHaveBeenCalledWith(expect.objectContaining({
      action: expect.objectContaining({ name: 'publish' }),
      input: expect.objectContaining({
        data: expect.objectContaining({ source: 'page' }),
        parameters: expect.objectContaining({ id: 7 }),
      }),
      url: '/users/7/publish',
    })))
  })

  it('keeps the ReactNode actions slot alongside declared actions and supports confirmation', async () => {
    const executor = vi.fn() as unknown as ActionExecutor

    render(
      <ResourcePage
        actionExecutor={executor}
        actionInput={{ parameters: { id: 7 } }}
        actions={<button type="button">Custom action</button>}
        headerActions={[action({
          requiresConfirmation: true,
          modal: { heading: 'Publish this user?', description: 'The user will become visible.' },
        })]}
        heading="User"
      />,
    )

    expect(screen.getByRole('button', { name: 'Custom action' })).toBeInTheDocument()
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }))

    const dialog = screen.getByRole('dialog', { name: 'Publish this user?' })
    expect(within(dialog).getByText('The user will become visible.')).toBeInTheDocument()
    await userEvent.click(within(dialog).getByRole('button', { name: 'Publish' }))

    await vi.waitFor(() => expect(executor).toHaveBeenCalledTimes(1))
  })
})
