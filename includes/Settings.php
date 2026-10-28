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
        // Deben ser modelos que cualquier cuenta nueva pueda usar: los Gemini 2.5
        // dejaron de estarlo (Google los limita a quien ya los había usado), y
        // un default inaccesible hace fallar la primera generación.
        $defaults = [
            'openai' => 'gpt-6-luna',
            'google' => 'gemini-3.8-flash',
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

    // ── Perfil del negocio ───────────────────────────────────────────────────
    //
    // Antes el único contexto era un texto libre. Si decía "tienda en
    // Concepción", la IA lo leía como señal local y no había forma de pedir un
    // posicionamiento nacional, ni de decir que el sitio es un blog sin nada que
    // vender. Estos campos lo dicen de forma explícita, y PromptBuilder los
    // convierte en instrucciones concretas.

    /** Tipo de sitio: decide el objetivo de los textos (vender, captar, informar). */
    public const SITE_TYPES = [
        'tienda'    => 'Tienda online',
        'servicios' => 'Empresa de servicios',
        'digital'   => 'Negocio 100 % digital (software, cursos, suscripciones)',
        'contenido' => 'Blog o medio de contenidos',
    ];

    /** Alcance geográfico: decide si las keywords llevan ubicación. */
    public const SCOPES = [
        'local'         => 'Local: una ciudad o comuna',
        'regional'      => 'Regional: una región o zona',
        'nacional'      => 'Nacional: todo el país',
        'internacional' => 'Internacional: varios países del mismo idioma',
    ];

    /** Modalidad: decide qué se puede prometer (visita, envío, acceso online). */
    public const DELIVERY = [
        'presencial' => 'Presencial (local, oficina o atención en la zona)',
        'envio'      => 'Venta online con envío o despacho',
        'mixta'      => 'Local físico y venta online',
        'digital'    => '100 % digital (se entrega o se usa por internet)',
    ];

    /** Largo máximo de cada campo de texto del perfil: todo viaja en cada prompt. */
    public const PROFILE_LIMITS = [
        'rubro'           => 100,
        'zona'            => 100,
        'publico'         => 300,
        'diferenciadores' => 400,
    ];

    /**
     * Perfil del negocio, ya validado.
     *
     * Las selecciones que no estén en su lista vuelven vacías ("sin
     * especificar"): una opción desconocida no debe llegar al prompt.
     *
     * @return array{tipo: string, rubro: string, descripcion: string, publico: string, diferenciadores: string, alcance: string, zona: string, modalidad: string}
     */
    public static function business_profile(): array {
        $elegir = static function ( string $key, array $opciones ): string {
            $valor = (string) get_option( self::PREFIX . $key, '' );
            return isset( $opciones[ $valor ] ) ? $valor : '';
        };

        return [
            'tipo'            => $elegir( 'tipo_sitio', self::SITE_TYPES ),
            'rubro'           => (string) get_option( self::PREFIX . 'rubro', '' ),
            'descripcion'     => self::site_context(),
            'publico'         => (string) get_option( self::PREFIX . 'publico', '' ),
            'diferenciadores' => (string) get_option( self::PREFIX . 'diferenciadores', '' ),
            'alcance'         => $elegir( 'alcance', self::SCOPES ),
            'zona'            => (string) get_option( self::PREFIX . 'zona', '' ),
            'modalidad'       => $elegir( 'modalidad', self::DELIVERY ),
        ];
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

        return self::default_prompt( $type );
    }

    /**
     * Prompt por defecto de un tipo, compuesto con el registro actual.
     */
    public static function default_prompt( string $type ): string {
        $default_method = 'default_prompt_' . sanitize_key( $type );

        return method_exists( Activator::class, $default_method )
            ? Activator::{$default_method}()
            : '';
    }

    /**
     * Forma comparable de un prompt: los navegadores envían los textarea con
     * saltos \r\n, así que el mismo texto vuelve distinto de como salió.
     */
    public static function normalize_prompt( string $text ): string {
        return trim( str_replace( [ "\r\n", "\r" ], "\n", $text ) );
    }

    /**
     * Guarda un prompt solo si difiere del por defecto.
     *
     * Antes se guardaban todos, también los que nadie había tocado (al activar
     * y en cada "Guardar configuración"), y desde ese momento una mejora de
     * los prompts por defecto ya no llegaba a esa instalación. Ahora sin
     * personalizar no se guarda nada y Settings::prompt() compone el actual.
     */
    public static function save_prompt( string $type, string $text ): void {
        $option = self::PREFIX . 'prompt_' . sanitize_key( $type );

        if ( '' === self::normalize_prompt( $text )
            || self::normalize_prompt( $text ) === self::normalize_prompt( self::default_prompt( $type ) ) ) {
            delete_option( $option );
            return;
        }

        update_option( $option, $text );
    }

    /**
     * Si «Completar» reemplaza también los campos que ya tienen contenido.
     *
     * Desactivado por defecto: completar es rellenar lo que falta. Antes la
     * opción existía pero no la leía nadie y la interfaz mandaba siempre
     * sobrescribir, así que una descripción escrita a mano se perdía al pulsar
     * «Completar» (los productos de WooCommerce no guardan revisiones).
     */
    public static function overwrite(): bool {
        return '1' === (string) get_option( self::PREFIX . 'overwrite', '0' );
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
            // Revisado 2026-10-28. OpenAI apagó gpt-4-turbo el 2026-10-23, apaga
            // la familia gpt-5 el 2026-12-11 y gpt-4o/gpt-4o-mini/gpt-4.1 el
            // 2027-01-14 (developers.openai.com/api/docs/deprecations). Quien
            // tuviera uno guardado pasa al default por model().
            'openai' => [
                'gpt-6-luna'    => 'GPT-6 Luna — Recomendado, más económico',
                'gpt-6.1-terra' => 'GPT-6.1 Terra — Redacción más natural, costo medio',
                'gpt-6.1-sol'   => 'GPT-6.1 Sol — Equilibrado',
                'gpt-6-astra'   => 'GPT-6 Astra — Mejor calidad, costo alto',
            ],
            // Los Gemini 2.5 ya solo los sirve Google a cuentas que los usaban, y
            // gemini-3.7-flash se redirige a 3.8 (ai.google.dev/gemini-api/docs/deprecations).
            'google' => [
                'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite — Más económico',
                'gemini-3.8-flash'      => 'Gemini 3.8 Flash — Recomendado',
                'gemini-4-argon'        => 'Gemini 4 Argon — Mejor calidad',
            ],
        ] );
    }

    /** Fallback cuando un modelo no tiene precio de salida declarado. */
    public const FALLBACK_COST_PER_TOKEN = 0.000001;

    /**
     * Fallback cuando un modelo no tiene precio de entrada declarado: una quinta
     * parte del de salida, que es la proporción habitual en ambos proveedores.
     */
    public const FALLBACK_INPUT_COST_PER_TOKEN = 0.0000002;

    /**
     * Precio vigente hoy de un modelo con una subida ya anunciada.
     *
     * Así el registro de costos acierta el día del cambio sin esperar a que
     * salga una versión nueva del plugin.
     *
     * @param string $change_date Fecha (Y-m-d, UTC) desde la que rige el precio nuevo.
     * @param float  $before      Precio por token hasta esa fecha.
     * @param float  $after       Precio por token desde esa fecha.
     */
    private static function price_until( string $change_date, float $before, float $after ): float {
        return gmdate( 'Y-m-d' ) < $change_date ? $before : $after;
    }

    /**
     * Tabla completa de costos por token de SALIDA, en USD.
     *
     * Única fuente de verdad: la calculadora del admin la recibe por
     * wp_localize_script en vez de tener su propia copia en JS. Antes había dos
     * tablas que se desincronizaban, y la del JS no pasaba por este filtro, así
     * que un addon no podía corregirla.
     *
     * La salida incluye los tokens de razonamiento: ambos proveedores los
     * cobran a precio de salida.
     *
     * Verificado 2026-10-28 contra developers.openai.com/api/docs/models y
     * ai.google.dev/gemini-api/docs/pricing.
     *
     * @return array<string, float> slug => USD por token de salida
     */
    public static function token_costs(): array {
        return apply_filters( 'hcsai_token_costs', [
            'gpt-6-luna'            => 0.00000050, // $0.50 / 1M
            'gpt-6.1-terra'         => 0.00000500, // $5.00 / 1M
            'gpt-6.1-sol'           => 0.00001000, // $10.00 / 1M
            'gpt-6-astra'           => 0.00005000, // $50.00 / 1M
            'gemini-3.5-flash-lite' => 0.00000250, // $2.50 / 1M
            // Precios de lanzamiento con subida anunciada.
            'gemini-3.8-flash'      => self::price_until( '2027-01-01', 0.00000375, 0.00000750 ), // $3.75 → $7.50 / 1M
            'gemini-4-argon'        => self::price_until( '2026-12-01', 0.00001000, 0.00002000 ), // $10 → $20 / 1M
        ] );
    }

    /**
     * Tabla de costos por token de ENTRADA, en USD.
     *
     * Antes el costo registrado solo contaba la salida. El prompt —con la
     * descripción del producto o el post entero al mejorarlo— pesa entre un
     * 15% y un 40% del total, y quedaba fuera.
     *
     * @return array<string, float> slug => USD por token de entrada
     */
    public static function token_input_costs(): array {
        return apply_filters( 'hcsai_token_input_costs', [
            'gpt-6-luna'            => 0.00000010, // $0.10 / 1M
            'gpt-6.1-terra'         => 0.00000100, // $1.00 / 1M
            'gpt-6.1-sol'           => 0.00000200, // $2.00 / 1M
            'gpt-6-astra'           => 0.00001000, // $10.00 / 1M
            'gemini-3.5-flash-lite' => 0.00000030, // $0.30 / 1M
            'gemini-3.8-flash'      => self::price_until( '2027-01-01', 0.00000075, 0.00000150 ), // $0.75 → $1.50 / 1M
            'gemini-4-argon'        => self::price_until( '2026-12-01', 0.00000200, 0.00000400 ), // $2 → $4 / 1M
        ] );
    }

    /**
     * Costo por token de salida de un modelo concreto (en USD).
     */
    public static function cost_per_token( string $model ): float {
        return self::token_costs()[ $model ] ?? self::FALLBACK_COST_PER_TOKEN;
    }

    /**
     * Costo en USD de una llamada, sumando entrada y salida.
     *
     * @param string $model         Modelo usado.
     * @param int    $tokens_input  Tokens del prompt.
     * @param int    $tokens_output Tokens generados, razonamiento incluido.
     */
    public static function cost_for( string $model, int $tokens_input, int $tokens_output ): float {
        $input_cost = self::token_input_costs()[ $model ] ?? self::FALLBACK_INPUT_COST_PER_TOKEN;

        return $tokens_input * $input_cost + $tokens_output * self::cost_per_token( $model );
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
