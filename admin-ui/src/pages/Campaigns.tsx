import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, Edit, Play, Pause, Send, Eye, BarChart3, X, ExternalLink, Copy } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../api/client';
import type { Campaign, PaginatedResponse, SubscriberList } from '../api/types';
import ConfirmModal from '../components/ConfirmModal';

interface CampaignsProps {
  onEditCampaign: (id: number | null) => void;
}

interface CampaignStats {
  campaign: Campaign;
  stats: {
    sent: number;
    opens: number;
    clicks: number;
    bounces: number;
    unsubscribes: number;
    open_rate: number;
    click_rate: number;
  };
  clicks: Array<{ link_url: string; click_count: number; unique_clicks: number }>;
  timeline: Array<{ date: string; event_type: string; count: number }>;
}

export default function Campaigns({ onEditCampaign }: CampaignsProps) {
  const queryClient = useQueryClient();
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');

  // Modal states
  const [sendCampaign, setSendCampaign] = useState<Campaign | null>(null);
  const [deleteCampaign, setDeleteCampaign] = useState<Campaign | null>(null);
  const [statsCampaignId, setStatsCampaignId] = useState<number | null>(null);
  const [statsData, setStatsData] = useState<CampaignStats | null>(null);
  const [statsLoading, setStatsLoading] = useState(false);

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

  const { data: listsData } = useQuery<{ data: SubscriberList[] }>({
    queryKey: ['lists'],
    queryFn: () => api.get('/lists'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/campaigns/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success('Campaign deleted');
      setDeleteCampaign(null);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const sendMutation = useMutation({
    mutationFn: (id: number) => api.post(`/campaigns/${id}/send`),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success(data.message);
      setSendCampaign(null);
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

  const duplicateMutation = useMutation({
    mutationFn: (id: number) => api.post(`/campaigns/${id}/duplicate`),
    onSuccess: (data: { campaign: Campaign }) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success('Campaign duplicated');
      onEditCampaign(data.campaign.id);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const openStats = async (campaignId: number) => {
    setStatsCampaignId(campaignId);
    setStatsLoading(true);
    setStatsData(null);
    try {
      const data = await api.get<CampaignStats>(`/campaigns/${campaignId}/stats`);
      setStatsData(data);
    } catch (err) {
      toast.error(err instanceof Error ? err.message : 'Failed to load stats');
      setStatsCampaignId(null);
    } finally {
      setStatsLoading(false);
    }
  };

  // Find subscriber count for the send modal
  const getListSubscriberCount = (listId?: number): number => {
    if (!listId || !listsData?.data) return 0;
    const list = listsData.data.find((l) => l.id === listId);
    return list?.subscriber_count || 0;
  };

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
                    {/* Stats */}
                    <button
                      onClick={() => openStats(campaign.id)}
                      className="p-2 hover:bg-gray-100 rounded"
                      title="Stats"
                    >
                      <BarChart3 size={16} />
                    </button>

                    {/* Edit (not sent) */}
                    {campaign.status !== 'sent' && (
                      <button
                        onClick={() => onEditCampaign(campaign.id)}
                        className="p-2 hover:bg-gray-100 rounded"
                        title="Edit"
                      >
                        <Edit size={16} />
                      </button>
                    )}

                    {/* View (sent only) */}
                    {campaign.status === 'sent' && (
                      <button
                        onClick={() => onEditCampaign(campaign.id)}
                        className="p-2 hover:bg-gray-100 rounded"
                        title="View"
                      >
                        <Eye size={16} />
                      </button>
                    )}

                    {/* Send (not sent) */}
                    {['draft', 'scheduled', 'paused'].includes(campaign.status) && (
                      <button
                        onClick={() => setSendCampaign(campaign)}
                        className="p-2 hover:bg-green-100 text-green-600 rounded"
                        title="Send"
                      >
                        <Send size={16} />
                      </button>
                    )}

                    {/* Duplicate (always) */}
                    <button
                      onClick={() => duplicateMutation.mutate(campaign.id)}
                      className="p-2 hover:bg-blue-100 text-blue-600 rounded"
                      title="Duplicate"
                    >
                      <Copy size={16} />
                    </button>

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
                    {['draft', 'scheduled', 'sent'].includes(campaign.status) && (
                      <button
                        onClick={() => setDeleteCampaign(campaign)}
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

      {/* Send Campaign Modal */}
      {sendCampaign && (
        <div className="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-2xl w-full max-w-md mx-4 border border-gray-300">
            <div className="p-4 border-b border-gray-300 bg-gray-100 rounded-t-lg">
              <h3 className="text-lg font-bold text-gray-900">Send Campaign</h3>
            </div>
            <div className="p-4 space-y-3">
              <div>
                <p className="font-semibold text-gray-900">{sendCampaign.name}</p>
                <p className="text-sm text-gray-500">{sendCampaign.subject}</p>
              </div>
              <div className="border rounded-lg p-3 space-y-2 bg-gray-50">
                <div className="flex justify-between text-sm">
                  <span className="text-gray-600">List:</span>
                  <span className="font-medium">{sendCampaign.list_name || 'No list selected'}</span>
                </div>
                <div className="flex justify-between text-sm">
                  <span className="text-gray-600">Subscribers:</span>
                  <span className="font-medium">{getListSubscriberCount(sendCampaign.list_id)}</span>
                </div>
                {sendCampaign.sent_count > 0 && (
                  <div className="flex justify-between text-sm">
                    <span className="text-gray-600">Already sent:</span>
                    <span className="font-medium">
                      {sendCampaign.sent_count} / {sendCampaign.total_recipients}
                    </span>
                  </div>
                )}
              </div>
              {getListSubscriberCount(sendCampaign.list_id) === 0 && (
                <div className="p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-800">
                  Warning: This list has no subscribers. The campaign will have no recipients.
                </div>
              )}
              {!sendCampaign.list_id && (
                <div className="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">
                  No list selected. Please edit the campaign and select a list first.
                </div>
              )}
              {sendCampaign.list_id && (() => {
                const alreadySent = campaigns?.data?.find(
                  (c) => c.id !== sendCampaign.id && c.status === 'sent' && c.list_id === sendCampaign.list_id && c.subject === sendCampaign.subject
                );
                return alreadySent ? (
                  <div className="p-3 bg-orange-50 border border-orange-200 rounded-lg text-sm text-orange-800">
                    Warning: A campaign with this subject was already sent to this list ({alreadySent.name}).
                  </div>
                ) : null;
              })()}
            </div>
            <div className="flex justify-end gap-2 p-4 border-t border-gray-300 bg-gray-50 rounded-b-lg">
              <button
                onClick={() => setSendCampaign(null)}
                disabled={sendMutation.isPending}
                className="px-4 py-2 border-2 border-gray-300 rounded-lg hover:bg-gray-100 font-medium disabled:opacity-50"
              >
                Cancel
              </button>
              <button
                onClick={() => sendMutation.mutate(sendCampaign.id)}
                disabled={sendMutation.isPending || !sendCampaign.list_id}
                className="px-4 py-2 bg-green-600 text-white font-medium rounded-lg hover:bg-green-700 disabled:opacity-50 flex items-center gap-2"
              >
                {sendMutation.isPending && (
                  <div className="animate-spin rounded-full h-4 w-4 border-2 border-white border-t-transparent" />
                )}
                Send Now
              </button>
            </div>
          </div>
        </div>
      )}

      {/* Delete Confirm Modal */}
      <ConfirmModal
        open={!!deleteCampaign}
        onConfirm={() => deleteCampaign && deleteMutation.mutate(deleteCampaign.id)}
        onCancel={() => setDeleteCampaign(null)}
        title="Delete Campaign"
        confirmLabel="Delete"
        confirmColor="red"
        loading={deleteMutation.isPending}
      >
        <p className="text-gray-700">
          Are you sure you want to delete <strong>{deleteCampaign?.name}</strong>? This action cannot be undone.
        </p>
      </ConfirmModal>

      {/* Stats Modal */}
      {statsCampaignId !== null && (
        <div className="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-2xl w-full max-w-2xl mx-4 border border-gray-300 max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b border-gray-300 bg-gray-100 rounded-t-lg">
              <h3 className="text-lg font-bold text-gray-900">
                Campaign Stats
                {statsData && (
                  <span className="text-sm font-normal text-gray-500 ml-2">
                    {statsData.campaign.name}
                  </span>
                )}
              </h3>
              <button
                onClick={() => { setStatsCampaignId(null); setStatsData(null); }}
                className="p-1 hover:bg-gray-200 rounded text-gray-700"
              >
                <X size={20} />
              </button>
            </div>

            <div className="p-4 overflow-y-auto flex-1 space-y-4">
              {statsLoading && (
                <div className="flex items-center justify-center py-12">
                  <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600" />
                </div>
              )}

              {statsData && (
                <>
                  {/* Summary Cards */}
                  <div className="grid grid-cols-3 gap-3">
                    <StatCard label="Sent" value={statsData.stats.sent} />
                    <StatCard
                      label="Opens"
                      value={statsData.stats.opens}
                      rate={statsData.stats.open_rate}
                    />
                    <StatCard
                      label="Clicks"
                      value={statsData.stats.clicks}
                      rate={statsData.stats.click_rate}
                    />
                    <StatCard label="Bounces" value={statsData.stats.bounces} />
                    <StatCard label="Unsubscribes" value={statsData.stats.unsubscribes} />
                    <StatCard
                      label="Recipients"
                      value={statsData.campaign.total_recipients}
                    />
                  </div>

                  {/* Top Clicked Links */}
                  {statsData.clicks.length > 0 && (
                    <div>
                      <h4 className="font-semibold text-gray-900 mb-2">Top Clicked Links</h4>
                      <div className="border rounded-lg overflow-hidden">
                        <table className="w-full text-sm">
                          <thead className="bg-gray-50">
                            <tr>
                              <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">URL</th>
                              <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Clicks</th>
                              <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unique</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y">
                            {statsData.clicks.map((click, idx) => (
                              <tr key={idx} className="hover:bg-gray-50">
                                <td className="px-3 py-2">
                                  <div className="flex items-center gap-1 max-w-xs">
                                    <ExternalLink size={12} className="flex-shrink-0 text-gray-400" />
                                    <span className="truncate text-blue-600" title={click.link_url}>
                                      {click.link_url}
                                    </span>
                                  </div>
                                </td>
                                <td className="px-3 py-2 text-right font-medium">{click.click_count}</td>
                                <td className="px-3 py-2 text-right text-gray-500">{click.unique_clicks}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}

                  {/* Timeline */}
                  {statsData.timeline.length > 0 && (
                    <div>
                      <h4 className="font-semibold text-gray-900 mb-2">Timeline</h4>
                      <div className="border rounded-lg overflow-hidden">
                        <table className="w-full text-sm">
                          <thead className="bg-gray-50">
                            <tr>
                              <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Date</th>
                              <th className="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Event</th>
                              <th className="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">Count</th>
                            </tr>
                          </thead>
                          <tbody className="divide-y">
                            {statsData.timeline.map((row, idx) => (
                              <tr key={idx} className="hover:bg-gray-50">
                                <td className="px-3 py-2">{row.date}</td>
                                <td className="px-3 py-2 capitalize">{row.event_type}</td>
                                <td className="px-3 py-2 text-right font-medium">{row.count}</td>
                              </tr>
                            ))}
                          </tbody>
                        </table>
                      </div>
                    </div>
                  )}

                  {/* No data state */}
                  {statsData.stats.sent === 0 && (
                    <p className="text-center text-gray-500 py-4">
                      No stats yet. Stats will appear after the campaign is sent.
                    </p>
                  )}
                </>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

function StatCard({ label, value, rate }: { label: string; value: number; rate?: number }) {
  return (
    <div className="border rounded-lg p-3 text-center">
      <div className="text-2xl font-bold text-gray-900">{value}</div>
      <div className="text-xs text-gray-500">{label}</div>
      {rate !== undefined && (
        <div className="text-xs font-medium text-blue-600">{rate}%</div>
      )}
    </div>
  );
}
