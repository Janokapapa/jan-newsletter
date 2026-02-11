import { useState, useRef, useEffect, useCallback } from 'react';
import { Code, Eye } from 'lucide-react';

declare global {
  interface Window {
    tinymce: {
      init: (config: Record<string, unknown>) => void;
      get: (id: string) => TinyMCEEditor | null;
      remove: (selector: string) => void;
    };
  }
}

interface TinyMCEEditor {
  getContent: () => string;
  setContent: (content: string) => void;
  on: (event: string, callback: () => void) => void;
  destroy: () => void;
}

interface EmailEditorProps {
  content: string;
  onChange: (html: string) => void;
  disabled?: boolean;
}

const EDITOR_ID = 'jan-nl-email-editor';

export default function EmailEditor({ content, onChange, disabled }: EmailEditorProps) {
  const [tab, setTab] = useState<'visual' | 'code' | 'preview'>(disabled ? 'code' : 'visual');
  const textareaRef = useRef<HTMLTextAreaElement>(null);
  const editorInitialized = useRef(false);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  // Initialize TinyMCE on the textarea
  const initTinyMCE = useCallback(() => {
    if (!window.tinymce || editorInitialized.current) return;

    // Remove existing instance
    const existing = window.tinymce.get(EDITOR_ID);
    if (existing) {
      existing.destroy();
      window.tinymce.remove('#' + EDITOR_ID);
    }

    window.tinymce.init({
      selector: '#' + EDITOR_ID,
      height: 500,
      menubar: true,
      plugins: 'lists link image charmap fullscreen media paste textcolor colorpicker code wordpress wplink',
      toolbar1: 'undo redo | formatselect | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify',
      toolbar2: 'bullist numlist outdent indent | link image media | charmap | removeformat | fullscreen | code',
      toolbar_mode: 'wrap',
      branding: false,
      promotion: false,
      convert_urls: false,
      relative_urls: false,
      entity_encoding: 'raw',
      paste_data_images: true,
      content_style: `
        body {
          font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
          font-size: 14px;
          line-height: 1.6;
          padding: 10px;
        }
        img { max-width: 100%; height: auto; }
      `,
      init_instance_callback: (editor: TinyMCEEditor) => {
        editorInitialized.current = true;
        editor.on('change', () => {
          onChangeRef.current(editor.getContent());
        });
        editor.on('keyup', () => {
          onChangeRef.current(editor.getContent());
        });
      },
    });
  }, []);

  // Initialize TinyMCE when visual tab is active
  useEffect(() => {
    if (tab === 'visual' && !disabled) {
      // Small delay to ensure textarea is in DOM
      const timer = setTimeout(initTinyMCE, 100);
      return () => clearTimeout(timer);
    }

    // Cleanup when leaving visual tab
    if (tab !== 'visual' && editorInitialized.current) {
      const editor = window.tinymce?.get(EDITOR_ID);
      if (editor) {
        editor.destroy();
        window.tinymce.remove('#' + EDITOR_ID);
      }
      editorInitialized.current = false;
    }
  }, [tab, disabled, initTinyMCE]);

  // Cleanup on unmount
  useEffect(() => {
    return () => {
      if (editorInitialized.current) {
        const editor = window.tinymce?.get(EDITOR_ID);
        if (editor) {
          editor.destroy();
          window.tinymce?.remove('#' + EDITOR_ID);
        }
        editorInitialized.current = false;
      }
    };
  }, []);

  // Sync content to code textarea
  useEffect(() => {
    if (tab === 'code' && textareaRef.current && textareaRef.current.value !== content) {
      textareaRef.current.value = content;
    }
  }, [content, tab]);

  const tabs = disabled
    ? [
        { id: 'code' as const, label: 'HTML', icon: Code },
        { id: 'preview' as const, label: 'Preview', icon: Eye },
      ]
    : [
        { id: 'visual' as const, label: 'Visual', icon: Eye },
        { id: 'code' as const, label: 'HTML', icon: Code },
        { id: 'preview' as const, label: 'Preview', icon: Eye },
      ];

  return (
    <div className="border-t">
      {/* Tabs */}
      <div className="flex border-b bg-gray-50">
        {tabs.map((t) => (
          <button
            key={t.id}
            type="button"
            onClick={() => setTab(t.id)}
            className={`flex items-center gap-1.5 px-4 py-2 text-sm font-medium border-b-2 transition-colors ${
              tab === t.id
                ? 'border-blue-600 text-blue-600 bg-white'
                : 'border-transparent text-gray-500 hover:text-gray-700'
            }`}
          >
            <t.icon size={16} />
            {t.label}
          </button>
        ))}
      </div>

      {/* Visual Editor (TinyMCE) */}
      {tab === 'visual' && !disabled && (
        <div>
          <textarea
            id={EDITOR_ID}
            defaultValue={content}
            style={{ visibility: 'hidden', height: 0 }}
          />
        </div>
      )}

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
