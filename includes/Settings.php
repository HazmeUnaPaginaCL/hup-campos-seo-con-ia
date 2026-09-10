<?php
/**
 * Gestión centralizada de opciones del plugin.
 * Lectura, escritura, encriptación de API keys y catálogo de modelos.
 *
 * @package HUP\CamposSeoIA
 */

namespace HUP\CamposSeoIA;

defined( 'ABSPATH' ) || exit;

class Settings {

    /** Prefijo de opciones */
    private const PREFIX = HCSAI_PREFIX;

    /** Clave de encriptación derivada de AUTH_KEY de WordPress */
    private const CIPHER = 'aes-256-cbc';

    // ── Getters de opciones ──────────────────────────────────────────────────

    /**
     * Proveedor de IA activo.
     */
    public static function provider(): string {
        return sanitize_text_field( get_option( self::PREFIX . 'provider', 'openai' ) );
    }

    /**
     * Modelo activo para el proveedor actual.
     */
    public static function model(): string {
        $provider = self::provider();
        $model    = sanitize_text_field( get_option( self::PREFIX . 'model_' . $provider, self::default_model( $provider ) ) );

        // Un modelo guardado que ya no está en el catálogo (el proveedor lo apagó,
        // o un addon filtró la lista) haría fallar cada generación con un error de
        // la API. Se cae al default del proveedor, que además es lo que el selector
        // de Configuración ya muestra en ese caso: así UI y comportamiento coinciden.
        $available = self::models_for( $provider );
        if ( ! empty( $available ) && ! isset( $available[ $model ] ) ) {
            return self::default_model( $provider );
        }

        return $model;
    }

    /**
     * Modelo por defecto para un proveedor.
     */
    public static function default_model( string $provider ): string {
        $defaults = [
            'openai' => 'gpt-4o-mini',
            'google' => 'gemini-2.5-flash',
        ];
        return $defaults[ $provider ] ?? '';
    }

    /**
     * Obtiene la API key desencriptada para un proveedor.
     */
    public static function api_key( string $provider ): string {
        $encrypted = get_option( self::PREFIX . 'api_key_' . $provider, '' );
        if ( empty( $encrypted ) ) {
            return '';
        }
        return self::decrypt( $encrypted );
    }

    /**
     * Verifica si existe API key para un proveedor.
     */
    public static function has_api_key( string $provider ): bool {
        return '' !== get_option( self::PREFIX . 'api_key_' . $provider, '' );
    }

    /**
     * Hay una API key guardada pero no se puede descifrar.
     *
     * Pasa cuando cambian los salts de WordPress (AUTH_KEY), de donde se deriva
     * la clave de cifrado: rotarlos es una práctica de seguridad normal y deja
     * la key almacenada ilegible. Sin distinguir este caso, la Configuración
     * mostraba "API key guardada" mientras la generación decía "API key no
     * configurada", sin pista de qué había pasado ni de cómo arreglarlo.
     */
    public static function api_key_unreadable( string $provider ): bool {
        $stored = get_option( self::PREFIX . 'api_key_' . $provider, '' );

        return '' !== $stored && '' === self::decrypt( $stored );
    }

    /**
     * Mensaje de error apropiado cuando no hay una API key utilizable.
     */
    public static function api_key_error( string $provider ): string {
        if ( self::api_key_unreadable( $provider ) ) {
            return 'La API key guardada no se puede descifrar. Suele pasar tras cambiar las claves de seguridad (salts) de WordPress. Vuelve a pegarla en Configuración.';
        }

        return 'API key no configurada.';
    }

    /**
     * Estado de conexión para un proveedor: 'ok', 'error', 'unknown'.
     */
    public static function connection_status( string $provider ): string {
        return sanitize_text_field( get_option( self::PREFIX . 'conn_' . $provider, 'unknown' ) );
    }

    /**
     * Guarda el estado de conexión para un proveedor.
     */
    public static function set_connection_status( string $provider, string $status ): void {
        update_option( self::PREFIX . 'conn_' . $provider, sanitize_text_field( $status ) );
    }

    /**
     * Contexto del sitio/negocio para los prompts.
     */
    public static function site_context(): string {
        return get_option( self::PREFIX . 'site_context', '' );
    }

    /**
     * País configurado (código ISO).
     */
    public static function country(): string {
        return sanitize_text_field( get_option( self::PREFIX . 'country', 'CL' ) );
    }

    /**
     * Idioma configurado.
     */
    public static function language(): string {
        return sanitize_text_field( get_option( self::PREFIX . 'language', 'es' ) );
    }

    /**
     * Prompt personalizado por tipo: product, post, post_seo, page, alt, improve.
     *
     * Si la opción no existe o está vacía se devuelve el default del Activator.
     * Esto evita depender de que la migración ya haya corrido: un tipo de prompt
     * nuevo funciona (y se muestra en Configuración) desde el primer momento.
     */
    public static function prompt( string $type ): string {
        $type  = sanitize_key( $type );
        $value = get_option( self::PREFIX . 'prompt_' . $type, '' );

        if ( '' !== trim( (string) $value ) ) {
            return $value;
        }

        $default_method = 'default_prompt_' . $type;

        return method_exists( Activator::class, $default_method )
            ? Activator::{$default_method}()
            : '';
    }

    /**
     * Tasa USD a CLP.
     */
    public static function usd_to_clp(): float {
        return (float) get_option( self::PREFIX . 'usd_to_clp', 960 );
    }

    // ── Guardar opciones ─────────────────────────────────────────────────────

    /**
     * Guarda la API key encriptada para un proveedor.
     */
    public static function save_api_key( string $provider, string $plain_key ): void {
        if ( empty( $plain_key ) ) {
            delete_option( self::PREFIX . 'api_key_' . $provider );
            return;
        }
        update_option( self::PREFIX . 'api_key_' . $provider, self::encrypt( $plain_key ) );
    }

    /**
     * Guarda una opción genérica del plugin.
     */
    public static function save( string $key, $value ): void {
        update_option( self::PREFIX . $key, $value );
    }

    /**
     * Obtiene una opción genérica del plugin.
     */
    public static function get( string $key, $default = '' ) {
        return get_option( self::PREFIX . $key, $default );
    }

    // ── Campos que rellena la IA ─────────────────────────────────────────────

    /**
     * Qué campos puede rellenar la IA en cada tipo de contenido.
     *
     * No todos los campos aplican a todo: `product_description` escribe en
     * `post_content`, así que ofrecerlo en un post haría que "Completar" le
     * reescribiera el artículo entero — eso es "Mejorar", otra acción distinta.
     *
     * @return array<string, array<string>>
     */
    private static function fields_by_type(): array {
        return [
            'product' => [
                'product_description',
                'short_description',
                'tags',
                'alt_text',
                'seo_title',
                'meta_description',
                'focus_keyword',
                'og_title',
                'og_description',
            ],
            'post'    => [
                'short_description',
                'tags',
                'alt_text',
                'seo_title',
                'meta_description',
                'focus_keyword',
                'og_title',
                'og_description',
            ],
            'page'    => [
                'short_description',
                'seo_title',
                'meta_description',
                'focus_keyword',
                'og_title',
                'og_description',
            ],
        ];
    }

    /**
     * Campos que la IA rellena automáticamente.
     *
     * Es la fuente única: la consultan el prompt, el schema de Gemini, el parser,
     * el Writer y el desglose del score. Así el prompt no pide nada que luego no
     * se vaya a guardar, que es lo que evita pagar tokens de más.
     *
     * Se rellenan **todos** los campos que el plugin sabe escribir. Dónde acaba
     * cada uno lo decide el entorno detectado, no esta lista: con un plugin SEO
     * activo van a sus campos meta, y sin ninguno a los campos propios del
     * plugin, que son los que `MetaOutput` imprime en el `<head>`.
     *
     * Qué campos aplican a cada tipo de contenido lo resuelve `fields_by_type()`:
     * aquí está el conjunto completo, sin distinción de tipo.
     *
     * El filtro está para que otro plugin pueda describir campos que éste no
     * conoce.
     *
     * @return array<string> Slugs de campo.
     */
    public static function generated_fields(): array {
        $fields = apply_filters( 'hcsai_generated_fields', [
            'product_description',
            'short_description',
            'tags',
            'alt_text',
            'seo_title',
            'meta_description',
            'focus_keyword',
            'og_title',
            'og_description',
        ] );

        return array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) $fields ) ) ) );
    }

    /**
     * Campos que la IA rellena para un tipo de contenido concreto.
     *
     * Cruza el registro con lo que aplica a ese tipo. Un tipo desconocido no
     * recibe nada: mejor no generar que escribir donde no toca.
     *
     * @return array<string>
     */
    public static function fields_for( string $post_type ): array {
        $aplicables = self::fields_by_type()[ $post_type ] ?? [];

        return array_values( array_intersect( self::generated_fields(), $aplicables ) );
    }

    /**
     * Comprueba si un campo lo rellena la IA en esta instalación.
     */
    public static function generates_field( string $field ): bool {
        return in_array( sanitize_key( $field ), self::generated_fields(), true );
    }

    // ── Catálogo de modelos por proveedor ────────────────────────────────────
    // Para agregar un nuevo proveedor, basta con agregar una entrada aquí.

    /**
     * Retorna modelos disponibles para un proveedor.
     *
     * @return array<string, string> slug => nombre visible
     */
    public static function models_for( string $provider ): array {
        $catalog = self::models_catalog();
        return $catalog[ $provider ] ?? [];
    }

    /**
     * Catálogo completo de modelos.
     * Extensible: agregar una clave nueva para cada proveedor futuro.
     *
     * @return array<string, array<string, string>>
     */
    public static function models_catalog(): array {
        return apply_filters( 'hcsai_models_catalog', [
            'openai' => [
                'gpt-5-nano'   => 'GPT-5 Nano — Más económico',
                'gpt-5-mini'   => 'GPT-5 Mini — Equilibrado',
                'gpt-5'        => 'GPT-5 — Mejor calidad',
                'gpt-4o-mini'  => 'GPT-4o Mini — Generación anterior, económico',
                'gpt-4o'       => 'GPT-4o — Generación anterior',
                'gpt-4-turbo'  => 'GPT-4 Turbo — Generación anterior',
            ],
            'google' => [
                'gemini-2.5-flash-lite' => 'Gemini 2.5 Flash Lite — Más económico',
                'gemini-3.7-flash'      => 'Gemini 3.7 Flash — Recomendado',
                'gemini-2.5-flash'      => 'Gemini 2.5 Flash — Generación anterior',
                'gemini-2.5-pro'        => 'Gemini 2.5 Pro — Generación anterior, mejor calidad',
            ],
        ] );
    }

    /** Fallback cuando un modelo no tiene precio declarado. */
    public const FALLBACK_COST_PER_TOKEN = 0.000001;

    /**
     * Tabla completa de costos por token de SALIDA, en USD.
     *
     * Única fuente de verdad: la calculadora del admin la recibe por
     * wp_localize_script en vez de tener su propia copia en JS. Antes había dos
     * tablas que se desincronizaban, y la del JS no pasaba por este filtro, así
     * que un addon no podía corregirla.
     *
     * Verificado 2026-08-16 contra developers.openai.com/api/docs/pricing y
     * ai.google.dev/gemini-api/docs/pricing.
     *
     * @return array<string, float> slug => USD por token de salida
     */
    public static function token_costs(): array {
        return apply_filters( 'hcsai_token_costs', [
            // OpenAI — generación actual
            'gpt-5'            => 0.00001000, // $10.00 / 1M
            'gpt-5-mini'       => 0.00000200, // $2.00 / 1M
            'gpt-5-nano'       => 0.00000040, // $0.40 / 1M
            // OpenAI — generación anterior, aún activa
            'gpt-4o-mini'      => 0.00000060, // $0.60 / 1M
            'gpt-4o'           => 0.00001000, // $10.00 / 1M
            'gpt-4-turbo'      => 0.00003000, // $30.00 / 1M
            // Google — generación actual
            // OJO: precio de lanzamiento, sube a $7.50/1M el 2027-01-01.
            'gemini-3.7-flash'      => 0.00000375, // $3.75 / 1M
            // Google — generación anterior, aún activa
            'gemini-2.5-flash'      => 0.00000250, // $2.50 / 1M
            'gemini-2.5-pro'        => 0.00001000, // $10.00 / 1M (prompts <= 200k; el plugin nunca los supera)
            'gemini-2.5-flash-lite' => 0.00000040, // $0.40 / 1M
        ] );
    }

    /**
     * Costo por token de salida de un modelo concreto (en USD).
     */
    public static function cost_per_token( string $model ): float {
        return self::token_costs()[ $model ] ?? self::FALLBACK_COST_PER_TOKEN;
    }

    /**
     * Lista de proveedores registrados con nombre visible.
     *
     * @return array<string, string> slug => nombre
     */
    public static function providers_display(): array {
        return apply_filters( 'hcsai_providers_display', [
            'openai' => 'OpenAI (ChatGPT)',
            'google' => 'Google (Gemini)',
        ] );
    }

    // ── Encriptación ─────────────────────────────────────────────────────────
    /**
     * Encripta un texto usando AES-256-CBC.
     *
     * El valor guardado es base64( IV || ciphertext ), con el IV al principio y
     * de longitud fija. Antes se separaban con '::', pero el IV son bytes
     * binarios aleatorios y puede contener esa secuencia (~1 de cada 4.400
     * claves): el explode partía por el sitio equivocado y la API key quedaba
     * ilegible para siempre, mostrándose como "no configurada".
     */
    private static function encrypt( string $plain ): string {
        if ( ! function_exists( 'openssl_encrypt' ) ) {
            wp_die(
                esc_html__( 'Se requiere la extensión OpenSSL de PHP para encriptar las API keys.', 'hup-campos-seo-con-ia' ),
                esc_html__( 'Error de requisitos', 'hup-campos-seo-con-ia' ),
                [ 'back_link' => true ]
            );
        }

        $key = self::encryption_key();
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER ) );

        $encrypted = openssl_encrypt( $plain, self::CIPHER, $key, 0, $iv );

        if ( false === $encrypted ) {
            return '';
        }

        return base64_encode( $iv . $encrypted );
    }

    /**
     * Desencripta un texto encriptado con AES-256-CBC.
     *
     * Lee el formato actual (IV de longitud fija) y también el heredado
     * (IV . '::' . ciphertext), para no invalidar las API keys ya guardadas.
     */
    private static function decrypt( string $encrypted_b64 ): string {
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $decoded = base64_decode( $encrypted_b64, true );
        if ( false === $decoded ) {
            return '';
        }

        $iv_len = openssl_cipher_iv_length( self::CIPHER );
        if ( strlen( $decoded ) <= $iv_len ) {
            return '';
        }

        // El IV siempre ocupa los primeros $iv_len bytes, contenga lo que contenga.
        $iv     = substr( $decoded, 0, $iv_len );
        $cipher = substr( $decoded, $iv_len );

        // Formato heredado: el separador iba justo detrás del IV.
        if ( 0 === strpos( $cipher, '::' ) ) {
            $cipher = substr( $cipher, 2 );
        }

        $decrypted = openssl_decrypt( $cipher, self::CIPHER, self::encryption_key(), 0, $iv );

        return false !== $decrypted ? $decrypted : '';
    }

    /**
     * Clave de encriptación derivada de AUTH_KEY de WordPress.
     */
    private static function encryption_key(): string {
        $salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'hcsai-fallback-key-change-me';
        return hash( 'sha256', $salt . 'hcsai_encryption_key' );
    }
}
