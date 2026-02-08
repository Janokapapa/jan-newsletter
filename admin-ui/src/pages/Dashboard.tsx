import { useQuery } from '@tanstack/react-query';
import {
  Users,
  Mail,
  Send,
  TrendingUp,
  MousePointer,
  Eye,
  AlertCircle,
} from 'lucide-react';
import { api } from '../api/client';
import type { DashboardStats } from '../api/types';
import {
  LineChart,
  Line,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  ResponsiveContainer,
} from 'recharts';

interface StatCardProps {
  title: string;
  value: string | number;
  icon: React.ReactNode;
  subtitle?: string;
  color?: string;
}

function StatCard({ title, value, icon, subtitle, color = 'blue' }: StatCardProps) {
  const colors = {
    blue: 'bg-blue-50 text-blue-600',
    green: 'bg-green-50 text-green-600',
    yellow: 'bg-yellow-50 text-yellow-600',
    red: 'bg-red-50 text-red-600',
    purple: 'bg-purple-50 text-purple-600',
  };

  return (
    <div className="bg-white rounded-lg shadow p-6">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-gray-500">{title}</p>
          <p className="text-2xl font-bold mt-1">{value}</p>
          {subtitle && <p className="text-xs text-gray-400 mt-1">{subtitle}</p>}
        </div>
        <div className={`p-3 rounded-full ${colors[color as keyof typeof colors]}`}>
          {icon}
        </div>
      </div>
    </div>
  );
}

export default function Dashboard() {
  const { data: stats, isLoading, error } = useQuery<DashboardStats>({
    queryKey: ['dashboard-stats'],
    queryFn: () => api.get('/stats'),
  });

  const { data: dailyStats } = useQuery<{ data: { date: string; sent: number; opens: number; clicks: number }[] }>({
    queryKey: ['daily-stats'],
    queryFn: () => api.get('/stats/daily?days=30'),
  });

  if (isLoading) {
    return (
      <div className="flex items-center justify-center h-64">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
      </div>
    );
  }

  if (error) {
    return (
      <div className="bg-red-50 text-red-600 p-4 rounded-lg flex items-center gap-2">
        <AlertCircle size={20} />
        <span>Failed to load dashboard data</span>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <h1 className="text-2xl font-bold text-gray-800">Dashboard</h1>

      {/* Stats Grid */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard
          title="Total Subscribers"
          value={stats?.subscribers.total || 0}
          icon={<Users size={24} />}
          subtitle={`+${stats?.subscribers.new_last_30_days || 0} last 30 days`}
          color="blue"
        />
        <StatCard
          title="Active Subscribers"
          value={stats?.subscribers.subscribed || 0}
          icon={<TrendingUp size={24} />}
          color="green"
        />
        <StatCard
          title="Emails Sent Today"
          value={stats?.queue.sent_today || 0}
          icon={<Send size={24} />}
          subtitle={`${stats?.queue.sent_this_week || 0} this week`}
          color="purple"
        />
        <StatCard
          title="Queue Pending"
          value={stats?.queue.pending || 0}
          icon={<Mail size={24} />}
          subtitle={stats?.queue.failed ? `${stats.queue.failed} failed` : undefined}
          color={stats?.queue.failed ? 'red' : 'yellow'}
        />
      </div>

      {/* Charts Row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
        {/* Email Activity Chart */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-4">Email Activity (30 days)</h2>
          <div className="h-64">
            <ResponsiveContainer width="100%" height="100%">
              <LineChart data={dailyStats?.data || []}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis
                  dataKey="date"
                  tickFormatter={(value) => new Date(value).toLocaleDateString('en', { month: 'short', day: 'numeric' })}
                />
                <YAxis />
                <Tooltip
                  labelFormatter={(value) => new Date(value).toLocaleDateString()}
                />
                <Line type="monotone" dataKey="sent" stroke="#3b82f6" name="Sent" />
                <Line type="monotone" dataKey="opens" stroke="#10b981" name="Opens" />
                <Line type="monotone" dataKey="clicks" stroke="#f59e0b" name="Clicks" />
              </LineChart>
            </ResponsiveContainer>
          </div>
        </div>

        {/* Performance Stats */}
        <div className="bg-white rounded-lg shadow p-6">
          <h2 className="text-lg font-semibold mb-4">Performance Overview</h2>
          <div className="space-y-4">
            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div className="flex items-center gap-3">
                <Eye className="text-blue-600" size={24} />
                <div>
                  <p className="font-medium">Average Open Rate</p>
                  <p className="text-sm text-gray-500">Across all campaigns</p>
                </div>
              </div>
              <span className="text-2xl font-bold text-blue-600">
                {stats?.emails.avg_open_rate || 0}%
              </span>
            </div>

            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div className="flex items-center gap-3">
                <MousePointer className="text-green-600" size={24} />
                <div>
                  <p className="font-medium">Average Click Rate</p>
                  <p className="text-sm text-gray-500">Across all campaigns</p>
                </div>
              </div>
              <span className="text-2xl font-bold text-green-600">
                {stats?.emails.avg_click_rate || 0}%
              </span>
            </div>

            <div className="flex items-center justify-between p-4 bg-gray-50 rounded-lg">
              <div className="flex items-center gap-3">
                <Mail className="text-purple-600" size={24} />
                <div>
                  <p className="font-medium">Total Emails Sent</p>
                  <p className="text-sm text-gray-500">All time</p>
                </div>
              </div>
              <span className="text-2xl font-bold text-purple-600">
                {stats?.emails.total_sent || 0}
              </span>
            </div>
          </div>
        </div>
      </div>

      {/* Recent Campaigns */}
      <div className="bg-white rounded-lg shadow">
        <div className="p-6 border-b">
          <h2 className="text-lg font-semibold">Recent Campaigns</h2>
        </div>
        <div className="overflow-x-auto">
          <table className="w-full">
            <thead className="bg-gray-50">
              <tr>
                <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                  Campaign
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
              </tr>
            </thead>
            <tbody className="divide-y">
              {stats?.recent_campaigns?.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-6 py-8 text-center text-gray-500">
                    No campaigns yet
                  </td>
                </tr>
              )}
              {stats?.recent_campaigns?.map((campaign) => (
                <tr key={campaign.id} className="hover:bg-gray-50">
                  <td className="px-6 py-4">
                    <div>
                      <p className="font-medium">{campaign.name}</p>
                      <p className="text-sm text-gray-500">{campaign.subject}</p>
                    </div>
                  </td>
                  <td className="px-6 py-4">
                    <span
                      className={`px-2 py-1 rounded-full text-xs font-medium ${
                        campaign.status === 'sent'
                          ? 'bg-green-100 text-green-800'
                          : campaign.status === 'sending'
                          ? 'bg-blue-100 text-blue-800'
                          : 'bg-gray-100 text-gray-800'
                      }`}
                    >
                      {campaign.status}
                    </span>
                  </td>
                  <td className="px-6 py-4">{campaign.sent_count}</td>
                  <td className="px-6 py-4">
                    {campaign.open_count} ({campaign.open_rate}%)
                  </td>
                  <td className="px-6 py-4">
                    {campaign.click_count} ({campaign.click_rate}%)
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </div>
  );
}
