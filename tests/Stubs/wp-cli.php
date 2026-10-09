<?php
declare(strict_types=1);

namespace {
    if (!class_exists('WP_CLI')) {
        final class WP_CLI
        {
            /** @var list<array{0: string, 1: string}> */
            public static array $log = [];

            public static function reset(): void
            {
                self::$log = [];
            }

            public static function line(string $message = ''): void
            {
                self::$log[] = ['line', $message];
            }

            public static function log(string $message): void
            {
                self::$log[] = ['line', $message];
            }

            public static function success(string $message): void
            {
                self::$log[] = ['success', $message];
            }

            public static function warning(string $message): void
            {
                self::$log[] = ['warning', $message];
            }

            public static function error(string $message): never
            {
                self::$log[] = ['error', $message];
                throw new \RuntimeException('WP_CLI::error: ' . $message);
            }

            /** @param array<string, mixed> $assoc */
            public static function confirm(string $question, array $assoc = []): void
            {
                self::$log[] = ['confirm', $question];
            }

            public static function add_command(string $name, object|string $callable): bool
            {
                self::$log[] = ['command', $name];
                return true;
            }
        }
    }
}

namespace WP_CLI\Utils {
    if (!function_exists(__NAMESPACE__ . '\\format_items')) {
        /**
         * @param array<int, array<string, mixed>> $items
         * @param array<int, string>|string $fields
         */
        function format_items(string $format, array $items, array|string $fields): void
        {
            foreach ($items as $item) {
                \WP_CLI::$log[] = ['row', (string) json_encode($item)];
            }
        }

        /** @param array<string, mixed> $assoc */
        function get_flag_value(array $assoc, string $flag, mixed $default = null): mixed
        {
            return $assoc[$flag] ?? $default;
        }

        function make_progress_bar(string $message, int $count): object
        {
            return new class {
                public function tick(int $increment = 1): void
                {
                }

                public function finish(): void
                {
                }
            };
        }
    }
}
