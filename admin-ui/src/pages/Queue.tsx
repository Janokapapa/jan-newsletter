import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { RefreshCw, Play, X, RotateCcw, Trash2, Ban } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../api/client';
import type { QueuedEmail, PaginatedResponse } from '../api/types';
import ConfirmModal from '../components/ConfirmModal';

export default function Queue() {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');
  const [showCancelAllConfirm, setShowCancelAllConfirm] = useState(false);

  const { data: queue, isLoading, refetch } = useQuery<PaginatedResponse<QueuedEmail>>({
    queryKey: ['queue', page, statusFilter],
    queryFn: () => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', '50');
      if (statusFilter) params.set('status', statusFilter);
      return api.get(`/queue?${params.toString()}`);
    },
    refetchInterval: 5000, // Auto-refresh every 5s
  });

  const { data: stats } = useQuery<{
    pending: number;
    processing: number;
    sent: number;
    failed: number;
    sent_today: number;
    sent_this_week: number;
  }>({
    queryKey: ['queue-stats'],
    queryFn: () => api.get('/queue/stats'),
    refetchInterval: 5000,
  });

  const processMutation = useMutation({
    mutationFn: () => api.post('/queue/process'),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      toast.success(data.message);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const retryFailedMutation = useMutation({
    mutationFn: () => api.post('/queue/retry-failed'),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      toast.success(data.message);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const cancelMutation = useMutation({
    mutationFn: (id: number) => api.post(`/queue/${id}/cancel`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      toast.success('Email cancelled');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const retryMutation = useMutation({
    mutationFn: (id: number) => api.post(`/queue/${id}/retry`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      toast.success('Email queued for retry');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/queue/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      toast.success('Email deleted');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const cancelAllPendingMutation = useMutation({
    mutationFn: () => api.post('/queue/cancel-all-pending'),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['queue'] });
      queryClient.invalidateQueries({ queryKey: ['queue-stats'] });
      setShowCancelAllConfirm(false);
      toast.success(data.message);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const statusColors: Record<string, string> = {
    pending: 'bg-yellow-100 text-yellow-800',
    processing: 'bg-blue-100 text-blue-800',
    sent: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800',
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Email Queue</h1>
        <div className="flex gap-2">
          <button
            onClick={() => refetch()}
            className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50"
          >
            <RefreshCw size={18} />
            Refresh
          </button>
          <button
            onClick={() => processMutation.mutate()}
            disabled={processMutation.isPending}
            className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
          >
            <Play size={18} />
            Process Now
          </button>
          {(stats?.pending ?? 0) + (stats?.processing ?? 0) > 0 && (
            <button
              onClick={() => setShowCancelAllConfirm(true)}
              className="flex items-center gap-2 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700"
            >
              <Ban size={18} />
              Cancel All Pending
            </button>
          )}
          {(stats?.failed ?? 0) > 0 && (
            <button
              onClick={() => retryFailedMutation.mutate()}
              disabled={retryFailedMutation.isPending}
              className="flex items-center gap-2 px-4 py-2 bg-orange-600 text-white rounded-lg hover:bg-orange-700 disabled:opacity-50"
            >
              <RotateCcw size={18} />
              Retry Failed
            </button>
          )}
        </div>
      </div>

      {/* Stats */}
      <div className="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-6 gap-4">
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Pending</p>
          <p className="text-2xl font-bold text-yellow-600">{stats?.pending || 0}</p>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Processing</p>
          <p className="text-2xl font-bold text-blue-600">{stats?.processing || 0}</p>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Sent</p>
          <p className="text-2xl font-bold text-green-600">{stats?.sent || 0}</p>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Failed</p>
          <p className="text-2xl font-bold text-red-600">{stats?.failed || 0}</p>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Sent Today</p>
          <p className="text-2xl font-bold">{stats?.sent_today || 0}</p>
        </div>
        <div className="bg-white rounded-lg shadow p-4">
          <p className="text-sm text-gray-500">Sent This Week</p>
          <p className="text-2xl font-bold">{stats?.sent_this_week || 0}</p>
        </div>
      </div>

      {/* Filter */}
      <div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All Status</option>
          <option value="pending">Pending</option>
          <option value="processing">Processing</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>

      {/* Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                To
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Subject
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Status
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Priority
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Source
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Created
              </th>
              <th className="px-6 py-3"></th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={7} className="px-6 py-8 text-center text-gray-500">
                  Loading...
                </td>
              </tr>
            )}
            {!isLoading && queue?.data?.length === 0 && (
              <tr>
                <td colSpan={7} className="px-6 py-8 text-center text-gray-500">
                  Queue is empty
                </td>
              </tr>
            )}
            {queue?.data?.map((email) => (
              <tr key={email.id} className="hover:bg-gray-50">
                <td className="px-6 py-4">
                  <span className="text-sm">{email.to_email}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm">{email.subject}</span>
                </td>
                <td className="px-6 py-4">
                  <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                      statusColors[email.status]
                    }`}
                  >
                    {email.status}
                  </span>
                  {email.error_message && (
                    <p className="text-xs text-red-500 mt-1" title={email.error_message}>
                      {email.error_message.substring(0, 50)}...
                    </p>
                  )}
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm">{email.priority_label}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-gray-500">{email.source}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-gray-500">
                    {new Date(email.created_at).toLocaleString()}
                  </span>
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-1">
                    {email.status === 'pending' && (
                      <button
                        onClick={() => cancelMutation.mutate(email.id)}
                        className="p-1 hover:bg-gray-100 rounded"
                        title="Cancel"
                      >
                        <X size={16} />
                      </button>
                    )}
                    {email.status === 'failed' && (
                      <button
                        onClick={() => retryMutation.mutate(email.id)}
                        className="p-1 hover:bg-gray-100 rounded"
                        title="Retry"
                      >
                        <RotateCcw size={16} />
                      </button>
                    )}
                    {['sent', 'cancelled', 'failed'].includes(email.status) && (
                      <button
                        onClick={() => deleteMutation.mutate(email.id)}
                        className="p-1 hover:bg-red-100 text-red-600 rounded"
                        title="Delete"
                      >
                        <Trash2 size={16} />
                      </button>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {queue?.meta && queue.meta.total_pages > 1 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-500">
            Showing {(page - 1) * 50 + 1} to{' '}
            {Math.min(page * 50, queue.meta.total)} of {queue.meta.total}
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
              onClick={() => setPage((p) => Math.min(queue.meta.total_pages, p + 1))}
              disabled={page === queue.meta.total_pages}
              className="px-3 py-1 border rounded disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
      <ConfirmModal
        open={showCancelAllConfirm}
        onConfirm={() => cancelAllPendingMutation.mutate()}
        onCancel={() => setShowCancelAllConfirm(false)}
        title="Cancel All Pending"
        confirmLabel="Cancel All"
        confirmColor="red"
        loading={cancelAllPendingMutation.isPending}
      >
        <p className="text-gray-700">
          Cancel all <strong>{(stats?.pending ?? 0) + (stats?.processing ?? 0)}</strong> pending/processing emails? This cannot be undone.
        </p>
      </ConfirmModal>
    </div>
  );
}
