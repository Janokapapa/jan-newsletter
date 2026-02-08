import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, Edit, Play, Pause, Send, Eye } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../api/client';
import type { Campaign, PaginatedResponse } from '../api/types';

interface CampaignsProps {
  onEditCampaign: (id: number | null) => void;
}

export default function Campaigns({ onEditCampaign }: CampaignsProps) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');

  const { data: campaigns, isLoading } = useQuery<PaginatedResponse<Campaign>>({
    queryKey: ['campaigns', page, statusFilter],
    queryFn: () => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', '20');
      if (statusFilter) params.set('status', statusFilter);
      return api.get(`/campaigns?${params.toString()}`);
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/campaigns/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success('Campaign deleted');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const sendMutation = useMutation({
    mutationFn: (id: number) => api.post(`/campaigns/${id}/send`),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success(data.message);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const pauseMutation = useMutation({
    mutationFn: (id: number) => api.post(`/campaigns/${id}/pause`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success('Campaign paused');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const statusColors: Record<string, string> = {
    draft: 'bg-gray-100 text-gray-800',
    scheduled: 'bg-blue-100 text-blue-800',
    sending: 'bg-yellow-100 text-yellow-800',
    sent: 'bg-green-100 text-green-800',
    paused: 'bg-orange-100 text-orange-800',
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Campaigns</h1>
        <button
          onClick={() => onEditCampaign(null)}
          className="flex items-center gap-2 px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700"
        >
          <Plus size={18} />
          New Campaign
        </button>
      </div>

      {/* Filters */}
      <div className="flex gap-4">
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All Status</option>
          <option value="draft">Draft</option>
          <option value="scheduled">Scheduled</option>
          <option value="sending">Sending</option>
          <option value="sent">Sent</option>
          <option value="paused">Paused</option>
        </select>
      </div>

      {/* Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Campaign
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                List
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Status
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Sent
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Opens
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Clicks
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
            {!isLoading && campaigns?.data?.length === 0 && (
              <tr>
                <td colSpan={7} className="px-6 py-8 text-center text-gray-500">
                  No campaigns yet. Create your first campaign to get started.
                </td>
              </tr>
            )}
            {campaigns?.data?.map((campaign) => (
              <tr key={campaign.id} className="hover:bg-gray-50">
                <td className="px-6 py-4">
                  <div>
                    <p className="font-medium">{campaign.name}</p>
                    <p className="text-sm text-gray-500">{campaign.subject}</p>
                  </div>
                </td>
                <td className="px-6 py-4 text-sm">
                  {campaign.list_name || '-'}
                </td>
                <td className="px-6 py-4">
                  <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                      statusColors[campaign.status]
                    }`}
                  >
                    {campaign.status}
                  </span>
                </td>
                <td className="px-6 py-4">
                  {campaign.sent_count}/{campaign.total_recipients || '-'}
                </td>
                <td className="px-6 py-4">
                  {campaign.open_count} ({campaign.open_rate}%)
                </td>
                <td className="px-6 py-4">
                  {campaign.click_count} ({campaign.click_rate}%)
                </td>
                <td className="px-6 py-4">
                  <div className="flex items-center gap-1">
                    {/* Edit */}
                    {['draft', 'scheduled', 'paused'].includes(campaign.status) && (
                      <button
                        onClick={() => onEditCampaign(campaign.id)}
                        className="p-2 hover:bg-gray-100 rounded"
                        title="Edit"
                      >
                        <Edit size={16} />
                      </button>
                    )}

                    {/* View */}
                    {campaign.status === 'sent' && (
                      <button
                        onClick={() => onEditCampaign(campaign.id)}
                        className="p-2 hover:bg-gray-100 rounded"
                        title="View"
                      >
                        <Eye size={16} />
                      </button>
                    )}

                    {/* Send */}
                    {['draft', 'scheduled', 'paused'].includes(campaign.status) && (
                      <button
                        onClick={() => {
                          if (confirm('Send this campaign now?')) {
                            sendMutation.mutate(campaign.id);
                          }
                        }}
                        className="p-2 hover:bg-green-100 text-green-600 rounded"
                        title="Send"
                      >
                        <Send size={16} />
                      </button>
                    )}

                    {/* Pause */}
                    {campaign.status === 'sending' && (
                      <button
                        onClick={() => pauseMutation.mutate(campaign.id)}
                        className="p-2 hover:bg-yellow-100 text-yellow-600 rounded"
                        title="Pause"
                      >
                        <Pause size={16} />
                      </button>
                    )}

                    {/* Resume */}
                    {campaign.status === 'paused' && (
                      <button
                        onClick={() => sendMutation.mutate(campaign.id)}
                        className="p-2 hover:bg-green-100 text-green-600 rounded"
                        title="Resume"
                      >
                        <Play size={16} />
                      </button>
                    )}

                    {/* Delete */}
                    {['draft', 'scheduled'].includes(campaign.status) && (
                      <button
                        onClick={() => {
                          if (confirm('Delete this campaign?')) {
                            deleteMutation.mutate(campaign.id);
                          }
                        }}
                        className="p-2 hover:bg-red-100 text-red-600 rounded"
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
      {campaigns?.meta && campaigns.meta.total_pages > 1 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-500">
            Showing {(page - 1) * 20 + 1} to{' '}
            {Math.min(page * 20, campaigns.meta.total)} of {campaigns.meta.total}
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
              onClick={() => setPage((p) => Math.min(campaigns.meta.total_pages, p + 1))}
              disabled={page === campaigns.meta.total_pages}
              className="px-3 py-1 border rounded disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
