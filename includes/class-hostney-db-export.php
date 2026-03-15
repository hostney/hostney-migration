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
     * Get list of all tables with metadata
     *
     * @return array Table list with name, rows, size, engine, primary_key, create_statement
     */
    public function get_tables() {
        global $wpdb;

        $tables = array();
        $results = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

        if ( empty( $results ) ) {
            return $tables;
        }

        foreach ( $results as $row ) {
            $table_name = $row['Name'];

            // Get primary key column
            $primary_key = $this->get_primary_key( $table_name );

            // Get CREATE TABLE statement
            $create_result = $wpdb->get_row(
                $wpdb->prepare( 'SHOW CREATE TABLE `%1s`', $table_name ),
                ARRAY_A
            );
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
     * Get rows from a table using primary key pagination
     *
     * @param string $table Table name
     * @param int    $last_id Last primary key value (for pagination)
     * @param int    $limit Number of rows to fetch
     * @return array|WP_Error
     */
    public function get_rows( $table, $last_id = 0, $limit = 1000 ) {
        global $wpdb;

        // Try to extend execution time for large exports
        @set_time_limit( 120 );

        // Defense in depth: reject table names with unexpected characters
        if ( ! preg_match( '/^[a-zA-Z0-9_]+$/', $table ) ) {
            return new WP_Error(
                'invalid_table',
                'Invalid table name.',
                array( 'status' => 400 )
            );
        }

        // Validate table name against actual tables to prevent SQL injection
        $valid_tables = $wpdb->get_col( 'SHOW TABLES' );
        if ( ! in_array( $table, $valid_tables, true ) ) {
            return new WP_Error(
                'invalid_table',
                'Table not found.',
                array( 'status' => 400 )
            );
        }

        $primary_key = $this->get_primary_key( $table );

        // Get column names
        $columns_result = $wpdb->get_results(
            $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $table ),
            ARRAY_A
        );
        $columns = wp_list_pluck( $columns_result, 'Field' );

        // Identify binary/blob columns for base64 encoding
        $binary_columns = array();
        foreach ( $columns_result as $col ) {
            $type = strtolower( $col['Type'] );
            if ( strpos( $type, 'blob' ) !== false || strpos( $type, 'binary' ) !== false ) {
                $binary_columns[] = $col['Field'];
            }
        }

        // Fetch rows using primary key pagination
        if ( $primary_key && $last_id > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` WHERE `{$primary_key}` > %d ORDER BY `{$primary_key}` ASC LIMIT %d",
                    $last_id,
                    $limit
                ),
                ARRAY_A
            );
        } elseif ( $primary_key ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` ORDER BY `{$primary_key}` ASC LIMIT %d",
                    $limit
                ),
                ARRAY_A
            );
        } else {
            // No primary key - use LIMIT/OFFSET (less efficient but necessary)
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `{$table}` LIMIT %d OFFSET %d",
                    $limit,
                    $last_id
                ),
                ARRAY_A
            );
        }

        if ( $wpdb->last_error ) {
            // Log the actual error server-side, but don't expose it in the response
            error_log( '[Hostney Migration] DB export error on table ' . sanitize_text_field( $table ) . ': ' . $wpdb->last_error );
            return new WP_Error(
                'db_error',
                'Database query failed.',
                array( 'status' => 500 )
            );
        }

        // Encode binary columns as base64
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
        );
    }

    /**
     * Get the primary key column for a table
     *
     * @param string $table Table name
     * @return string|null Primary key column name
     */
    private function get_primary_key( $table ) {
        global $wpdb;

        $columns = $wpdb->get_results(
            $wpdb->prepare( 'SHOW COLUMNS FROM `%1s`', $table ),
            ARRAY_A
        );

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
