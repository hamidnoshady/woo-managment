<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-db-dumper.php

defined('ABSPATH') || exit;

/**
 * Dumps the live WP database to a local .sql file, BATCH_ROWS rows per
 * tick, resuming from cursor_json's {table_index, offset}. Pure PHP — no
 * mysqldump, since shared hosts often disable shell_exec.
 */
class Wma_Db_Dumper
{
    private const BATCH_ROWS = 200;

    public static function dump_step(array &$job): bool
    {
        global $wpdb;

        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['table_index' => 0, 'offset' => 0];
        $dumpPath = $job['temp_dir'];
        if ($dumpPath === '' || !is_file($dumpPath)) {
            $dumpPath = self::start_dump_file($job['job_id']);
            $job['temp_dir'] = $dumpPath;
        }

        $tables = $wpdb->get_col('SHOW TABLES');
        if ($cursor['table_index'] >= count($tables)) {
            return true;
        }

        $table = $tables[$cursor['table_index']];
        $fh = fopen($dumpPath, 'a');
        if ($fh === false) {
            throw new RuntimeException('Could not open the database dump file for writing.');
        }

        if ($cursor['offset'] === 0) {
            $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A);
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow['Create Table'] . ";\n\n");
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::BATCH_ROWS, $cursor['offset']),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $columns = array_map(fn($c) => "`{$c}`", array_keys($row));
            $values = array_map(fn($v) => $v === null ? 'NULL' : "'" . esc_sql((string) $v) . "'", array_values($row));
            fwrite($fh, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
        fclose($fh);

        if (count($rows) < self::BATCH_ROWS) {
            $cursor = ['table_index' => $cursor['table_index'] + 1, 'offset' => 0];
        } else {
            $cursor['offset'] += self::BATCH_ROWS;
        }
        $job['cursor_json'] = wp_json_encode($cursor);

        return $cursor['table_index'] >= count($tables);
    }

    private static function start_dump_file(int $job_id): string
    {
        $dir = trailingslashit(get_temp_dir()) . 'wma-' . $job_id;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $path = $dir . '/database.sql';
        file_put_contents($path, "-- Site backup generated " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        return $path;
    }
}
