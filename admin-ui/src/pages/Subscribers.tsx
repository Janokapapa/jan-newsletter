import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Search,
  Plus,
  Trash2,
  Download,
  Upload,
  MoreHorizontal,
  X,
  UserMinus,
} from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../api/client';
import type { Subscriber, SubscriberList, PaginatedResponse } from '../api/types';
import ConfirmModal from '../components/ConfirmModal';

export default function Subscribers() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [listFilter, setListFilter] = useState(() => {
    // Read list_id from URL on initial load
    const params = new URLSearchParams(window.location.search);
    return params.get('list_id') || '';
  });
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [showAddModal, setShowAddModal] = useState(false);
  const [editingSubscriber, setEditingSubscriber] = useState<Subscriber | null>(null);
  const [showBulkDeleteConfirm, setShowBulkDeleteConfirm] = useState(false);
  const [showRemoveFromListConfirm, setShowRemoveFromListConfirm] = useState(false);
  const [deleteSubscriber, setDeleteSubscriber] = useState<Subscriber | null>(null);

  const { data: subscribers, isLoading } = useQuery<PaginatedResponse<Subscriber>>({
    queryKey: ['subscribers', page, search, statusFilter, listFilter],
    queryFn: () => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', '20');
      if (search) params.set('search', search);
      if (statusFilter) params.set('status', statusFilter);
      if (listFilter) params.set('list_id', listFilter);
      return api.get(`/subscribers?${params.toString()}`);
    },
  });

  const { data: listsData } = useQuery<{ data: SubscriberList[] }>({
    queryKey: ['lists'],
    queryFn: () => api.get('/lists'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/subscribers/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      setDeleteSubscriber(null);
      toast.success('Subscriber deleted');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const bulkDeleteMutation = useMutation({
    mutationFn: (ids: number[]) => api.post('/subscribers/bulk-delete', { ids }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      setSelectedIds([]);
      setShowBulkDeleteConfirm(false);
      toast.success('Subscribers deleted');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const bulkRemoveFromListMutation = useMutation({
    mutationFn: ({ ids, list_id }: { ids: number[]; list_id: number }) =>
      api.post('/subscribers/bulk-remove-from-list', { ids, list_id }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      setSelectedIds([]);
      setShowRemoveFromListConfirm(false);
      toast.success('Subscribers removed from list');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const toggleSelect = (id: number) => {
    setSelectedIds((prev) =>
      prev.includes(id) ? prev.filter((i) => i !== id) : [...prev, id]
    );
  };

  const toggleSelectAll = () => {
    if (subscribers?.data) {
      if (selectedIds.length === subscribers.data.length) {
        setSelectedIds([]);
      } else {
        setSelectedIds(subscribers.data.map((s) => s.id));
      }
    }
  };

  const statusColors: Record<string, string> = {
    subscribed: 'bg-green-100 text-green-800',
    unsubscribed: 'bg-gray-100 text-gray-800',
    bounced: 'bg-red-100 text-red-800',
    pending: 'bg-yellow-100 text-yellow-800',
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Subscribers</h1>
        <div className="flex gap-2">
          <button className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border rounded-lg hover:bg-gray-50">
            <Upload size={18} />
            Import
          </button>
          <button className="flex items-center gap-2 px-4 py-2 text-gray-700 bg-white border rounded-lg hover:bg-gray-50">
            <Download size={18} />
            Export
          </button>
          <button
            onClick={() => setShowAddModal(true)}
            className="flex items-center gap-2 px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700"
          >
            <Plus size={18} />
            Add Subscriber
          </button>
        </div>
      </div>

      {/* Filters */}
      <div className="flex gap-4 flex-wrap">
        <div className="relative flex-1 min-w-[200px]">
          <Search className="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" size={20} />
          <input
            type="text"
            placeholder="Search subscribers..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="w-full pl-10 pr-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
          />
        </div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All Status</option>
          <option value="subscribed">Subscribed</option>
          <option value="unsubscribed">Unsubscribed</option>
          <option value="bounced">Bounced</option>
          <option value="pending">Pending</option>
        </select>
        <select
          value={listFilter}
          onChange={(e) => setListFilter(e.target.value)}
          className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All Lists</option>
          {listsData?.data?.map((list) => (
            <option key={list.id} value={list.id}>
              {list.name}
            </option>
          ))}
        </select>
      </div>

      {/* Bulk Actions */}
      {selectedIds.length > 0 && (
        <div className="flex items-center gap-4 p-4 bg-blue-50 rounded-lg">
          <span className="text-blue-800">{selectedIds.length} selected</span>
          {listFilter && (
            <button
              onClick={() => setShowRemoveFromListConfirm(true)}
              className="flex items-center gap-2 px-3 py-1 text-orange-600 bg-white border border-orange-200 rounded hover:bg-orange-50"
            >
              <UserMinus size={16} />
              Remove from list
            </button>
          )}
          <button
            onClick={() => setShowBulkDeleteConfirm(true)}
            className="flex items-center gap-2 px-3 py-1 text-red-600 bg-white border border-red-200 rounded hover:bg-red-50"
          >
            <Trash2 size={16} />
            Delete
          </button>
        </div>
      )}

      {/* Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-3 text-left">
                <input
                  type="checkbox"
                  checked={selectedIds.length === subscribers?.data?.length && subscribers?.data?.length > 0}
                  onChange={toggleSelectAll}
                  className="rounded"
                />
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Email
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Name
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Status
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Lists
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Source
              </th>
              <th className="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Date
              </th>
              <th className="px-4 py-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-gray-500">
                  Loading...
                </td>
              </tr>
            )}
            {!isLoading && subscribers?.data?.length === 0 && (
              <tr>
                <td colSpan={8} className="px-4 py-8 text-center text-gray-500">
                  No subscribers found
                </td>
              </tr>
            )}
            {subscribers?.data?.map((subscriber) => (
              <tr key={subscriber.id} className="hover:bg-gray-50">
                <td className="px-4 py-3">
                  <input
                    type="checkbox"
                    checked={selectedIds.includes(subscriber.id)}
                    onChange={() => toggleSelect(subscriber.id)}
                    className="rounded"
                  />
                </td>
                <td className="px-4 py-3">
                  <span className="font-medium">{subscriber.email}</span>
                </td>
                <td className="px-4 py-3">
                  {subscriber.first_name} {subscriber.last_name}
                </td>
                <td className="px-4 py-3">
                  <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                      statusColors[subscriber.status]
                    }`}
                  >
                    {subscriber.status}
                  </span>
                </td>
                <td className="px-4 py-3">
                  <div className="flex flex-wrap gap-1">
                    {subscriber.lists?.map((list) => (
                      <span
                        key={list.id}
                        className="px-2 py-0.5 bg-gray-100 rounded text-xs"
                      >
                        {list.name}
                      </span>
                    ))}
                  </div>
                </td>
                <td className="px-4 py-3 text-sm text-gray-500">
                  {subscriber.source}
                </td>
                <td className="px-4 py-3 text-sm text-gray-500">
                  {new Date(subscriber.created_at).toLocaleDateString()}
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-2">
                    <button
                      onClick={() => setEditingSubscriber(subscriber)}
                      className="p-1 hover:bg-gray-100 rounded"
                    >
                      <MoreHorizontal size={18} />
                    </button>
                    <button
                      onClick={() => setDeleteSubscriber(subscriber)}
                      className="p-1 hover:bg-red-100 text-red-600 rounded"
                    >
                      <Trash2 size={18} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {subscribers?.meta && subscribers.meta.total_pages > 1 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-500">
            Showing {(page - 1) * 20 + 1} to{' '}
            {Math.min(page * 20, subscribers.meta.total)} of {subscribers.meta.total}
          </span>
          <div className="flex gap-2">
            <button
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page === 1}
              className="px-3 py-1 border rounded disabled:opacity-50"
            >
              Previous
            </button>
            <button
              onClick={() => setPage((p) => Math.min(subscribers.meta.total_pages, p + 1))}
              disabled={page === subscribers.meta.total_pages}
              className="px-3 py-1 border rounded disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}

      {/* Single Delete Confirm */}
      <ConfirmModal
        open={!!deleteSubscriber}
        onConfirm={() => deleteSubscriber && deleteMutation.mutate(deleteSubscriber.id)}
        onCancel={() => setDeleteSubscriber(null)}
        title="Delete Subscriber"
        confirmLabel="Delete"
        confirmColor="red"
        loading={deleteMutation.isPending}
      >
        <p className="text-gray-700">
          Are you sure you want to delete <strong>{deleteSubscriber?.email}</strong>? This will remove them from all lists.
        </p>
      </ConfirmModal>

      {/* Bulk Delete Confirm */}
      <ConfirmModal
        open={showBulkDeleteConfirm}
        onConfirm={() => bulkDeleteMutation.mutate(selectedIds)}
        onCancel={() => setShowBulkDeleteConfirm(false)}
        title="Delete Subscribers"
        confirmLabel="Delete"
        confirmColor="red"
        loading={bulkDeleteMutation.isPending}
      >
        <p className="text-gray-700">
          Are you sure you want to delete <strong>{selectedIds.length} subscribers</strong>? This will remove them from all lists permanently.
        </p>
      </ConfirmModal>

      {/* Remove from List Confirm */}
      <ConfirmModal
        open={showRemoveFromListConfirm}
        onConfirm={() =>
          bulkRemoveFromListMutation.mutate({
            ids: selectedIds,
            list_id: Number(listFilter),
          })
        }
        onCancel={() => setShowRemoveFromListConfirm(false)}
        title="Remove from List"
        confirmLabel="Remove"
        confirmColor="blue"
        loading={bulkRemoveFromListMutation.isPending}
      >
        <p className="text-gray-700">
          Remove <strong>{selectedIds.length} subscribers</strong> from{' '}
          <strong>{listsData?.data?.find((l) => l.id === Number(listFilter))?.name}</strong>?
          The subscribers will not be deleted, only removed from this list.
        </p>
      </ConfirmModal>

      {/* Add/Edit Modal */}
      {(showAddModal || editingSubscriber) && (
        <SubscriberModal
          subscriber={editingSubscriber}
          lists={listsData?.data || []}
          onClose={() => {
            setShowAddModal(false);
            setEditingSubscriber(null);
          }}
        />
      )}
    </div>
  );
}

interface SubscriberModalProps {
  subscriber: Subscriber | null;
  lists: SubscriberList[];
  onClose: () => void;
}

interface SubscriberDetail extends Subscriber {
  meta?: Record<string, string>;
}

function SubscriberModal({ subscriber, lists, onClose }: SubscriberModalProps) {
  const queryClient = useQueryClient();
  const [formData, setFormData] = useState({
    email: subscriber?.email || '',
    first_name: subscriber?.first_name || '',
    last_name: subscriber?.last_name || '',
    status: subscriber?.status || 'subscribed',
    list_ids: subscriber?.lists?.map((l) => l.id) || [],
  });

  // Fetch full subscriber data with meta when editing
  const { data: subscriberDetail } = useQuery<SubscriberDetail>({
    queryKey: ['subscriber', subscriber?.id],
    queryFn: () => api.get(`/subscribers/${subscriber?.id}`),
    enabled: !!subscriber?.id,
  });

  // Update form when subscriber detail loads
  useEffect(() => {
    if (subscriberDetail) {
      setFormData({
        email: subscriberDetail.email,
        first_name: subscriberDetail.first_name || '',
        last_name: subscriberDetail.last_name || '',
        status: subscriberDetail.status,
        list_ids: subscriberDetail.lists?.map((l) => l.id) || [],
      });
    }
  }, [subscriberDetail]);

  const createMutation = useMutation({
    mutationFn: (data: typeof formData) => api.post('/subscribers', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
      toast.success('Subscriber added');
      onClose();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const updateMutation = useMutation({
    mutationFn: (data: typeof formData) =>
      api.put(`/subscribers/${subscriber?.id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
      toast.success('Subscriber updated');
      onClose();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (subscriber) {
      updateMutation.mutate(formData);
    } else {
      createMutation.mutate(formData);
    }
  };

  // Use detail data for lists if available
  const currentListIds = subscriberDetail?.lists?.map((l) => l.id) || formData.list_ids;

  return (
    <div className="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
      <div className="bg-white rounded-lg w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <div className="flex items-center justify-between p-4 border-b bg-gray-100">
          <h2 className="text-lg font-bold text-gray-900">
            {subscriber ? 'Edit Subscriber' : 'Add Subscriber'}
          </h2>
          <button onClick={onClose} className="p-1 hover:bg-gray-200 rounded">
            <X size={20} />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-4 space-y-4">
          <div>
            <label className="block text-sm font-semibold text-gray-900 mb-1">Email</label>
            <input
              type="email"
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              required
              className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div className="grid grid-cols-2 gap-4">
            <div>
              <label className="block text-sm font-semibold text-gray-900 mb-1">First Name</label>
              <input
                type="text"
                value={formData.first_name}
                onChange={(e) =>
                  setFormData({ ...formData, first_name: e.target.value })
                }
                className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-semibold text-gray-900 mb-1">Last Name</label>
              <input
                type="text"
                value={formData.last_name}
                onChange={(e) =>
                  setFormData({ ...formData, last_name: e.target.value })
                }
                className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>
          <div>
            <label className="block text-sm font-semibold text-gray-900 mb-1">Status</label>
            <select
              value={formData.status}
              onChange={(e) =>
                setFormData({ ...formData, status: e.target.value as Subscriber['status'] })
              }
              className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="subscribed">Subscribed</option>
              <option value="unsubscribed">Unsubscribed</option>
              <option value="pending">Pending</option>
            </select>
          </div>
          <div>
            <label className="block text-sm font-semibold text-gray-900 mb-1">Lists</label>
            {/* Selected lists as tags with X */}
            <div className="flex flex-wrap gap-2 p-2 min-h-[42px] border-2 border-gray-300 rounded-lg bg-gray-50 mb-2">
              {formData.list_ids.length === 0 ? (
                <span className="text-gray-500 text-sm">No lists selected</span>
              ) : (
                formData.list_ids.map((listId) => {
                  const list = lists.find((l) => l.id === listId);
                  return list ? (
                    <span
                      key={listId}
                      className="inline-flex items-center gap-1 px-3 py-1 bg-blue-600 text-white rounded text-sm font-medium"
                    >
                      {list.name}
                      <button
                        type="button"
                        onClick={() =>
                          setFormData({
                            ...formData,
                            list_ids: formData.list_ids.filter((id) => id !== listId),
                          })
                        }
                        className="p-0.5 hover:bg-blue-700 rounded"
                      >
                        <X size={14} />
                      </button>
                    </span>
                  ) : null;
                })
              )}
            </div>
            {/* Dropdown to add lists */}
            <select
              value=""
              onChange={(e) => {
                const listId = Number(e.target.value);
                if (listId && !formData.list_ids.includes(listId)) {
                  setFormData({
                    ...formData,
                    list_ids: [...formData.list_ids, listId],
                  });
                }
              }}
              className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="">+ Add to list...</option>
              {lists
                .filter((list) => !formData.list_ids.includes(list.id))
                .map((list) => (
                  <option key={list.id} value={list.id}>
                    {list.name}
                  </option>
                ))}
            </select>
          </div>

          {/* Meta data display (read-only) */}
          {subscriberDetail?.meta && Object.keys(subscriberDetail.meta).length > 0 && (
            <div>
              <label className="block text-sm font-semibold text-gray-900 mb-1">
                GetResponse Data
              </label>
              <div className="bg-gray-50 border-2 border-gray-300 rounded-lg p-3 max-h-40 overflow-y-auto">
                <dl className="space-y-1 text-sm">
                  {Object.entries(subscriberDetail.meta).map(([key, value]) => (
                    <div key={key} className="flex">
                      <dt className="font-medium text-gray-700 w-1/3 truncate" title={key}>
                        {key.replace('gr_', '')}:
                      </dt>
                      <dd className="text-gray-900 w-2/3 truncate" title={String(value)}>
                        {String(value)}
                      </dd>
                    </div>
                  ))}
                </dl>
              </div>
            </div>
          )}

          <div className="flex justify-end gap-2 pt-4 border-t">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 border-2 border-gray-300 rounded-lg hover:bg-gray-50 font-medium"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 font-medium"
            >
              {createMutation.isPending || updateMutation.isPending
                ? 'Saving...'
                : 'Save'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
