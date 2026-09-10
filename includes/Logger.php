<?php
/**
 * Sistema de log centralizado.
 * Registra generaciones en la tabla hcsai_log con queries preparadas.
 *
 * @package HUP\CamposSeoIA
 */

namespace HUP\CamposSeoIA;

defined( 'ABSPATH' ) || exit;

class Logger {

    /**
     * Nombre de la tabla sin prefijo.
     */
    private const TABLE = 'hcsai_log';

    /** Días de retención por defecto del log. */
    public const DEFAULT_RETENTION_DAYS = 90;

    /** Evento de WP-Cron que dispara la purga. */
    public const PURGE_HOOK = 'hcsai_purge_log';

    /**
     * Registra una entrada en el log.
     *
     * @param int    $post_id    ID del post.
     * @param string $post_type  Tipo de post.
     * @param string $action     Acción realizada.
     * @param int    $tokens     Tokens usados.
     * @param float  $cost       Costo en USD.
     * @param string $model      Modelo usado.
     * @param string $status     'ok' o 'error'.
     * @param string $error_msg  Mensaje de error (opcional).
     */
    public static function log(
        int $post_id,
        string $post_type,
        string $action,
        int $tokens,
        float $cost,
        string $model,
        string $status = 'ok',
        string $error_msg = ''
    ): void {
        global $wpdb;

        $table    = $wpdb->prefix . self::TABLE;
        $provider = Settings::provider();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        $wpdb->insert(
            $table,
            [
                'post_id'     => $post_id,
                'post_type'   => $post_type,
                'action'      => $action,
                'tokens_used' => $tokens,
                'cost_usd'    => $cost,
                'model'       => $model,
                'provider'    => $provider,
                'status'      => $status,
                'error_msg'   => $error_msg,
                'created_at'  => current_time( 'mysql', true ),
            ],
            [ '%d', '%s', '%s', '%d', '%f', '%s', '%s', '%s', '%s', '%s' ]
        );
    }

    /**
     * Obtiene las últimas entradas del log con paginación.
     *
     * @param int $limit  Cantidad de registros.
     * @param int $offset Desplazamiento.
     * @return array
     */
    public static function get_recent( int $limit = 10, int $offset = 0 ): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.*, p.post_title
                 FROM %i l
                 LEFT JOIN {$wpdb->posts} p ON l.post_id = p.ID
                 ORDER BY l.created_at DESC
                 LIMIT %d OFFSET %d",
                $table,
                $limit,
                $offset
            )
        );
    }

    /**
     * Obtiene el costo total del mes actual, en el huso horario del sitio.
     *
     * created_at se guarda en GMT (current_time('mysql', true)), pero "el mes
     * actual" es el del huso del sitio. Antes se comparaba con YEAR()/MONTH()
     * contra current_time('Y'/'n'), que devuelve la hora LOCAL: en un huso con
     * desfase negativo (Chile es UTC-3/-4) las generaciones de las últimas horas
     * de cada mes ya estaban en el mes siguiente en GMT y no se contaban.
     *
     * Se calculan los límites del mes en local y se convierten a GMT. De paso la
     * query pasa a ser un rango sobre la columna, así que puede usar el índice
     * idx_created_at: envolverla en YEAR()/MONTH() lo impedía.
     *
     * @return float Costo en USD.
     */
    public static function monthly_cost(): float {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        $site_tz   = wp_timezone();
        $utc       = new \DateTimeZone( 'UTC' );
        $month_ini = new \DateTimeImmutable( 'first day of this month 00:00:00', $site_tz );
        $month_end = $month_ini->modify( '+1 month' );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        $result = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COALESCE(SUM(cost_usd), 0) FROM %i
                 WHERE created_at >= %s AND created_at < %s AND status = %s",
                $table,
                $month_ini->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
                $month_end->setTimezone( $utc )->format( 'Y-m-d H:i:s' ),
                'ok'
            )
        );

        return (float) $result;
    }

    /**
     * Estadísticas generales del log.
     *
     * @return array{total_generations: int, total_tokens: int, total_cost: float}
     */
    public static function stats(): array {
        global $wpdb;

        $table = $wpdb->prefix . self::TABLE;

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, COALESCE(SUM(tokens_used),0) AS tokens, COALESCE(SUM(cost_usd),0) AS cost
                 FROM %i WHERE status = %s",
                $table,
                'ok'
            )
        );

        $archived = self::archived_totals();

        return [
            'total_generations' => (int) ( $row->total ?? 0 ) + $archived['generations'],
            'total_tokens'      => (int) ( $row->tokens ?? 0 ) + $archived['tokens'],
            'total_cost'        => (float) ( $row->cost ?? 0 ) + $archived['cost'],
        ];
    }

    /**
     * Totales acumulados de las filas ya purgadas.
     *
     * Sin esto, "Generaciones totales" del dashboard bajaría cada vez que la
     * purga borra filas antiguas: el detalle se descarta, el agregado no.
     *
     * @return array{generations: int, tokens: int, cost: float}
     */
    private static function archived_totals(): array {
        $stored = get_option( HCSAI_PREFIX . 'log_totals', [] );

        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return [
            'generations' => (int) ( $stored['generations'] ?? 0 ),
            'tokens'      => (int) ( $stored['tokens'] ?? 0 ),
            'cost'        => (float) ( $stored['cost'] ?? 0 ),
        ];
    }

    /**
     * Elimina las entradas de log más antiguas que el periodo de retención.
     *
     * Enganchado al evento diario de WP-Cron 'hcsai_purge_log'. Sin esto la
     * tabla crecía sin límite: una fila por cada generación, para siempre.
     *
     * Antes de borrar acumula los totales de las filas 'ok' en la opción
     * hcsai_log_totals, para no falsear las estadísticas del dashboard.
     */
    public static function purge(): void {
        global $wpdb;

        $days = (int) Settings::get( 'log_retention_days', self::DEFAULT_RETENTION_DAYS );

        // 0 = conservar todo, para quien quiera el historial completo.
        if ( $days <= 0 ) {
            return;
        }

        $table = $wpdb->prefix . self::TABLE;

        // created_at se escribe en GMT (current_time('mysql', true)), así que el
        // corte también se calcula en GMT.
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

        // Solo se acumulan las 'ok' porque stats() solo cuenta esas.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT COUNT(*) AS total, COALESCE(SUM(tokens_used),0) AS tokens, COALESCE(SUM(cost_usd),0) AS cost
                 FROM %i WHERE created_at < %s AND status = %s",
                $table,
                $cutoff,
                'ok'
            )
        );

        if ( $row && (int) $row->total > 0 ) {
            $archived = self::archived_totals();
            update_option( HCSAI_PREFIX . 'log_totals', [
                'generations' => $archived['generations'] + (int) $row->total,
                'tokens'      => $archived['tokens'] + (int) $row->tokens,
                'cost'        => $archived['cost'] + (float) $row->cost,
            ] );
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Tabla propia del plugin (hcsai_log); no existe API de WordPress para ella y no procede cachear.
        $wpdb->query(
            $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', $table, $cutoff )
        );
    }
}
