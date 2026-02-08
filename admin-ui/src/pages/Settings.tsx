import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Save, TestTube, RefreshCw, Eye, EyeOff, Key, Download, X, Check, Loader2 } from 'lucide-react';
import toast from 'react-hot-toast';
import { api } from '../api/client';
import type { Settings, SubscriberList } from '../api/types';

interface GRCampaign {
  campaignId: string;
  name: string;
  description?: string;
}

interface SyncProgress {
  status: 'idle' | 'loading' | 'syncing' | 'done' | 'error';
  campaigns: GRCampaign[];
  currentIndex: number;
  currentName: string;
  results: Array<{
    name: string;
    synced: number;
    created: number;
    updated: number;
    error?: string;
  }>;
  totalSynced: number;
  totalCreated: number;
  totalUpdated: number;
}

export default function SettingsPage() {
  const queryClient = useQueryClient();
  const [activeTab, setActiveTab] = useState('general');
  const [showPassword, setShowPassword] = useState(false);
  const [showSyncModal, setShowSyncModal] = useState(false);
  const [deepSync, setDeepSync] = useState(false);
  const [showTestEmailModal, setShowTestEmailModal] = useState(false);
  const [testEmailAddress, setTestEmailAddress] = useState('');
  const [syncProgress, setSyncProgress] = useState<SyncProgress>({
    status: 'idle',
    campaigns: [],
    currentIndex: -1,
    currentName: '',
    results: [],
    totalSynced: 0,
    totalCreated: 0,
    totalUpdated: 0,
  });

  const { data: settings, isLoading } = useQuery<Settings>({
    queryKey: ['settings'],
    queryFn: () => api.get('/settings'),
  });

  const { data: listsData } = useQuery<{ data: SubscriberList[] }>({
    queryKey: ['lists'],
    queryFn: () => api.get('/lists'),
  });

  const [formData, setFormData] = useState<Partial<Settings>>({});

  useEffect(() => {
    if (settings) {
      setFormData(settings);
    }
  }, [settings]);

  const saveMutation = useMutation({
    mutationFn: (data: Partial<Settings>) => api.put('/settings', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      toast.success('Settings saved');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const testSmtpMutation = useMutation({
    mutationFn: () => api.post('/settings/test-smtp'),
    onSuccess: (data: { message: string }) => toast.success(data.message),
    onError: (error: Error) => toast.error(error.message),
  });

  const testEmailMutation = useMutation({
    mutationFn: (email: string) => api.post('/settings/test-email', { email }),
    onSuccess: (data: { message: string }) => toast.success(data.message),
    onError: (error: Error) => toast.error(error.message),
  });

  const generateApiKeyMutation = useMutation({
    mutationFn: () => api.post('/settings/generate-api-key'),
    onSuccess: (data: { api_key: string }) => {
      setFormData((prev) => ({ ...prev, api_key: data.api_key }));
      queryClient.invalidateQueries({ queryKey: ['settings'] });
      toast.success('API key generated');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const testGetResponseMutation = useMutation({
    mutationFn: () => api.post('/settings/getresponse/test'),
    onSuccess: (data: { message: string }) => toast.success(data.message),
    onError: (error: Error) => toast.error(error.message),
  });

  // Step-by-step sync functions
  const startFullSync = async () => {
    setShowSyncModal(true);
    setSyncProgress({
      status: 'loading',
      campaigns: [],
      currentIndex: -1,
      currentName: 'Loading campaigns...',
      results: [],
      totalSynced: 0,
      totalCreated: 0,
      totalUpdated: 0,
    });

    try {
      // Step 1: Get campaigns from GetResponse
      const response = await api.get<{ campaigns: GRCampaign[]; total: number }>(
        '/settings/getresponse/campaigns'
      );

      const campaigns = response.campaigns;

      if (!campaigns || campaigns.length === 0) {
        setSyncProgress((prev) => ({
          ...prev,
          status: 'done',
          currentName: 'No campaigns found',
        }));
        return;
      }

      setSyncProgress((prev) => ({
        ...prev,
        status: 'syncing',
        campaigns,
        currentIndex: 0,
        currentName: campaigns[0].name,
      }));

      // Step 2: Sync each campaign one by one
      let totalSynced = 0;
      let totalCreated = 0;
      let totalUpdated = 0;
      const results: SyncProgress['results'] = [];

      for (let i = 0; i < campaigns.length; i++) {
        const campaign = campaigns[i];

        setSyncProgress((prev) => ({
          ...prev,
          currentIndex: i,
          currentName: campaign.name,
        }));

        try {
          const result = await api.post<{
            synced: number;
            created: number;
            updated: number;
          }>('/settings/getresponse/sync-campaign', {
            campaign_id: campaign.campaignId,
            campaign_name: campaign.name,
            deep: deepSync,
          }, deepSync ? 300000 : 60000); // 5 min for deep sync, 60 sec for normal

          totalSynced += result.synced;
          totalCreated += result.created;
          totalUpdated += result.updated;

          results.push({
            name: campaign.name,
            synced: result.synced,
            created: result.created,
            updated: result.updated,
          });
        } catch (err) {
          console.error('Sync campaign error:', err);
          results.push({
            name: campaign.name,
            synced: 0,
            created: 0,
            updated: 0,
            error: err instanceof Error ? err.message : String(err),
          });
        }

        setSyncProgress((prev) => ({
          ...prev,
          results: [...results],
          totalSynced,
          totalCreated,
          totalUpdated,
        }));
      }

      // Done
      setSyncProgress((prev) => ({
        ...prev,
        status: 'done',
        currentIndex: campaigns.length,
        currentName: 'Sync completed!',
      }));

      // Refresh data
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      queryClient.invalidateQueries({ queryKey: ['subscribers'] });
    } catch (err) {
      setSyncProgress((prev) => ({
        ...prev,
        status: 'error',
        currentName: err instanceof Error ? err.message : 'Failed to load campaigns',
      }));
    }
  };

  const closeSyncModal = () => {
    setShowSyncModal(false);
    setSyncProgress({
      status: 'idle',
      campaigns: [],
      currentIndex: -1,
      currentName: '',
      results: [],
      totalSynced: 0,
      totalCreated: 0,
      totalUpdated: 0,
    });
  };

  const handleSave = () => {
    saveMutation.mutate(formData);
  };

  const tabs = [
    { id: 'general', label: 'General' },
    { id: 'smtp', label: 'SMTP' },
    { id: 'queue', label: 'Queue' },
    { id: 'tracking', label: 'Tracking' },
    { id: 'webhooks', label: 'Webhooks' },
    { id: 'api', label: 'API' },
    { id: 'getresponse', label: 'GetResponse' },
  ];

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Sync Progress Modal */}
      {showSyncModal && (
        <div className="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-2xl w-full max-w-lg mx-4 border border-gray-300">
            <div className="flex items-center justify-between p-4 border-b border-gray-300 bg-gray-100">
              <h3 className="text-lg font-bold text-gray-900">GetResponse Sync</h3>
              {syncProgress.status === 'done' || syncProgress.status === 'error' ? (
                <button onClick={closeSyncModal} className="p-1 hover:bg-gray-200 rounded text-gray-700">
                  <X size={20} />
                </button>
              ) : null}
            </div>

            <div className="p-4 space-y-4">
              {/* Status */}
              <div className="flex items-center gap-3">
                {syncProgress.status === 'loading' || syncProgress.status === 'syncing' ? (
                  <Loader2 size={24} className="animate-spin text-blue-700" />
                ) : syncProgress.status === 'done' ? (
                  <Check size={24} className="text-green-700" />
                ) : syncProgress.status === 'error' ? (
                  <X size={24} className="text-red-700" />
                ) : null}
                <span className="font-semibold text-gray-900">{syncProgress.currentName}</span>
              </div>

              {/* Progress bar */}
              {syncProgress.campaigns.length > 0 && (
                <div className="space-y-2">
                  <div className="flex justify-between text-sm font-medium text-gray-800">
                    <span>
                      {syncProgress.currentIndex + 1} / {syncProgress.campaigns.length} lists
                    </span>
                    <span>
                      {Math.round(
                        ((syncProgress.currentIndex + 1) / syncProgress.campaigns.length) * 100
                      )}
                      %
                    </span>
                  </div>
                  <div className="w-full bg-gray-300 rounded-full h-3">
                    <div
                      className="bg-blue-600 h-3 rounded-full transition-all duration-300"
                      style={{
                        width: `${((syncProgress.currentIndex + 1) / syncProgress.campaigns.length) * 100}%`,
                      }}
                    />
                  </div>
                </div>
              )}

              {/* Results list */}
              {syncProgress.results.length > 0 && (
                <div className="max-h-48 overflow-y-auto border-2 border-gray-300 rounded">
                  {syncProgress.results.map((result, idx) => (
                    <div
                      key={idx}
                      className={`flex items-center justify-between px-3 py-2 text-sm ${
                        idx % 2 === 0 ? 'bg-gray-100' : 'bg-white'
                      }`}
                    >
                      <span className="truncate flex-1 text-gray-900 font-medium">{result.name}</span>
                      {result.error ? (
                        <span className="text-red-700 text-xs font-semibold">{result.error}</span>
                      ) : (
                        <span className="text-gray-700 text-xs font-semibold">
                          +{result.created} / ~{result.updated}
                        </span>
                      )}
                    </div>
                  ))}
                </div>
              )}

              {/* Summary */}
              {syncProgress.status === 'done' && (
                <div className="bg-green-100 border-2 border-green-400 rounded-lg p-4">
                  <h4 className="font-bold text-green-900 mb-2">Sync Complete!</h4>
                  <div className="grid grid-cols-3 gap-4 text-center">
                    <div>
                      <div className="text-2xl font-bold text-green-800">
                        {syncProgress.totalSynced}
                      </div>
                      <div className="text-xs font-semibold text-green-700">Total</div>
                    </div>
                    <div>
                      <div className="text-2xl font-bold text-green-800">
                        {syncProgress.totalCreated}
                      </div>
                      <div className="text-xs font-semibold text-green-700">Created</div>
                    </div>
                    <div>
                      <div className="text-2xl font-bold text-green-800">
                        {syncProgress.totalUpdated}
                      </div>
                      <div className="text-xs font-semibold text-green-700">Updated</div>
                    </div>
                  </div>
                </div>
              )}
            </div>

            {(syncProgress.status === 'done' || syncProgress.status === 'error') && (
              <div className="p-4 border-t border-gray-300">
                <button
                  onClick={closeSyncModal}
                  className="w-full px-4 py-2 bg-blue-700 text-white font-semibold rounded-lg hover:bg-blue-800"
                >
                  Close
                </button>
              </div>
            )}
          </div>
        </div>
      )}

      {/* Test Email Modal */}
      {showTestEmailModal && (
        <div className="fixed inset-0 bg-black bg-opacity-70 flex items-center justify-center z-50">
          <div className="bg-white rounded-lg shadow-2xl w-full max-w-md mx-4 border border-gray-300">
            <div className="flex items-center justify-between p-4 border-b border-gray-300 bg-gray-100">
              <h3 className="text-lg font-bold text-gray-900">Send Test Email</h3>
              <button
                onClick={() => setShowTestEmailModal(false)}
                className="p-1 hover:bg-gray-200 rounded text-gray-700"
              >
                <X size={20} />
              </button>
            </div>
            <div className="p-4 space-y-4">
              <div>
                <label className="block text-sm font-medium text-gray-900 mb-1">
                  Email Address
                </label>
                <input
                  type="email"
                  value={testEmailAddress}
                  onChange={(e) => setTestEmailAddress(e.target.value)}
                  placeholder="test@example.com"
                  className="w-full px-3 py-2 border-2 border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  autoFocus
                />
                <p className="text-sm text-gray-500 mt-1">
                  A test email will be sent to this address
                </p>
              </div>
            </div>
            <div className="flex justify-end gap-2 p-4 border-t border-gray-300 bg-gray-50">
              <button
                onClick={() => setShowTestEmailModal(false)}
                className="px-4 py-2 border-2 border-gray-300 rounded-lg hover:bg-gray-100 font-medium"
              >
                Cancel
              </button>
              <button
                onClick={() => {
                  const email = testEmailAddress.trim();
                  if (email) {
                    testEmailMutation.mutate(email);
                    setShowTestEmailModal(false);
                  }
                }}
                disabled={!testEmailAddress.trim() || testEmailMutation.isPending}
                className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50 font-medium"
              >
                {testEmailMutation.isPending ? 'Sending...' : 'Send Test'}
              </button>
            </div>
          </div>
        </div>
      )}

      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Settings</h1>
        <button
          onClick={handleSave}
          disabled={saveMutation.isPending}
          className="flex items-center gap-2 px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
        >
          <Save size={18} />
          {saveMutation.isPending ? 'Saving...' : 'Save Settings'}
        </button>
      </div>

      {/* Tabs */}
      <div className="border-b">
        <div className="flex gap-4">
          {tabs.map((tab) => (
            <button
              key={tab.id}
              onClick={() => setActiveTab(tab.id)}
              className={`px-4 py-2 border-b-2 transition-colors ${
                activeTab === tab.id
                  ? 'border-blue-600 text-blue-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700'
              }`}
            >
              {tab.label}
            </button>
          ))}
        </div>
      </div>

      {/* Tab Content */}
      <div className="bg-white rounded-lg shadow p-6">
        {activeTab === 'general' && (
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="block text-sm font-medium mb-1">Default From Name</label>
              <input
                type="text"
                value={formData.from_name || ''}
                onChange={(e) => setFormData({ ...formData, from_name: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Default From Email</label>
              <input
                type="email"
                value={formData.from_email || ''}
                onChange={(e) => setFormData({ ...formData, from_email: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Default List</label>
              <select
                value={formData.default_list_id || ''}
                onChange={(e) =>
                  setFormData({ ...formData, default_list_id: Number(e.target.value) || undefined })
                }
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              >
                <option value="">None</option>
                {listsData?.data?.map((list) => (
                  <option key={list.id} value={list.id}>
                    {list.name}
                  </option>
                ))}
              </select>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.double_optin || false}
                  onChange={(e) => setFormData({ ...formData, double_optin: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm">Require double opt-in by default</span>
              </label>
            </div>
          </div>
        )}

        {activeTab === 'smtp' && (
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.smtp_enabled || false}
                  onChange={(e) => setFormData({ ...formData, smtp_enabled: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm font-medium">Enable SMTP</span>
              </label>
              <p className="text-sm text-gray-500 mt-1">
                Use custom SMTP server instead of WordPress default
              </p>
            </div>

            {formData.smtp_enabled && (
              <>
                <div>
                  <label className="block text-sm font-medium mb-1">SMTP Host</label>
                  <input
                    type="text"
                    value={formData.smtp_host || ''}
                    onChange={(e) => setFormData({ ...formData, smtp_host: e.target.value })}
                    placeholder="smtp.example.com"
                    className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                  />
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <label className="block text-sm font-medium mb-1">Port</label>
                    <input
                      type="number"
                      value={formData.smtp_port || 587}
                      onChange={(e) => setFormData({ ...formData, smtp_port: Number(e.target.value) })}
                      className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    />
                  </div>
                  <div>
                    <label className="block text-sm font-medium mb-1">Encryption</label>
                    <select
                      value={formData.smtp_encryption || 'tls'}
                      onChange={(e) =>
                        setFormData({ ...formData, smtp_encryption: e.target.value as 'tls' | 'ssl' | 'none' })
                      }
                      className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                    >
                      <option value="tls">TLS</option>
                      <option value="ssl">SSL</option>
                      <option value="none">None</option>
                    </select>
                  </div>
                </div>
                <div>
                  <label className="flex items-center gap-2">
                    <input
                      type="checkbox"
                      checked={formData.smtp_auth ?? true}
                      onChange={(e) => setFormData({ ...formData, smtp_auth: e.target.checked })}
                      className="rounded"
                    />
                    <span className="text-sm">Require authentication</span>
                  </label>
                </div>
                {formData.smtp_auth && (
                  <>
                    <div>
                      <label className="block text-sm font-medium mb-1">Username</label>
                      <input
                        type="text"
                        value={formData.smtp_username || ''}
                        onChange={(e) => setFormData({ ...formData, smtp_username: e.target.value })}
                        className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                      />
                    </div>
                    <div>
                      <label className="block text-sm font-medium mb-1">Password</label>
                      <div className="relative">
                        <input
                          type={showPassword ? 'text' : 'password'}
                          value={formData.smtp_password || ''}
                          onChange={(e) => setFormData({ ...formData, smtp_password: e.target.value })}
                          placeholder={settings?.smtp_password_masked || ''}
                          className="w-full px-3 py-2 pr-10 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
                        />
                        <button
                          type="button"
                          onClick={() => setShowPassword(!showPassword)}
                          className="absolute right-2 top-1/2 -translate-y-1/2 p-1"
                        >
                          {showPassword ? <EyeOff size={18} /> : <Eye size={18} />}
                        </button>
                      </div>
                    </div>
                  </>
                )}
                <div className="flex gap-2 pt-4">
                  <button
                    onClick={() => testSmtpMutation.mutate()}
                    disabled={testSmtpMutation.isPending}
                    className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
                  >
                    <RefreshCw size={18} />
                    {testSmtpMutation.isPending ? 'Testing...' : 'Test Connection'}
                  </button>
                  <button
                    onClick={() => {
                      setTestEmailAddress(settings?.from_email || '');
                      setShowTestEmailModal(true);
                    }}
                    disabled={testEmailMutation.isPending}
                    className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
                  >
                    <TestTube size={18} />
                    {testEmailMutation.isPending ? 'Sending...' : 'Send Test Email'}
                  </button>
                </div>
              </>
            )}
          </div>
        )}

        {activeTab === 'queue' && (
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.intercept_wp_mail || false}
                  onChange={(e) => setFormData({ ...formData, intercept_wp_mail: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm font-medium">Intercept wp_mail()</span>
              </label>
              <p className="text-sm text-gray-500 mt-1">
                Route all WordPress emails through the queue
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Batch Size</label>
              <input
                type="number"
                value={formData.queue_batch_size || 50}
                onChange={(e) => setFormData({ ...formData, queue_batch_size: Number(e.target.value) })}
                min={1}
                max={200}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p className="text-sm text-gray-500 mt-1">
                Emails to process per cron run (1-200)
              </p>
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Processing Interval (minutes)</label>
              <input
                type="number"
                value={formData.queue_interval || 2}
                onChange={(e) => setFormData({ ...formData, queue_interval: Number(e.target.value) })}
                min={1}
                max={60}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>
        )}

        {activeTab === 'tracking' && (
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.track_opens ?? true}
                  onChange={(e) => setFormData({ ...formData, track_opens: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm font-medium">Track email opens</span>
              </label>
              <p className="text-sm text-gray-500 mt-1">
                Adds a tracking pixel to campaign emails
              </p>
            </div>
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.track_clicks ?? true}
                  onChange={(e) => setFormData({ ...formData, track_clicks: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm font-medium">Track link clicks</span>
              </label>
              <p className="text-sm text-gray-500 mt-1">
                Wraps links in campaign emails for click tracking
              </p>
            </div>
          </div>
        )}

        {activeTab === 'webhooks' && (
          <div className="space-y-6 max-w-lg">
            <p className="text-sm text-gray-600">
              Configure webhooks from your email provider to handle bounces and complaints.
            </p>
            <div>
              <label className="block text-sm font-medium mb-1">Mailgun Webhook URL</label>
              <input
                type="text"
                value={`${window.location.origin}/wp-json/jan-newsletter/v1/webhooks/mailgun`}
                readOnly
                className="w-full px-3 py-2 border rounded-lg bg-gray-50"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">Mailgun Signing Key</label>
              <input
                type="text"
                value={formData.mailgun_signing_key || ''}
                onChange={(e) => setFormData({ ...formData, mailgun_signing_key: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">SendGrid Webhook URL</label>
              <input
                type="text"
                value={`${window.location.origin}/wp-json/jan-newsletter/v1/webhooks/sendgrid`}
                readOnly
                className="w-full px-3 py-2 border rounded-lg bg-gray-50"
              />
            </div>
            <div>
              <label className="block text-sm font-medium mb-1">SendGrid Signing Key</label>
              <input
                type="text"
                value={formData.sendgrid_signing_key || ''}
                onChange={(e) => setFormData({ ...formData, sendgrid_signing_key: e.target.value })}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
            </div>
          </div>
        )}

        {activeTab === 'api' && (
          <div className="space-y-6 max-w-lg">
            <div>
              <label className="flex items-center gap-2">
                <input
                  type="checkbox"
                  checked={formData.api_enabled || false}
                  onChange={(e) => setFormData({ ...formData, api_enabled: e.target.checked })}
                  className="rounded"
                />
                <span className="text-sm font-medium">Enable REST API</span>
              </label>
              <p className="text-sm text-gray-500 mt-1">
                Allow external applications to access the API
              </p>
            </div>
            {formData.api_enabled && (
              <div>
                <label className="block text-sm font-medium mb-1">API Key</label>
                <div className="flex gap-2">
                  <input
                    type="text"
                    value={formData.api_key || settings?.api_key_masked || ''}
                    readOnly
                    className="flex-1 px-3 py-2 border rounded-lg bg-gray-50"
                  />
                  <button
                    onClick={() => {
                      if (confirm('Generate a new API key? This will invalidate the current key.')) {
                        generateApiKeyMutation.mutate();
                      }
                    }}
                    disabled={generateApiKeyMutation.isPending}
                    className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
                  >
                    <Key size={18} />
                    Generate
                  </button>
                </div>
              </div>
            )}
          </div>
        )}

        {activeTab === 'getresponse' && (
          <div className="space-y-6 max-w-lg">
            <p className="text-sm text-gray-600">
              Import lists and subscribers from GetResponse.
            </p>
            <div>
              <label className="block text-sm font-medium mb-1">GetResponse API Key</label>
              <input
                type="password"
                value={formData.getresponse_api_key || ''}
                onChange={(e) => setFormData({ ...formData, getresponse_api_key: e.target.value })}
                placeholder={settings?.getresponse_api_key_masked || 'Enter your GetResponse API key'}
                className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
              />
              <p className="text-sm text-gray-500 mt-1">
                Get your API key from GetResponse → Integrations → API
              </p>
            </div>

            <div className="flex gap-2 pt-4">
              <button
                onClick={() => testGetResponseMutation.mutate()}
                disabled={testGetResponseMutation.isPending}
                className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50 disabled:opacity-50"
              >
                <RefreshCw size={18} className={testGetResponseMutation.isPending ? 'animate-spin' : ''} />
                {testGetResponseMutation.isPending ? 'Testing...' : 'Test Connection'}
              </button>
            </div>

            <div className="border-t pt-6">
              <h3 className="text-lg font-medium mb-4">Sync</h3>

              <div className="mb-4">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    checked={deepSync}
                    onChange={(e) => setDeepSync(e.target.checked)}
                    className="rounded"
                  />
                  <span className="text-sm font-medium">Deep sync</span>
                </label>
                <p className="text-sm text-gray-500 mt-1 ml-6">
                  Fetch full contact details individually (slower, but gets all custom fields, geolocation, tags)
                </p>
              </div>

              <div className="flex items-center justify-between p-4 bg-green-50 border border-green-200 rounded-lg">
                <div>
                  <h4 className="font-medium text-green-800">
                    {deepSync ? 'Full Sync (Deep)' : 'Full Sync'}
                  </h4>
                  <p className="text-sm text-green-600">
                    {deepSync
                      ? 'Import all lists with complete contact data (birthdate, city, phone, tags, etc.)'
                      : 'Import all lists and their subscribers with progress'}
                  </p>
                </div>
                <button
                  onClick={startFullSync}
                  disabled={syncProgress.status === 'loading' || syncProgress.status === 'syncing'}
                  className="flex items-center gap-2 px-4 py-2 bg-green-600 text-white rounded-lg hover:bg-green-700 disabled:opacity-50"
                >
                  <Download size={18} />
                  Start Sync
                </button>
              </div>
            </div>
          </div>
        )}
      </div>
    </div>
  );
}
