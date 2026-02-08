import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { Plus, Trash2, Edit, Users, X } from 'lucide-react';
import toast from 'react-hot-toast';
import { api, config } from '../api/client';
import type { SubscriberList } from '../api/types';

export default function Lists() {
  const queryClient = useQueryClient();
  const [showModal, setShowModal] = useState(false);
  const [editingList, setEditingList] = useState<SubscriberList | null>(null);

  const { data, isLoading } = useQuery<{ data: SubscriberList[] }>({
    queryKey: ['lists'],
    queryFn: () => api.get('/lists'),
  });

  const deleteMutation = useMutation({
    mutationFn: (id: number) => api.delete(`/lists/${id}`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      toast.success('List deleted');
    },
    onError: (error: Error) => toast.error(error.message),
  });

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold text-gray-800">Lists</h1>
        <button
          onClick={() => {
            setEditingList(null);
            setShowModal(true);
          }}
          className="flex items-center gap-2 px-4 py-2 text-white bg-blue-600 rounded-lg hover:bg-blue-700"
        >
          <Plus size={18} />
          Create List
        </button>
      </div>

      {/* Lists Table */}
      <div className="bg-white rounded-lg shadow overflow-hidden">
        <table className="w-full">
          <thead className="bg-gray-50">
            <tr>
              <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Name</th>
              <th className="px-4 py-2 text-left text-xs font-semibold text-gray-600 uppercase">Slug</th>
              <th className="px-4 py-2 text-right text-xs font-semibold text-gray-600 uppercase">Subscribers</th>
              <th className="px-4 py-2 text-center text-xs font-semibold text-gray-600 uppercase">Opt-in</th>
              <th className="px-4 py-2 w-20"></th>
            </tr>
          </thead>
          <tbody className="divide-y">
            {isLoading && (
              <tr>
                <td colSpan={5} className="px-4 py-4 text-center text-gray-500">
                  Loading...
                </td>
              </tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr>
                <td colSpan={5} className="px-4 py-4 text-center text-gray-500">
                  No lists yet. Create your first list.
                </td>
              </tr>
            )}
            {data?.data?.map((list) => (
              <tr key={list.id} className="hover:bg-gray-50">
                <td className="px-4 py-2">
                  <a
                    href={`${config.menuUrls.subscribers}&list_id=${list.id}`}
                    className="font-medium text-blue-600 hover:text-blue-800 hover:underline"
                  >
                    {list.name}
                  </a>
                </td>
                <td className="px-4 py-2 text-sm text-gray-500">{list.slug}</td>
                <td className="px-4 py-2 text-right">
                  <span className="inline-flex items-center gap-1 text-gray-700">
                    <Users size={14} />
                    {list.subscriber_count}
                  </span>
                </td>
                <td className="px-4 py-2 text-center">
                  <span
                    className={`px-2 py-0.5 rounded text-xs font-medium ${
                      list.double_optin
                        ? 'bg-green-100 text-green-700'
                        : 'bg-gray-100 text-gray-600'
                    }`}
                  >
                    {list.double_optin ? 'Double' : 'Single'}
                  </span>
                </td>
                <td className="px-4 py-2">
                  <div className="flex items-center justify-end gap-1">
                    <button
                      onClick={() => {
                        setEditingList(list);
                        setShowModal(true);
                      }}
                      className="p-1.5 hover:bg-gray-100 rounded text-gray-600"
                      title="Edit"
                    >
                      <Edit size={14} />
                    </button>
                    <button
                      onClick={() => {
                        if (confirm('Delete this list?')) {
                          deleteMutation.mutate(list.id);
                        }
                      }}
                      className="p-1.5 hover:bg-red-100 text-red-600 rounded"
                      title="Delete"
                    >
                      <Trash2 size={14} />
                    </button>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {/* Modal */}
      {showModal && (
        <ListModal
          list={editingList}
          onClose={() => {
            setShowModal(false);
            setEditingList(null);
          }}
        />
      )}
    </div>
  );
}

interface ListModalProps {
  list: SubscriberList | null;
  onClose: () => void;
}

function ListModal({ list, onClose }: ListModalProps) {
  const queryClient = useQueryClient();
  const [formData, setFormData] = useState({
    name: list?.name || '',
    slug: list?.slug || '',
    description: list?.description || '',
    double_optin: list?.double_optin ?? true,
  });

  const createMutation = useMutation({
    mutationFn: (data: typeof formData) => api.post('/lists', data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      toast.success('List created');
      onClose();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const updateMutation = useMutation({
    mutationFn: (data: typeof formData) => api.put(`/lists/${list?.id}`, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['lists'] });
      toast.success('List updated');
      onClose();
    },
    onError: (error: Error) => toast.error(error.message),
  });

  const handleSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (list) {
      updateMutation.mutate(formData);
    } else {
      createMutation.mutate(formData);
    }
  };

  return (
    <div className="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div className="bg-white rounded-lg w-full max-w-md">
        <div className="flex items-center justify-between p-4 border-b">
          <h2 className="text-lg font-semibold">
            {list ? 'Edit List' : 'Create List'}
          </h2>
          <button onClick={onClose} className="p-1 hover:bg-gray-100 rounded">
            <X size={20} />
          </button>
        </div>
        <form onSubmit={handleSubmit} className="p-4 space-y-4">
          <div>
            <label className="block text-sm font-medium mb-1">Name</label>
            <input
              type="text"
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              required
              className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Slug (optional)</label>
            <input
              type="text"
              value={formData.slug}
              onChange={(e) => setFormData({ ...formData, slug: e.target.value })}
              placeholder="auto-generated-from-name"
              className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="block text-sm font-medium mb-1">Description</label>
            <textarea
              value={formData.description}
              onChange={(e) =>
                setFormData({ ...formData, description: e.target.value })
              }
              rows={3}
              className="w-full px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
          </div>
          <div>
            <label className="flex items-center gap-2">
              <input
                type="checkbox"
                checked={formData.double_optin}
                onChange={(e) =>
                  setFormData({ ...formData, double_optin: e.target.checked })
                }
                className="rounded"
              />
              <span className="text-sm">
                Require double opt-in confirmation
              </span>
            </label>
          </div>
          <div className="flex justify-end gap-2 pt-4">
            <button
              type="button"
              onClick={onClose}
              className="px-4 py-2 border rounded-lg hover:bg-gray-50"
            >
              Cancel
            </button>
            <button
              type="submit"
              disabled={createMutation.isPending || updateMutation.isPending}
              className="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-50"
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
