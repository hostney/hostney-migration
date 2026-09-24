<?php
/**
 * Hostney Migration - Database Export
 *
 * Exports database tables row-by-row using $wpdb.
 * Uses primary key pagination for efficient large-table export.
 * Compatible with shared hosting (no mysqldump required).
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Hostney_DB_Export {

    /**
     * Longest paging cursor accepted back from the worker, in characters.
     */
    const MAX_CURSOR_LENGTH = 65536;

    /**
     * Version of the cursor's inner format (see encode_cursor()).
     */
    const CURSOR_VERSION = 1;

    /**
     * Unique keys per table, read once per request (see get_unique_keys()).
     *
     * @var array
     */
    private $unique_keys = array();

    /**
     * Get list of all tables with metadata
     *
     * @return array Table list with name, rows, size, engine, primary_key, create_statement
     */
    public function get_tables() {
        global $wpdb;

        $tables = array();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
        $results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

        if ( empty( $results ) ) {
            return $tables;
        }

        foreach ( $results as $row ) {
            $table_name = $row['Name'];

            // Get primary key column
            $primary_key = $this->get_primary_key( $table_name );

            // Get CREATE TABLE statement
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- reading schema for export, not modifying it
            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder -- %1s is intentional for identifier (table name)
            $create_result = $wpdb->get_row(
                $wpdb->prepare( 'SHOW CREATE TABLE `%1s`', $table_name ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
            $create_statement = isset( $create_result['Create Table'] ) ? $create_result['Create Table'] : null;

            $tables[] = array(
                'name'             => $table_name,
                'rows'             => intval( $row['Rows'] ),
                'size'             => intval( $row['Data_length'] ) + intval( $row['Index_length'] ),
                'engine'           => $row['Engine'],
                'primary_key'      => $primary_key,
                'create_statement' => $create_statement,
            );
        }

        return $tables;
    }

    /**
     * Get rows from a table
     *
     * Two ways to page, chosen by the caller:
     *
     * - $cursor is a string (Hostney workers from September 2026): rows come in
     *   the order of the table's key - its primary key, or else its first unique
     *   key whose columns are all NOT NULL - and each page starts strictly after
     *   the last row of the previous one, compared on EVERY column of that key.
     *   Rows added or deleted while the export runs cannot shift a page, and
     *   rows that share a value in one key column are never split between two
     *   pages. '' asks for the first page; each response carries next_cursor.
     * - $cursor is null (older workers): paging on last_id exactly as before,
     *   because the worker that sent the request depends on it.
     *
     * A table without a usable key is paged the original way either way. The
     * response says which way was used in 'paging': 'key', 'id' or 'offset'.
     *
     * @param string      $table   Table name
     * @param int         $last_id Last primary key value, or row offset (original paging)
     * @param int         $limit   Number of rows to fetch
     * @param string|null $cursor  Key paging: '' for the first page, then next_cursor
     * @return array|WP_Error
     */
    public function get_rows( $table, $last_id = 0, $limit = 1000, $cursor = null ) {
        global $wpdb;

        // phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- required for large table exports on shared hosting with short max_execution_time
        @set_time_limit( 120 );

        // Defense in depth: reject table names with unexpected characters
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            return new WP_Error(
                'invalid_table',
                __( 'Invalid table name.', 'hostney-migration' ),
                array( 'status' => 400 )
            );
        }

        // Validate table name against actual tables to prevent SQL injection
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- required for table validation each request
        $valid_tables = $wpdb->get_col( 'SHOW TABLES' );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        if ( ! in_array( $table, $valid_tables, true ) ) {
            return new WP_Error(
                'invalid_table',
                __( 'Table not found.', 'hostney-migration' ),
                array( 'status' => 400 )
            );
        }

        // Get column names
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
        // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder -- %1s is intentional for identifier (table name)
        $columns_result = $wpdb->get_results(
            $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $table ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
        $columns = wp_list_pluck( $columns_result, 'Field' );

        // Identify binary/blob columns for base64 encoding
        $binary_columns = array();
        foreach ( $columns_result as $col ) {
            $type = strtolower( $col['Type'] );
            if ( strpos( $type, 'blob' ) !== false || strpos( $type, 'binary' ) !== false ) {
                $binary_columns[] = $col['Field'];
            }
        }

        if ( null !== $cursor ) {
            $key = $this->get_paging_key( $table, $columns_result );
            if ( null !== $key ) {
                return $this->get_rows_after( $table, $key, $cursor, $limit, $columns, $binary_columns );
            }
        }

        $primary_key = $this->get_primary_key( $table, $columns_result );

        // Fetch rows using primary key pagination.
        //
        // Table and column names are interpolated into the SQL string because
        // $wpdb->prepare() does not support identifier placeholders. Both values
        // are validated before reaching this point:
        //   1. $table passes a strict regex check (/^[a-zA-Z0-9_]+$/) above
        //   2. $table is verified against the SHOW TABLES allowlist above
        //   3. $primary_key comes from SHOW COLUMNS output for the validated table
        //
        // All user-supplied values (last_id, limit) use %d placeholders via prepare().
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table and $primary_key validated above (regex + allowlist)
        if ( $primary_key && $last_id > 0 ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$primary_key}` > %d ORDER BY `{$primary_key}` ASC LIMIT %d",
                    $last_id,
                    $limit
                ),
                ARRAY_A
            );
        } elseif ( $primary_key ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` ORDER BY `{$primary_key}` ASC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        } else {
            // No numeric primary key - use LIMIT/OFFSET (less efficient but necessary).
            // Ordered by the primary key when the table has one of any type, so every
            // request reads the rows in the same order: without ORDER BY, SQL promises
            // none. A table with no primary key at all is read in storage order.
            $order_by = $this->primary_key_order_by( $table );
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}`{$order_by} LIMIT %d OFFSET %d",
                    $limit,
                    $last_id
                ),
                ARRAY_A
            );
        }
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $wpdb->last_error ) {
            return $this->query_failed( $table );
        }

        $rows = $this->encode_binary_columns( $rows, $binary_columns );

        // Determine last_id for next page
        $new_last_id = $last_id;
        if ( ! empty( $rows ) ) {
            $last_row = end( $rows );
            if ( $primary_key && isset( $last_row[ $primary_key ] ) ) {
                $new_last_id = intval( $last_row[ $primary_key ] );
            } else {
                // For tables without primary key, use offset-based pagination
                $new_last_id = $last_id + count( $rows );
            }
        }

        $has_more = count( $rows ) >= $limit;

        return array(
            'success'  => true,
            'table'    => $table,
            'columns'  => $columns,
            'rows'     => $rows,
            'last_id'  => $new_last_id,
            'has_more' => $has_more,
            'count'    => count( $rows ),
            'paging'   => $primary_key ? 'id' : 'offset',
        );
    }

    /**
     * One page in key order, starting strictly after $cursor (see get_rows()).
     *
     * @param string $table          Validated table name
     * @param array  $key            get_paging_key() result
     * @param string $cursor         '' for the first page, else a next_cursor
     * @param int    $limit          Number of rows to fetch
     * @param array  $columns        Column names, for the response
     * @param array  $binary_columns Columns to base64-encode for transport
     * @return array|WP_Error
     */
    private function get_rows_after( $table, $key, $cursor, $limit, $columns, $binary_columns ) {
        global $wpdb;

        $after = array();
        if ( '' !== $cursor ) {
            $after = $this->decode_cursor( $cursor, $table, $key );
            if ( is_wp_error( $after ) ) {
                return $after;
            }
        }

        $args  = array();
        $where = empty( $after ) ? '' : ' WHERE ' . $this->after_clause( $key, $after, $args );
        $order = array();
        foreach ( $key as $part ) {
            $order[] = '`' . $part['column'] . '`';
        }
        $args[] = $limit;

        // Identifiers are interpolated: $table passed the regex and the SHOW TABLES
        // allowlist in get_rows(), and every key column came from SHOW INDEX for that
        // table and passed the same character rule (get_paging_key()). Every value is
        // a %s placeholder, or a literal after_clause() built from digits or hex only.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- identifiers validated above; values are placeholders or validated literals
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM `{$table}`{$where} ORDER BY " . implode( ', ', $order ) . ' LIMIT %d',
                $args
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( $wpdb->last_error ) {
            return $this->query_failed( $table );
        }

        // Taken from the last row BEFORE binary values are base64-encoded for
        // transport: the cursor must carry the bytes the table holds.
        $next_cursor = '';
        if ( ! empty( $rows ) ) {
            $next_cursor = $this->encode_cursor( $table, $key, end( $rows ) );
            if ( is_wp_error( $next_cursor ) ) {
                return $next_cursor;
            }
        }

        $rows = $this->encode_binary_columns( $rows, $binary_columns );

        return array(
            'success'     => true,
            'table'       => $table,
            'columns'     => $columns,
            'rows'        => $rows,
            'last_id'     => 0,
            'has_more'    => count( $rows ) >= $limit,
            'count'       => count( $rows ),
            'paging'      => 'key',
            'next_cursor' => $next_cursor,
        );
    }

    /**
     * WHERE condition for "strictly after this key", column by column:
     *   (k1 > v1) OR (k1 = v1 AND k2 > v2) OR (k1 = v1 AND k2 = v2 AND k3 > v3) ...
     *
     * Written out, rather than as the row comparison (k1, k2) > (v1, v2), because
     * some MySQL and MariaDB versions cannot answer the row form from the index
     * and would scan the whole table for every page.
     *
     * @param array $key   get_paging_key() result
     * @param array $after Decoded cursor values, one per key column
     * @param array $args  prepare() arguments, appended to in order
     * @return string
     */
    private function after_clause( $key, $after, &$args ) {
        $any   = array();
        $count = count( $key );

        for ( $i = 0; $i < $count; $i++ ) {
            $all = array();
            for ( $j = 0; $j <= $i; $j++ ) {
                $operator = ( $j === $i ) ? '>' : '=';
                $all[]    = '`' . $key[ $j ]['column'] . '` ' . $operator . ' ' . $this->key_literal( $key[ $j ]['kind'], $after[ $j ], $args );
            }
            $any[] = '(' . implode( ' AND ', $all ) . ')';
        }

        return '(' . implode( ' OR ', $any ) . ')';
    }

    /**
     * One key value as SQL.
     *
     * Numbers are written as literals so they compare exactly: bound as strings,
     * MySQL compares a BIGINT against them as a double, which cannot tell two
     * values above 2^53 apart. decode_cursor() let through digits, a sign and one
     * point only. Binary values are hex literals, which also keep the query plain
     * ASCII. Everything else - text, dates and times - is bound as a string.
     *
     * @param string $kind  key_kind() result
     * @param string $value Decoded cursor value
     * @param array  $args  prepare() arguments, appended to when a placeholder is used
     * @return string
     */
    private function key_literal( $kind, $value, &$args ) {
        if ( 'int' === $kind || 'decimal' === $kind ) {
            return $value;
        }

        if ( 'binary' === $kind ) {
            return "X'" . bin2hex( $value ) . "'";
        }

        $args[] = $value;
        return '%s';
    }

    /**
     * The next_cursor for a page that ended on $row.
     *
     * base64 of a small JSON document naming the table and key columns, with each
     * key value base64-encoded in turn so any bytes survive JSON: text in any
     * character set, binary keys, everything.
     *
     * @param string $table Table name
     * @param array  $key   get_paging_key() result
     * @param array  $row   The page's last row, as read from the table
     * @return string|WP_Error
     */
    private function encode_cursor( $table, $key, $row ) {
        $values = array();
        foreach ( $key as $part ) {
            // Key columns are NOT NULL, so a missing value means the row is not
            // what the query was asked for. Stop rather than page from a guess.
            if ( ! isset( $row[ $part['column'] ] ) ) {
                return new WP_Error(
                    'cursor_failed',
                    __( 'A key column had no value.', 'hostney-migration' ),
                    array( 'status' => 500 )
                );
            }
            $values[] = base64_encode( (string) $row[ $part['column'] ] );
        }

        return base64_encode( wp_json_encode( array(
            'v' => self::CURSOR_VERSION,
            't' => $table,
            'c' => $this->key_columns( $key ),
            'k' => $values,
        ) ) );
    }

    /**
     * Read a cursor back, and refuse anything this table did not produce.
     *
     * The worker only ever returns a cursor this plugin made, but it still
     * arrives over the network: it is checked for its table, its key columns and
     * the shape of every value before any of it reaches a query.
     *
     * @param string $cursor A next_cursor
     * @param string $table  Table name
     * @param array  $key    get_paging_key() result
     * @return array|WP_Error Key values, one per key column
     */
    private function decode_cursor( $cursor, $table, $key ) {
        $invalid = new WP_Error(
            'invalid_cursor',
            __( 'Invalid paging cursor.', 'hostney-migration' ),
            array( 'status' => 400 )
        );

        if ( ! is_string( $cursor ) || strlen( $cursor ) > self::MAX_CURSOR_LENGTH ) {
            return $invalid;
        }

        $json = base64_decode( $cursor, true );
        if ( false === $json ) {
            return $invalid;
        }

        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || ! isset( $data['v'], $data['t'], $data['c'], $data['k'] )
            || self::CURSOR_VERSION !== $data['v']
            || $table !== $data['t']
            || $this->key_columns( $key ) !== $data['c']
            || ! is_array( $data['k'] )
            || count( $data['k'] ) !== count( $key ) ) {
            return $invalid;
        }

        $values = array();
        foreach ( $key as $i => $part ) {
            $encoded = isset( $data['k'][ $i ] ) ? $data['k'][ $i ] : null;
            $value   = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;

            if ( false === $value ) {
                return $invalid;
            }
            if ( 'int' === $part['kind'] && ! preg_match( '/^-?[0-9]{1,20}$/D', $value ) ) {
                return $invalid;
            }
            if ( 'decimal' === $part['kind'] && ! preg_match( '/^-?[0-9]{1,65}(\.[0-9]{1,30})?$/D', $value ) ) {
                return $invalid;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * Column names of a paging key, in key order.
     *
     * @param array $key get_paging_key() result
     * @return string[]
     */
    private function key_columns( $key ) {
        return wp_list_pluck( $key, 'column' );
    }

    /**
     * The key get_rows() pages on in key order.
     *
     * The primary key, else the first unique key whose columns are all NOT NULL;
     * a key qualifies only when every column is indexed whole (no prefix length,
     * no expression), has a plain name, and is of a type key_kind() accepts.
     *
     * @param string $table          Validated table name
     * @param array  $columns_result SHOW COLUMNS rows for the table
     * @return array|null List of array( 'column' => name, 'kind' => key_kind() ), or null
     */
    private function get_paging_key( $table, $columns_result ) {
        $types = array();
        foreach ( $columns_result as $col ) {
            $types[ $col['Field'] ] = strtolower( $col['Type'] );
        }

        foreach ( $this->get_unique_keys( $table ) as $parts ) {
            $key = array();
            foreach ( $parts as $part ) {
                $column = (string) $part['Column_name'];
                $kind   = isset( $types[ $column ] ) ? $this->key_kind( $types[ $column ] ) : null;

                if ( null === $kind
                    || null !== $part['Sub_part']
                    || 'YES' === $part['Null']
                    || ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) ) {
                    $key = array();
                    break;
                }

                $key[] = array(
                    'column' => $column,
                    'kind'   => $kind,
                );
            }

            if ( ! empty( $key ) ) {
                return $key;
            }
        }

        return null;
    }

    /**
     * How a key column's values are compared, or null when key paging cannot
     * use the column.
     *
     * Only types whose ORDER BY and whose comparison agree qualify. ENUM and SET
     * sort by their position in the definition but compare as text; FLOAT and
     * DOUBLE cannot be compared for equality reliably; BIT, JSON and spatial types
     * are not worth the risk.
     *
     * @param string $type Lower-case column type from SHOW COLUMNS
     * @return string|null 'int', 'decimal', 'string' or 'binary'
     */
    private function key_kind( $type ) {
        if ( preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint)\b/', $type ) ) {
            return 'int';
        }
        if ( preg_match( '/^(decimal|numeric|dec|fixed)\b/', $type ) ) {
            return 'decimal';
        }
        if ( preg_match( '/^(char|varchar|date|datetime|timestamp|time|year)\b/', $type ) ) {
            return 'string';
        }
        if ( preg_match( '/^(binary|varbinary)\b/', $type ) ) {
            return 'binary';
        }

        return null;
    }

    /**
     * The table's unique indexes, the primary key first, each as its SHOW INDEX
     * rows in column order.
     *
     * @param string $table Validated table name
     * @return array Index name => list of SHOW INDEX rows
     */
    private function get_unique_keys( $table ) {
        global $wpdb;

        if ( isset( $this->unique_keys[ $table ] ) ) {
            return $this->unique_keys[ $table ];
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
        // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table validated by the caller (regex + allowlist)
        $indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $keys = array();
        foreach ( (array) $indexes as $index ) {
            if ( '0' !== (string) $index['Non_unique'] ) {
                continue;
            }
            $keys[ $index['Key_name'] ][ intval( $index['Seq_in_index'] ) ] = $index;
        }

        foreach ( $keys as $name => $parts ) {
            ksort( $parts );
            $keys[ $name ] = array_values( $parts );
        }

        if ( isset( $keys['PRIMARY'] ) ) {
            $keys = array( 'PRIMARY' => $keys['PRIMARY'] ) + $keys;
        }

        $this->unique_keys[ $table ] = $keys;

        return $keys;
    }

    /**
     * ORDER BY clause for the table's primary key, of any type, or '' when it has
     * none (or a column name the query could not carry bare).
     *
     * @param string $table Validated table name
     * @return string
     */
    private function primary_key_order_by( $table ) {
        $keys = $this->get_unique_keys( $table );

        if ( empty( $keys['PRIMARY'] ) ) {
            return '';
        }

        $order = array();
        foreach ( $keys['PRIMARY'] as $part ) {
            $column = (string) $part['Column_name'];
            if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $column ) ) {
                return '';
            }
            $order[] = '`' . $column . '`';
        }

        return ' ORDER BY ' . implode( ', ', $order );
    }

    /**
     * Encode binary columns as base64
     *
     * @param array $rows           Rows as read from the table
     * @param array $binary_columns Column names
     * @return array
     */
    private function encode_binary_columns( $rows, $binary_columns ) {
        if ( ! empty( $binary_columns ) && ! empty( $rows ) ) {
            foreach ( $rows as &$row ) {
                foreach ( $binary_columns as $bin_col ) {
                    if ( isset( $row[ $bin_col ] ) && $row[ $bin_col ] !== null ) {
                        $row[ $bin_col ] = 'base64:' . base64_encode( $row[ $bin_col ] );
                    }
                }
            }
            unset( $row );
        }

        return $rows;
    }

    /**
     * Log a failed export query server-side, and answer without its details.
     *
     * @param string $table Table name
     * @return WP_Error
     */
    private function query_failed( $table ) {
        global $wpdb;

        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional server-side logging for migration export failures
        error_log( '[Hostney Migration] DB export error on table ' . sanitize_text_field( $table ) . ': ' . $wpdb->last_error );

        return new WP_Error(
            'db_error',
            __( 'Database query failed.', 'hostney-migration' ),
            array( 'status' => 500 )
        );
    }

    /**
     * Get the primary key column for a table
     *
     * @param string     $table   Table name
     * @param array|null $columns SHOW COLUMNS rows, when the caller already has them
     * @return string|null Primary key column name
     */
    private function get_primary_key( $table, $columns = null ) {
        global $wpdb;

        if ( null === $columns ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- migration export, caching not applicable
            // phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder -- %1s is intentional for identifier (table name)
            $columns = $wpdb->get_results(
                $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $table ),
                ARRAY_A
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            // phpcs:enable WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
        }

        foreach ( $columns as $col ) {
            if ( $col['Key'] === 'PRI' ) {
                // Only use numeric primary keys for WHERE > %d pagination
                $type = strtolower( $col['Type'] );
                if ( preg_match( '/int|decimal|float|double|numeric/', $type ) ) {
                    return $col['Field'];
                }
                // Non-numeric PK (varchar, etc.) - fall back to LIMIT/OFFSET
                return null;
            }
        }

        return null;
    }
}
