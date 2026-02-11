import { useEffect, useRef, useCallback } from 'react';

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

interface SettingsTinyMCEProps {
  id: string;
  content: string;
  onChange: (html: string) => void;
}

export default function SettingsTinyMCE({ id, content, onChange }: SettingsTinyMCEProps) {
  const editorInitialized = useRef(false);
  const onChangeRef = useRef(onChange);
  onChangeRef.current = onChange;

  const initEditor = useCallback(() => {
    if (!window.tinymce || editorInitialized.current) return;

    const existing = window.tinymce.get(id);
    if (existing) {
      existing.destroy();
      window.tinymce.remove('#' + id);
    }

    window.tinymce.init({
      selector: '#' + id,
      height: 300,
      menubar: false,
      plugins: 'lists link image charmap paste textcolor colorpicker',
      toolbar: 'undo redo | formatselect | bold italic underline | forecolor backcolor | alignleft aligncenter alignright | link image',
      toolbar_mode: 'wrap',
      branding: false,
      promotion: false,
      convert_urls: false,
      relative_urls: false,
      entity_encoding: 'raw',
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
  }, [id]);

  useEffect(() => {
    const timer = setTimeout(initEditor, 100);
    return () => clearTimeout(timer);
  }, [initEditor]);

  useEffect(() => {
    return () => {
      if (editorInitialized.current) {
        const editor = window.tinymce?.get(id);
        if (editor) {
          editor.destroy();
          window.tinymce?.remove('#' + id);
        }
        editorInitialized.current = false;
      }
    };
  }, [id]);

  return (
    <textarea
      id={id}
      defaultValue={content}
      style={{ visibility: 'hidden', height: 0 }}
    />
  );
}
