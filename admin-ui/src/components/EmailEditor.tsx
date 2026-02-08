import { useRef } from 'react';
import { Editor } from '@tinymce/tinymce-react';

interface EmailEditorProps {
  content: string;
  onChange: (html: string) => void;
  disabled?: boolean;
}

export default function EmailEditor({ content, onChange, disabled }: EmailEditorProps) {
  const editorRef = useRef<unknown>(null);

  return (
    <div className="border-t">
      <Editor
        tinymceScriptSrc="/wp-includes/js/tinymce/tinymce.min.js"
        onInit={(_evt, editor) => {
          editorRef.current = editor;
        }}
        initialValue={content}
        disabled={disabled}
        onEditorChange={(newContent) => {
          onChange(newContent);
        }}
        init={{
          height: 500,
          menubar: true,
          plugins: [
            'advlist', 'autolink', 'lists', 'link', 'image', 'charmap', 'preview',
            'anchor', 'searchreplace', 'visualblocks', 'code', 'fullscreen',
            'insertdatetime', 'media', 'table', 'help', 'wordcount', 'emoticons',
            'codesample', 'directionality', 'visualchars', 'nonbreaking', 'pagebreak',
            'quickbars'
          ],
          toolbar1: 'undo redo | blocks fontfamily fontsize | bold italic underline strikethrough | forecolor backcolor | alignleft aligncenter alignright alignjustify',
          toolbar2: 'bullist numlist outdent indent | link image media table | charmap emoticons | removeformat | code fullscreen | help',
          toolbar_mode: 'wrap',
          branding: false,
          promotion: false,
          content_style: `
            body {
              font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
              font-size: 14px;
              line-height: 1.6;
              padding: 10px;
            }
            img { max-width: 100%; height: auto; }
          `,
          convert_urls: false,
          relative_urls: false,
          entity_encoding: 'raw',
          paste_data_images: true,
          image_advtab: true,
          image_caption: true,
          quickbars_selection_toolbar: 'bold italic | link h2 h3 blockquote',
          quickbars_insert_toolbar: 'quickimage quicktable',
          contextmenu: 'link image table',
          font_family_formats: 'Arial=arial,helvetica,sans-serif;Courier New=courier new,courier,monospace;Georgia=georgia,palatino;Helvetica=helvetica;Times New Roman=times new roman,times;Verdana=verdana,geneva;',
          font_size_formats: '8px 10px 12px 14px 16px 18px 20px 24px 28px 32px 36px 48px 72px',
          block_formats: 'Paragraph=p; Heading 1=h1; Heading 2=h2; Heading 3=h3; Heading 4=h4; Preformatted=pre',
          style_formats: [
            { title: 'Headings', items: [
              { title: 'Heading 1', format: 'h1' },
              { title: 'Heading 2', format: 'h2' },
              { title: 'Heading 3', format: 'h3' },
            ]},
            { title: 'Inline', items: [
              { title: 'Bold', format: 'bold' },
              { title: 'Italic', format: 'italic' },
              { title: 'Underline', format: 'underline' },
              { title: 'Strikethrough', format: 'strikethrough' },
              { title: 'Code', format: 'code' },
            ]},
            { title: 'Blocks', items: [
              { title: 'Paragraph', format: 'p' },
              { title: 'Blockquote', format: 'blockquote' },
              { title: 'Pre', format: 'pre' },
            ]},
          ],
        }}
      />

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
