import { useState, useRef, useEffect } from 'react';
import { Code, Eye } from 'lucide-react';

interface EmailEditorProps {
  content: string;
  onChange: (html: string) => void;
  disabled?: boolean;
}

export default function EmailEditor({ content, onChange, disabled }: EmailEditorProps) {
  const [tab, setTab] = useState<'code' | 'preview'>('code');
  const textareaRef = useRef<HTMLTextAreaElement>(null);

  // Sync external content changes to textarea
  useEffect(() => {
    if (textareaRef.current && textareaRef.current.value !== content) {
      textareaRef.current.value = content;
    }
  }, [content]);

  return (
    <div className="border-t">
      {/* Tabs */}
      <div className="flex border-b bg-gray-50">
        <button
          type="button"
          onClick={() => setTab('code')}
          className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            tab === 'code'
              ? 'border-blue-600 text-blue-600 bg-white'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          <Code size={16} />
          HTML
        </button>
        <button
          type="button"
          onClick={() => setTab('preview')}
          className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
            tab === 'preview'
              ? 'border-blue-600 text-blue-600 bg-white'
              : 'border-transparent text-gray-500 hover:text-gray-700'
          }`}
        >
          <Eye size={16} />
          Preview
        </button>
      </div>

      {/* Code Editor */}
      {tab === 'code' && (
        <textarea
          ref={textareaRef}
          defaultValue={content}
          onChange={(e) => onChange(e.target.value)}
          disabled={disabled}
          spellCheck={false}
          className="w-full h-[500px] p-4 font-mono text-sm leading-relaxed resize-y focus:outline-none disabled:bg-gray-100 border-0"
          style={{ tabSize: 2 }}
        />
      )}

      {/* Preview */}
      {tab === 'preview' && (
        <iframe
          srcDoc={content || '<p style="color:#999;text-align:center;padding:40px;">No content yet</p>'}
          className="w-full h-[500px] border-0"
          title="Email Preview"
          sandbox="allow-same-origin"
        />
      )}

      {/* Personalization Tags */}
      <div className="p-2 border-t bg-gray-50 text-xs text-gray-500">
        <span className="font-medium">Personalization:</span>{' '}
        <code className="bg-gray-200 px-1 rounded">{'{first_name}'}</code>{' '}
        <code className="bg-gray-200 px-1 rounded">{'{last_name}'}</code>{' '}
        <code className="bg-gray-200 px-1 rounded">{'{email}'}</code>{' '}
        <code className="bg-gray-200 px-1 rounded">[unsubscribe_link]</code>
      </div>
    </div>
  );
}
