import { Loader2 } from 'lucide-react';

interface ConfirmModalProps {
  open: boolean;
  onConfirm: () => void;
  onCancel: () => void;
  title: string;
  children: React.ReactNode;
  confirmLabel?: string;
  cancelLabel?: string;
  confirmColor?: 'blue' | 'red' | 'green';
  loading?: boolean;
}

const colorClasses = {
  blue: 'bg-blue-600 hover:bg-blue-700',
  red: 'bg-red-600 hover:bg-red-700',
  green: 'bg-green-600 hover:bg-green-700',
};

export default function ConfirmModal({
  open,
  onConfirm,
  onCancel,
  title,
  children,
  confirmLabel = 'Confirm',
  cancelLabel = 'Cancel',
  confirmColor = 'blue',
  loading = false,
}: ConfirmModalProps) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 bg-black/70 flex items-center justify-center z-50">
      <div className="bg-white rounded-lg shadow-2xl w-full max-w-md mx-4 border border-gray-300">
        <div className="p-4 border-b border-gray-300 bg-gray-100 rounded-t-lg">
          <h3 className="text-lg font-bold text-gray-900">{title}</h3>
        </div>
        <div className="p-4">{children}</div>
        <div className="flex justify-end gap-2 p-4 border-t border-gray-300 bg-gray-50 rounded-b-lg">
          <button
            onClick={onCancel}
            disabled={loading}
            className="px-4 py-2 border-2 border-gray-300 rounded-lg hover:bg-gray-100 font-medium disabled:opacity-50"
          >
            {cancelLabel}
          </button>
          <button
            onClick={onConfirm}
            disabled={loading}
            className={`px-4 py-2 text-white font-medium rounded-lg disabled:opacity-50 flex items-center gap-2 ${colorClasses[confirmColor]}`}
          >
            {loading && <Loader2 size={16} className="animate-spin" />}
            {confirmLabel}
          </button>
        </div>
      </div>
    </div>
  );
}
