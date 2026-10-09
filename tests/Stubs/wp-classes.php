<?php
declare(strict_types=1);

// Minimal runtime stand-ins for WordPress classes used by unit tests.
// PHPStan uses the real signatures from php-stubs/wordpress-stubs instead.

if (!class_exists('WP_Post')) {
    #[\AllowDynamicProperties]
    final class WP_Post
    {
        public int $ID = 0;
        public string $post_title = '';
        public string $post_content = '';
        public string $post_excerpt = '';
        public string $post_type = 'post';
        public string $post_status = 'publish';
        public string $post_password = '';
        public string $post_date = '2026-01-01 00:00:00';
        public string $post_author = '1';

        /** @param array<string, mixed> $props */
        public function __construct(array $props = [])
        {
            foreach ($props as $key => $value) {
                $this->{$key} = $value;
            }
        }
    }
}

if (!class_exists('WP_Query')) {
    #[\AllowDynamicProperties]
    class WP_Query
    {
        /** @var array<string, mixed> */
        public array $query_vars = [];
        /** @var array<int, mixed> */
        public array $posts = [];
        public int $found_posts = 0;
        public int $max_num_pages = 0;
        public bool $is_search = false;
        public bool $isMain = true;

        /** @param array<string, mixed> $vars */
        public function __construct(array $vars = [])
        {
            $this->query_vars = $vars;
        }

        public function get(string $key, mixed $default = ''): mixed
        {
            return $this->query_vars[$key] ?? $default;
        }

        public function set(string $key, mixed $value): void
        {
            $this->query_vars[$key] = $value;
        }

        public function is_search(): bool
        {
            return $this->is_search;
        }

        public function is_main_query(): bool
        {
            return $this->isMain;
        }
    }
}

if (!class_exists('WP_Widget')) {
    class WP_Widget
    {
        /** @param array<string, mixed> $options */
        public function __construct(public string $id_base = '', public string $name = '', array $options = [])
        {
        }

        public function get_field_id(string $field): string
        {
            return $this->id_base . '-' . $field;
        }

        public function get_field_name(string $field): string
        {
            return 'widget-' . $this->id_base . '[' . $field . ']';
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
        /** @param array<string, mixed> $params */
        public function __construct(private array $params = [])
        {
        }

        public function get_param(string $key): mixed
        {
            return $this->params[$key] ?? null;
        }

        /** @return array<string, mixed> */
        public function get_params(): array
        {
            return $this->params;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response
    {
        /** @var array<string, string> */
        private array $headers = [];

        /** @param array<string, string> $headers */
        public function __construct(private mixed $data = null, private int $status = 200, array $headers = [])
        {
            $this->headers = $headers;
        }

        public function get_data(): mixed
        {
            return $this->data;
        }

        public function get_status(): int
        {
            return $this->status;
        }

        public function header(string $key, string $value): void
        {
            $this->headers[$key] = $value;
        }

        /** @return array<string, string> */
        public function get_headers(): array
        {
            return $this->headers;
        }
    }
}

if (!class_exists('WP_Error')) {
    class WP_Error
    {
        public function __construct(private string $code = '', private string $message = '', private mixed $data = '')
        {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }

        public function get_error_data(): mixed
        {
            return $this->data;
        }
    }
}
