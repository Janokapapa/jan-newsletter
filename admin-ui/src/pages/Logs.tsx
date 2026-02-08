import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { RefreshCw, Eye, X } from 'lucide-react';
import { api } from '../api/client';
import type { EmailLog, PaginatedResponse } from '../api/types';

export default function Logs() {
  const [page, setPage] = useState(1);
  const [statusFilter, setStatusFilter] = useState('');
  const [selectedLogId, setSelectedLogId] = useState<number | null>(null);

  const { data: logs, isLoading, refetch } = useQuery<PaginatedResponse<EmailLog>>({
    queryKey: ['logs', page, statusFilter],
    queryFn: () => {
      const params = new URLSearchParams();
      params.set('page', String(page));
      params.set('per_page', '50');
      if (statusFilter) params.set('status', statusFilter);
      return api.get(`/stats/logs?${params.toString()}`);
    },
  });

  const { data: logDetail, isLoading: isLoadingDetail } = useQuery<{ data: EmailLog }>({
    queryKey: ['log-detail', selectedLogId],
    queryFn: () => api.get(`/stats/logs/${selectedLogId}`),
    enabled: selectedLogId !== null,
  });

  const statusColors: Record<string, string> = {
    sent: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
  };

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Email Logs</h1>
        <button
          onClick={() => refetch()}
          className="flex items-center gap-2 px-4 py-2 border rounded-lg hover:bg-gray-50"
        >
          <RefreshCw size={18} />
          Refresh
        </button>
      </div>

      {/* Filter */}
      <div>
        <select
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
          className="px-4 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
        >
          <option value="">All Status</option>
          <option value="sent">Sent</option>
          <option value="failed">Failed</option>
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
                Source
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Sent At
              </th>
              <th className="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">
                Actions
              </th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                  Loading...
                </td>
              </tr>
            )}
            {!isLoading && logs?.data?.length === 0 && (
              <tr>
                <td colSpan={6} className="px-6 py-8 text-center text-gray-500">
                  No logs yet
                </td>
              </tr>
            )}
            {logs?.data?.map((log) => (
              <tr key={log.id} className="hover:bg-gray-50">
                <td className="px-6 py-4">
                  <span className="text-sm">{log.to_email}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm">{log.subject}</span>
                </td>
                <td className="px-6 py-4">
                  <span
                    className={`px-2 py-1 rounded-full text-xs font-medium ${
                      statusColors[log.status] || 'bg-gray-100 text-gray-800'
                    }`}
                  >
                    {log.status}
                  </span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-gray-500">{log.source}</span>
                </td>
                <td className="px-6 py-4">
                  <span className="text-sm text-gray-500">
                    {new Date(log.sent_at).toLocaleString()}
                  </span>
                </td>
                <td className="px-6 py-4">
                  <button
                    onClick={() => setSelectedLogId(log.id)}
                    className="p-2 text-gray-500 hover:text-blue-600 hover:bg-blue-50 rounded-lg transition-colors"
                    title="View details"
                  >
                    <Eye size={18} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Pagination */}
      {logs?.meta && logs.meta.total_pages > 1 && (
        <div className="flex items-center justify-between">
          <span className="text-sm text-gray-500">
            Showing {(page - 1) * 50 + 1} to{' '}
            {Math.min(page * 50, logs.meta.total)} of {logs.meta.total}
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
              onClick={() => setPage((p) => Math.min(logs.meta.total_pages, p + 1))}
              disabled={page === logs.meta.total_pages}
              className="px-3 py-1 border rounded disabled:opacity-50"
            >
              Next
            </button>
          </div>
        </div>
      )}

      {/* Detail Modal */}
      {selectedLogId !== null && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
          <div className="bg-white rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
            {/* Modal Header */}
            <div className="flex items-center justify-between px-6 py-4 border-b">
              <h2 className="text-xl font-semibold text-gray-800">Email Details</h2>
              <button
                onClick={() => setSelectedLogId(null)}
                className="p-2 text-gray-500 hover:text-gray-700 hover:bg-gray-100 rounded-lg"
              >
                <X size={20} />
              </button>
            </div>

            {/* Modal Content */}
            <div className="overflow-y-auto flex-1 p-6">
              {isLoadingDetail ? (
                <div className="text-center py-8 text-gray-500">Loading...</div>
              ) : logDetail?.data ? (
                <div className="space-y-6">
                  {/* Basic Info */}
                  <div className="grid grid-cols-2 gap-4">
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        To
                      </label>
                      <div className="text-sm font-mono bg-gray-50 p-2 rounded">
                        {logDetail.data.to_email}
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        From
                      </label>
                      <div className="text-sm font-mono bg-gray-50 p-2 rounded">
                        {logDetail.data.from_name
                          ? `${logDetail.data.from_name} <${logDetail.data.from_email}>`
                          : logDetail.data.from_email || '-'}
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Subject
                      </label>
                      <div className="text-sm font-mono bg-gray-50 p-2 rounded">
                        {logDetail.data.subject}
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Status
                      </label>
                      <div className="text-sm">
                        <span
                          className={`px-2 py-1 rounded-full text-xs font-medium ${
                            statusColors[logDetail.data.status] || 'bg-gray-100 text-gray-800'
                          }`}
                        >
                          {logDetail.data.status}
                        </span>
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Source
                      </label>
                      <div className="text-sm font-mono bg-gray-50 p-2 rounded">
                        {logDetail.data.source}
                      </div>
                    </div>
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Sent At
                      </label>
                      <div className="text-sm font-mono bg-gray-50 p-2 rounded">
                        {new Date(logDetail.data.sent_at).toLocaleString()}
                      </div>
                    </div>
                  </div>

                  {/* SMTP Response */}
                  {logDetail.data.smtp_response && (
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        SMTP Response
                      </label>
                      <pre className="text-xs font-mono bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap">
                        {logDetail.data.smtp_response}
                      </pre>
                    </div>
                  )}

                  {/* Headers */}
                  {logDetail.data.headers && (
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Headers
                      </label>
                      <pre className="text-xs font-mono bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap max-h-40">
                        {logDetail.data.headers}
                      </pre>
                    </div>
                  )}

                  {/* Body Text */}
                  {logDetail.data.body_text && (
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Body (Plain Text)
                      </label>
                      <pre className="text-xs font-mono bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap max-h-60">
                        {logDetail.data.body_text}
                      </pre>
                    </div>
                  )}

                  {/* Body HTML */}
                  {logDetail.data.body_html && (
                    <div>
                      <label className="block text-xs font-medium text-gray-500 uppercase mb-1">
                        Body (HTML)
                      </label>
                      <div className="border rounded overflow-hidden">
                        <div className="bg-gray-100 px-3 py-2 border-b">
                          <span className="text-xs font-medium text-gray-500">Preview</span>
                        </div>
                        <iframe
                          srcDoc={logDetail.data.body_html}
                          className="w-full h-80 bg-white"
                          title="Email preview"
                          sandbox="allow-same-origin"
                        />
                      </div>
                      <details className="mt-2">
                        <summary className="text-xs font-medium text-gray-500 cursor-pointer hover:text-gray-700">
                          View HTML Source
                        </summary>
                        <pre className="text-xs font-mono bg-gray-50 p-3 rounded overflow-x-auto whitespace-pre-wrap max-h-60 mt-2">
                          {logDetail.data.body_html}
                        </pre>
                      </details>
                    </div>
                  )}

                  {/* No content available */}
                  {!logDetail.data.body_html && !logDetail.data.body_text && !logDetail.data.headers && (
                    <div className="text-center py-8 text-gray-500 bg-gray-50 rounded">
                      <p>Email content not available.</p>
                      <p className="text-xs mt-1">Content is automatically removed after 1 month.</p>
                    </div>
                  )}
                </div>
              ) : (
                <div className="text-center py-8 text-red-500">Failed to load details</div>
              )}
            </div>

            {/* Modal Footer */}
            <div className="border-t px-6 py-4">
              <button
                onClick={() => setSelectedLogId(null)}
                className="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200"
              >
                Close
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
