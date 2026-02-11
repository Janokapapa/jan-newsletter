<?php

namespace JanNewsletter\Mail;

use JanNewsletter\Plugin;

/**
 * SMTP Transport for sending emails
 */
class SmtpTransport {
    private string $host;
    private int $port;
    private string $encryption; // tls, ssl, none
    private bool $auth;
    private string $username;
    private string $password;

    private $socket = null;
    private string $last_error = '';
    private string $last_response = '';

    public function __construct() {
        $this->host = Plugin::get_option('smtp_host', '');
        $this->port = (int) Plugin::get_option('smtp_port', 587);
        $this->encryption = Plugin::get_option('smtp_encryption', 'tls');
        $this->auth = (bool) Plugin::get_option('smtp_auth', true);
        $this->username = Plugin::get_option('smtp_username', '');
        $this->password = Plugin::get_option('smtp_password', '');
    }

    /**
     * Send email via SMTP
     */
    public function send(
        string $to,
        string $subject,
        string $body_html,
        ?string $body_text = null,
        string $from_email = '',
        string $from_name = '',
        array $headers = [],
        array $attachments = []
    ): bool {
        if (empty($this->host)) {
            $this->last_error = __('SMTP host not configured', 'jan-newsletter');
            return false;
        }

        // Use default from if not provided
        if (empty($from_email)) {
            $from_email = Plugin::get_option('from_email', get_option('admin_email'));
        }
        if (empty($from_name)) {
            $from_name = Plugin::get_option('from_name', get_bloginfo('name'));
        }

        // Generate plain text if not provided
        if (empty($body_text)) {
            $body_text = $this->html_to_text($body_html);
        }

        // Generate HTML if not provided (e.g. plain text WordPress emails)
        if (empty($body_html) && !empty($body_text)) {
            $body_html = nl2br(htmlspecialchars($body_text, ENT_QUOTES, 'UTF-8'));
        }

        try {
            // Connect
            if (!$this->connect()) {
                return false;
            }

            // EHLO/HELO
            if (!$this->ehlo()) {
                return false;
            }

            // STARTTLS if needed
            if ($this->encryption === 'tls') {
                if (!$this->starttls()) {
                    return false;
                }
                // Re-EHLO after STARTTLS
                if (!$this->ehlo()) {
                    return false;
                }
            }

            // Authenticate
            if ($this->auth) {
                if (!$this->authenticate()) {
                    return false;
                }
            }

            // Mail From
            if (!$this->mail_from($from_email)) {
                return false;
            }

            // RCPT TO (handle multiple recipients)
            $recipients = is_array($to) ? $to : explode(',', $to);
            foreach ($recipients as $recipient) {
                $recipient = trim($recipient);
                if (!empty($recipient) && !$this->rcpt_to($recipient)) {
                    return false;
                }
            }

            // Data
            $message = $this->build_message(
                $recipients,
                $subject,
                $body_html,
                $body_text,
                $from_email,
                $from_name,
                $headers,
                $attachments
            );

            if (!$this->data($message)) {
                return false;
            }

            // Quit
            $this->quit();

            return true;

        } catch (\Exception $e) {
            $this->last_error = $e->getMessage();
            return false;
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Test SMTP connection
     */
    public function test(): array {
        try {
            if (!$this->connect()) {
                return ['success' => false, 'message' => $this->last_error];
            }

            if (!$this->ehlo()) {
                return ['success' => false, 'message' => $this->last_error];
            }

            if ($this->encryption === 'tls') {
                if (!$this->starttls()) {
                    return ['success' => false, 'message' => $this->last_error];
                }
                if (!$this->ehlo()) {
                    return ['success' => false, 'message' => $this->last_error];
                }
            }

            if ($this->auth) {
                if (!$this->authenticate()) {
                    return ['success' => false, 'message' => $this->last_error];
                }
            }

            $this->quit();
            $this->disconnect();

            return ['success' => true, 'message' => __('SMTP connection successful', 'jan-newsletter')];

        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Connect to SMTP server
     */
    private function connect(): bool {
        $host = $this->host;

        // SSL prefix
        if ($this->encryption === 'ssl') {
            $host = 'ssl://' . $host;
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $this->socket = @stream_socket_client(
            $host . ':' . $this->port,
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$this->socket) {
            $this->last_error = sprintf(
                __('Could not connect to SMTP server: %s (%d)', 'jan-newsletter'),
                $errstr,
                $errno
            );
            return false;
        }

        // Read greeting
        $response = $this->read();
        if (!$this->is_success($response)) {
            $this->last_error = __('SMTP server did not send greeting', 'jan-newsletter');
            return false;
        }

        return true;
    }

    /**
     * Disconnect from SMTP server
     */
    private function disconnect(): void {
        if ($this->socket) {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    /**
     * Send EHLO/HELO command
     */
    private function ehlo(): bool {
        $hostname = gethostname() ?: 'localhost';

        $this->write("EHLO {$hostname}");
        $response = $this->read();

        if (!$this->is_success($response)) {
            // Try HELO as fallback
            $this->write("HELO {$hostname}");
            $response = $this->read();

            if (!$this->is_success($response)) {
                $this->last_error = __('EHLO/HELO failed', 'jan-newsletter');
                return false;
            }
        }

        return true;
    }

    /**
     * Start TLS encryption
     */
    private function starttls(): bool {
        $this->write('STARTTLS');
        $response = $this->read();

        if (!$this->is_success($response)) {
            $this->last_error = __('STARTTLS failed', 'jan-newsletter');
            return false;
        }

        $crypto = stream_socket_enable_crypto(
            $this->socket,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT
        );

        if (!$crypto) {
            $this->last_error = __('TLS encryption failed', 'jan-newsletter');
            return false;
        }

        return true;
    }

    /**
     * Authenticate with SMTP server
     */
    private function authenticate(): bool {
        // AUTH LOGIN
        $this->write('AUTH LOGIN');
        $response = $this->read();

        if (strpos($response, '334') !== 0) {
            $this->last_error = __('AUTH LOGIN not supported', 'jan-newsletter');
            return false;
        }

        // Username
        $this->write(base64_encode($this->username));
        $response = $this->read();

        if (strpos($response, '334') !== 0) {
            $this->last_error = __('Authentication failed (username)', 'jan-newsletter');
            return false;
        }

        // Password
        $this->write(base64_encode($this->password));
        $response = $this->read();

        if (!$this->is_success($response)) {
            $this->last_error = __('Authentication failed (password)', 'jan-newsletter');
            return false;
        }

        return true;
    }

    /**
     * Send MAIL FROM command
     */
    private function mail_from(string $email): bool {
        $this->write("MAIL FROM:<{$email}>");
        $response = $this->read();

        if (!$this->is_success($response)) {
            $this->last_error = __('MAIL FROM failed', 'jan-newsletter');
            return false;
        }

        return true;
    }

    /**
     * Send RCPT TO command
     */
    private function rcpt_to(string $email): bool {
        // Extract email if formatted as "Name <email>"
        if (preg_match('/<(.+)>/', $email, $matches)) {
            $email = $matches[1];
        }

        $this->write("RCPT TO:<{$email}>");
        $response = $this->read();

        if (!$this->is_success($response)) {
            $this->last_error = sprintf(__('RCPT TO failed for %s', 'jan-newsletter'), $email);
            return false;
        }

        return true;
    }

    /**
     * Send DATA command and message
     */
    private function data(string $message): bool {
        $this->write('DATA');
        $response = $this->read();

        if (strpos($response, '354') !== 0) {
            $this->last_error = __('DATA command failed', 'jan-newsletter');
            return false;
        }

        // Send message (with dot stuffing)
        $message = str_replace("\r\n.", "\r\n..", $message);
        $this->write($message . "\r\n.");
        $response = $this->read();

        if (!$this->is_success($response)) {
            $this->last_error = __('Message sending failed', 'jan-newsletter');
            return false;
        }

        $this->last_response = $response;
        return true;
    }

    /**
     * Send QUIT command
     */
    private function quit(): void {
        $this->write('QUIT');
        $this->read();
    }

    /**
     * Build email message
     */
    private function build_message(
        array $to,
        string $subject,
        string $body_html,
        string $body_text,
        string $from_email,
        string $from_name,
        array $extra_headers = [],
        array $attachments = []
    ): string {
        $boundary = 'boundary_' . md5(uniqid(time()));
        $alt_boundary = 'alt_boundary_' . md5(uniqid(time() + 1));

        $headers = [];
        $headers[] = 'Date: ' . date('r');
        $headers[] = 'From: ' . $this->format_address($from_email, $from_name);
        $headers[] = 'To: ' . implode(', ', $to);
        $headers[] = 'Subject: ' . $this->encode_header($subject);
        $headers[] = 'MIME-Version: 1.0';
        $headers[] = 'Message-ID: <' . uniqid() . '@' . parse_url(home_url(), PHP_URL_HOST) . '>';

        // Add extra headers (but skip ones we already set)
        $skip_headers = ['from', 'to', 'subject', 'mime-version', 'content-type', 'date', 'message-id'];
        foreach ($extra_headers as $name => $value) {
            if (!in_array(strtolower($name), $skip_headers)) {
                $headers[] = $name . ': ' . $value;
            }
        }

        $has_attachments = !empty($attachments);

        if ($has_attachments) {
            $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        } else {
            $headers[] = 'Content-Type: multipart/alternative; boundary="' . $alt_boundary . '"';
        }

        $message = implode("\r\n", $headers) . "\r\n\r\n";

        if ($has_attachments) {
            // Start mixed
            $message .= '--' . $boundary . "\r\n";
            $message .= 'Content-Type: multipart/alternative; boundary="' . $alt_boundary . '"' . "\r\n\r\n";
        }

        // Plain text part
        $message .= '--' . $alt_boundary . "\r\n";
        $message .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $message .= $this->quoted_printable_encode($body_text) . "\r\n\r\n";

        // HTML part
        $message .= '--' . $alt_boundary . "\r\n";
        $message .= 'Content-Type: text/html; charset=UTF-8' . "\r\n";
        $message .= 'Content-Transfer-Encoding: quoted-printable' . "\r\n\r\n";
        $message .= $this->quoted_printable_encode($body_html) . "\r\n\r\n";

        $message .= '--' . $alt_boundary . '--' . "\r\n";

        // Attachments
        if ($has_attachments) {
            foreach ($attachments as $attachment) {
                if (is_string($attachment) && file_exists($attachment)) {
                    $filename = basename($attachment);
                    $content = file_get_contents($attachment);
                    $mime_type = mime_content_type($attachment) ?: 'application/octet-stream';

                    $message .= "\r\n" . '--' . $boundary . "\r\n";
                    $message .= 'Content-Type: ' . $mime_type . '; name="' . $filename . '"' . "\r\n";
                    $message .= 'Content-Disposition: attachment; filename="' . $filename . '"' . "\r\n";
                    $message .= 'Content-Transfer-Encoding: base64' . "\r\n\r\n";
                    $message .= chunk_split(base64_encode($content)) . "\r\n";
                }
            }
            $message .= '--' . $boundary . '--' . "\r\n";
        }

        return $message;
    }

    /**
     * Format email address
     */
    private function format_address(string $email, string $name = ''): string {
        if (empty($name)) {
            return $email;
        }
        return $this->encode_header($name) . ' <' . $email . '>';
    }

    /**
     * Encode header for non-ASCII characters
     */
    private function encode_header(string $text): string {
        if (!preg_match('/[^\x20-\x7E]/', $text)) {
            return $text;
        }
        return '=?UTF-8?B?' . base64_encode($text) . '?=';
    }

    /**
     * Quoted-printable encoding
     */
    private function quoted_printable_encode(string $text): string {
        return quoted_printable_encode($text);
    }

    /**
     * Convert HTML to plain text
     */
    private function html_to_text(string $html): string {
        $text = $html;

        // Convert links
        $text = preg_replace('/<a[^>]+href="([^"]*)"[^>]*>([^<]*)<\/a>/i', '$2 ($1)', $text);

        // Convert line breaks
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = preg_replace('/<\/p>/i', "\n\n", $text);
        $text = preg_replace('/<\/div>/i', "\n", $text);
        $text = preg_replace('/<\/h[1-6]>/i', "\n\n", $text);

        // Remove remaining HTML tags
        $text = strip_tags($text);

        // Decode HTML entities
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');

        // Clean up whitespace
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    /**
     * Write to socket
     */
    private function write(string $data): void {
        fwrite($this->socket, $data . "\r\n");
    }

    /**
     * Read from socket
     */
    private function read(): string {
        $response = '';
        while ($line = fgets($this->socket, 515)) {
            $response .= $line;
            // Check if this is the last line (code followed by space)
            if (preg_match('/^\d{3} /', $line)) {
                break;
            }
        }
        return trim($response);
    }

    /**
     * Check if response is success (2xx or 3xx)
     */
    private function is_success(string $response): bool {
        return preg_match('/^[23]\d{2}/', $response);
    }

    /**
     * Get last error
     */
    public function get_last_error(): string {
        return $this->last_error;
    }

    /**
     * Get last SMTP response
     */
    public function get_last_response(): string {
        return $this->last_response;
    }
}
