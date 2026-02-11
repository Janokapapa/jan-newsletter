import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Send, Eye, Save, TestTube } from 'lucide-react';
import toast from 'react-hot-toast';
import { api, config } from '../api/client';
import type { Campaign, SubscriberList } from '../api/types';
import EmailEditor from '../components/EmailEditor';
import ConfirmModal from '../components/ConfirmModal';

interface CampaignEditorProps {
  campaignId: number | null;
  onBack: () => void;
}

export default function CampaignEditor({ campaignId, onBack }: CampaignEditorProps) {
  const queryClient = useQueryClient();
  const [showPreview, setShowPreview] = useState(false);
  const [showSendConfirm, setShowSendConfirm] = useState(false);
  const [testEmail, setTestEmail] = useState(config.adminEmail || '');

  const [formData, setFormData] = useState({
    name: '',
    subject: '',
    body_html: '',
    body_text: '',
    from_name: '',
    from_email: '',
    list_id: '' as string | number,
  });

  // Load campaign if editing
  const { data: campaign, isLoading: loadingCampaign } = useQuery<Campaign>({
    queryKey: ['campaign', campaignId],
    queryFn: () => api.get(`/campaigns/${campaignId}`),
    enabled: !!campaignId,
  });

  // Load lists
  const { data: listsData } = useQuery<{ data: SubscriberList[] }>({
    queryKey: ['lists'],
    queryFn: () => api.get('/lists'),
  });

  // Load preview
  const { data: previewData, refetch: refetchPreview } = useQuery<{ html: string }>({
    queryKey: ['campaign-preview', campaignId],
    queryFn: () => api.get(`/campaigns/${campaignId}/preview`),
    enabled: false,
  });

  // Populate form when campaign loads
  useEffect(() => {
    if (campaign) {
      setFormData({
        name: campaign.name,
        subject: campaign.subject,
        body_html: campaign.body_html || '',
        body_text: campaign.body_text || '',
        from_name: campaign.from_name,
        from_email: campaign.from_email,
        list_id: campaign.list_id || '',
      });
    }
  }, [campaign]);

  const createMutation = useMutation({
    mutationFn: (data: typeof formData) => api.post('/campaigns', data),
    onSuccess: (response: { campaign: Campaign }) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success('Campaign created');
      // Update to edit mode
      window.history.replaceState({}, '', `?campaign=${response.campaign.id}`);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const updateMutation = useMutation({
    mutationFn: (data: typeof formData) => api.put(`/campaigns/${campaignId}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      queryClient.invalidateQueries({ queryKey: ['campaign', campaignId] });
      toast.success('Campaign saved');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const testMutation = useMutation({
    mutationFn: (email: string) =>
      api.post(`/campaigns/${campaignId}/test`, { email }),
    onSuccess: (data: { message: string }) => {
      toast.success(data.message);
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const sendMutation = useMutation({
    mutationFn: () => api.post(`/campaigns/${campaignId}/send`),
    onSuccess: (data: { message: string }) => {
      queryClient.invalidateQueries({ queryKey: ['campaigns'] });
      toast.success(data.message);
      onBack();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const handleSave = () => {
    if (campaignId) {
      updateMutation.mutate(formData);
    } else {
      createMutation.mutate(formData);
    }
  };

  const handlePreview = async () => {
    if (campaignId) {
      await refetchPreview();
      setShowPreview(true);
    } else {
      toast.error('Save the campaign first to preview');
    }
  };

  const handleSendTest = () => {
    if (!campaignId) {
      toast.error('Save the campaign first');
      return;
    }
    if (!testEmail) {
      toast.error('Enter a test email address');
      return;
    }
    testMutation.mutate(testEmail);
  };

  const handleSend = () => {
    if (!campaignId) {
      toast.error('Save the campaign first');
      return;
    }
    if (!formData.list_id) {
      toast.error('Select a list first');
      return;
    }
    setShowSendConfirm(true);
  };

  const isReadOnly = campaign?.status === 'sent' || campaign?.status === 'sending';

  if (loadingCampaign) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-4">
          <button
            onClick={onBack}
            className="p-2 hover:bg-gray-100 rounded-lg"
          >
            <ArrowLeft size={20} />
          </button>
          <h1 className="text-2xl font-bold text-gray-800">
            {campaignId ? (isReadOnly ? 'View Campaign' : 'Edit Campaign') : 'New Campaign'}
          </h1>
        </div>
        <div className="flex gap-2">
          {!isReadOnly && (
            <>
              <button
                onClick={handleSave}
                disabled={createMutation.isPending || updateMutation.isPending}
                className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
              >
                <Save size={18} />
                Save Draft
              </button>
              <button
                onClick={handlePreview}
                className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50"
              >
                <Eye size={18} />
                Preview
              </button>
              <button
                onClick={handleSend}
                disabled={sendMutation.isPending}
                className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
              >
                <Send size={18} />
                Send Now
              </button>
            </>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
        {/* Main Content */}
        <div className="lg:col-span-2 space-y-6">
          {/* Campaign Details */}
          <div className="bg-white rounded-lg shadow p-6 space-y-4">
            <div>
              <label className="block text-sm font-medium mb-1">Campaign Name</label>
              <input
                type="text"
                value={formData.name}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                disabled={isReadOnly}
                placeholder="e.g. Monthly Newsletter - January"
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Subject Line</label>
              <input
                type="text"
                value={formData.subject}
                onChange={(e) => setFormData({ ...formData, subject: e.target.value })}
                disabled={isReadOnly}
                placeholder="e.g. Your January Update"
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
              />
            </div>
          </div>

          {/* Email Editor */}
          <div className="bg-white rounded-lg shadow">
            <div className="p-4 border-b">
              <h2 className="font-semibold">Email Content</h2>
            </div>
            <EmailEditor
              content={formData.body_html}
              onChange={(html) => setFormData({ ...formData, body_html: html })}
              disabled={isReadOnly}
            />
          </div>
        </div>

        {/* Sidebar */}
        <div className="space-y-6">
          {/* Campaign Settings */}
          <div className="bg-white rounded-lg shadow p-6 space-y-4">
            <h2 className="font-semibold">Settings</h2>

            <div>
              <label className="block text-sm font-medium mb-1">Send To List</label>
              <select
                value={formData.list_id}
                onChange={(e) => setFormData({ ...formData, list_id: e.target.value })}
                disabled={isReadOnly}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
              >
                <option value="">Select a list...</option>
                {listsData?.data?.map((list) => (
                  <option key={list.id} value={list.id}>
                    {list.name} ({list.subscriber_count})
                  </option>
                ))}
              </select>
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">From Name</label>
              <input
                type="text"
                value={formData.from_name}
                onChange={(e) => setFormData({ ...formData, from_name: e.target.value })}
                disabled={isReadOnly}
                placeholder={config.siteName}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
              />
            </div>

            <div>
              <label className="block text-sm font-medium mb-1">From Email</label>
              <input
                type="email"
                value={formData.from_email}
                onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                disabled={isReadOnly}
                placeholder={config.adminEmail}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100"
              />
            </div>
          </div>

          {/* Test Email */}
          {!isReadOnly && campaignId && (
            <div className="bg-white rounded-lg shadow p-6 space-y-4">
              <h2 className="font-semibold">Send Test Email</h2>
              <div>
                <input
                  type="email"
                  value={testEmail}
                  onChange={(e) => setTestEmail(e.target.value)}
                  placeholder="test@example.com"
                  className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
              </div>
              <button
                onClick={handleSendTest}
                disabled={testMutation.isPending}
                className="w-full flex items-center justify-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
              >
                <TestTube size={18} />
                {testMutation.isPending ? 'Sending...' : 'Send Test'}
              </button>
            </div>
          )}

          {/* Campaign Status */}
          {campaign && (
            <div className="bg-white rounded-lg shadow p-6 space-y-2">
              <h2 className="font-semibold">Status</h2>
              <p className="text-sm">
                <span className="text-gray-500">Status:</span>{' '}
                <span className="font-medium capitalize">{campaign.status}</span>
              </p>
              {campaign.sent_count > 0 && (
                <>
                  <p className="text-sm">
                    <span className="text-gray-500">Sent:</span>{' '}
                    {campaign.sent_count}/{campaign.total_recipients}
                  </p>
                  <p className="text-sm">
                    <span className="text-gray-500">Opens:</span>{' '}
                    {campaign.open_count} ({campaign.open_rate}%)
                  </p>
                  <p className="text-sm">
                    <span className="text-gray-500">Clicks:</span>{' '}
                    {campaign.click_count} ({campaign.click_rate}%)
                  </p>
                </>
              )}
            </div>
          )}
        </div>
      </div>

      {/* Send Confirm Modal */}
      <ConfirmModal
        open={showSendConfirm}
        onConfirm={async () => {
          // Save first, then send
          try {
            await api.put(`/campaigns/${campaignId}`, formData);
            queryClient.invalidateQueries({ queryKey: ['campaigns'] });
            queryClient.invalidateQueries({ queryKey: ['campaign', campaignId] });
          } catch {
            toast.error('Failed to save campaign before sending');
            setShowSendConfirm(false);
            return;
          }
          sendMutation.mutate();
        }}
        onCancel={() => setShowSendConfirm(false)}
        title="Send Campaign"
        confirmLabel="Send Now"
        confirmColor="green"
        loading={sendMutation.isPending}
      >
        <p className="text-gray-700">
          Are you sure you want to send <strong>{formData.name || 'this campaign'}</strong> now?
        </p>
        {formData.list_id && listsData?.data && (() => {
          const list = listsData.data.find((l) => String(l.id) === String(formData.list_id));
          return list ? (
            <p className="text-sm text-gray-500 mt-2">
              Sending to: <strong>{list.name}</strong> ({list.subscriber_count} subscribers)
            </p>
          ) : null;
        })()}
      </ConfirmModal>

      {/* Preview Modal */}
      {showPreview && (
        <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg w-full max-w-4xl max-h-[90vh] flex flex-col">
            <div className="flex items-center justify-between p-4 border-b">
              <h2 className="text-lg font-semibold">Email Preview</h2>
              <button
                onClick={() => setShowPreview(false)}
                className="p-2 hover:bg-gray-100 rounded"
              >
                ×
              </button>
            </div>
            <div className="flex-1 overflow-auto p-4">
              <iframe
                srcDoc={previewData?.html || ''}
                className="w-full h-full min-h-[500px] border rounded"
                title="Email Preview"
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
