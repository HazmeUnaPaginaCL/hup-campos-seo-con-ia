<?php
/**
 * Vista de administración.
 *
 * Las vistas se cargan con `require` DENTRO de un método de Admin\Menu, así que
 * las variables de este archivo son locales a ese método, no globales: el sniff
 * de prefijos no puede verlo desde aquí y marcaría cada una como global sin
 * prefijo. Los $_GET son filtros, orden y paginación de un listado de solo
 * lectura —no cambian estado, así que no llevan nonce— y todos van sanitizados
 * al leerlos. meta_query es la forma soportada de filtrar por los campos que
 * escriben los plugins SEO, y el listado va paginado.
 *
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.SlowDBQuery
 *
 * @package HUP\CamposSeoIA
 */

defined( 'ABSPATH' ) || exit;

$provider     = \HUP\CamposSeoIA\Settings::provider();
$providers    = \HUP\CamposSeoIA\Settings::providers_display();
$models       = \HUP\CamposSeoIA\Settings::models_catalog();
$site_context = \HUP\CamposSeoIA\Settings::site_context();
$country      = \HUP\CamposSeoIA\Settings::country();
$language     = \HUP\CamposSeoIA\Settings::language();
$usd_to_clp   = \HUP\CamposSeoIA\Settings::usd_to_clp();
$delete_keys  = '1' === (string) \HUP\CamposSeoIA\Settings::get( 'delete_api_keys', '0' );
$log_retention = (int) \HUP\CamposSeoIA\Settings::get( 'log_retention_days', \HUP\CamposSeoIA\Logger::DEFAULT_RETENTION_DAYS );
$saved         = isset( $_GET['saved'] ) ? 1 : 0;

$countries = [ 'CL' => 'Chile', 'MX' => 'México', 'AR' => 'Argentina', 'CO' => 'Colombia', 'PE' => 'Perú', 'ES' => 'España', 'US' => 'Estados Unidos' ];
$languages = [ 'es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués' ];

// Cada prompt recibe solo su propio juego de variables: mostrarlas todas en
// cada card llevaba a escribir plantillas con placeholders que nunca se
// reemplazan (era el caso de "Posts", que mezclaba crear y optimizar).
$prompt_types = [
    'product'  => [
        'label' => 'SEO de productos',
        'desc'  => 'Optimiza un producto WooCommerce existente.',
        'vars'  => [ 'nombre', 'descripcion', 'categoria', 'sku', 'precio', 'atributos', 'pais', 'idioma', 'contexto_sitio' ],
    ],
    'post_seo' => [
        'label' => 'SEO de posts existentes',
        'desc'  => 'Optimiza un post que ya existe. Solo genera campos SEO, no reescribe el contenido.',
        'vars'  => [ 'nombre', 'descripcion', 'categoria', 'pais', 'idioma', 'contexto_sitio' ],
    ],
    'post'     => [
        'label' => 'Crear post nuevo',
        'desc'  => 'Redacta un post completo desde cero (pestaña Blog → "Crear post con IA").',
        'vars'  => [ 'tema', 'tono', 'largo', 'pais', 'idioma', 'contexto_sitio' ],
    ],
    'improve'  => [
        'label' => 'Mejora de posts',
        'desc'  => 'Reescribe y mejora un post existente (pestaña Blog → "Mejorar post existente").',
        'vars'  => [ 'titulo', 'contenido', 'instrucciones', 'pais', 'idioma', 'contexto_sitio' ],
    ],
];

foreach ( $prompt_types as $type => $info ) {
    $prompt_types[ $type ]['prompt'] = \HUP\CamposSeoIA\Settings::prompt( $type );
}

// Prompts guardados que piden campos que esta instalación no rellena. Pasa al
// editarlos a mano, o al desactivar un addon que ampliaba el registro: la IA
// los devuelve y el Writer los descarta, así que se pagan tokens en balde.
$prompt_sync = \HUP\CamposSeoIA\Generator\PromptSync::audit();

require __DIR__ . '/partials/layout-open.php';
?>

<h1 class="hup-seo-page-title">Configuración</h1>

<?php if ( $saved ) : ?>
<div class="hup-seo-notice hup-seo-notice-ok">Configuración guardada correctamente.</div>
<?php endif; ?>

<!-- Tabs -->
<?php
/**
 * Pestañas de Configuración.
 *
 * Otros plugins pueden añadir las suyas con el filtro `hcsai_extend_admin_tabs`
 * y renderizar su contenido enganchándose a `hcsai_settings_tab_{id}`.
 *
 * @var array<string, string> $extra_tabs  id => etiqueta visible
 */
$extra_tabs = (array) apply_filters( 'hcsai_extend_admin_tabs', [] );
?>
<div class="hup-seo-tabs">
    <button class="hup-seo-tab active" data-tab="tab-api">API y Modelo</button>
    <button class="hup-seo-tab" data-tab="tab-prompts">Sitio y Prompts</button>
    <button class="hup-seo-tab" data-tab="tab-calc">Calculadora</button>
    <?php foreach ( $extra_tabs as $tab_id => $tab_label ) : ?>
    <button class="hup-seo-tab" data-tab="<?php echo esc_attr( 'tab-' . sanitize_key( $tab_id ) ); ?>"><?php echo esc_html( $tab_label ); ?></button>
    <?php endforeach; ?>
</div>

<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
    <?php wp_nonce_field( 'hcsai_save_settings' ); ?>
    <input type="hidden" name="action" value="hcsai_save_settings">

    <!-- Tab: API y Modelo -->
    <div class="hup-seo-tab-content active" id="tab-api">
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Proveedor de IA</h3></div>
            <div class="hup-seo-card-body">
                <div class="hup-seo-provider-selector">
                    <?php foreach ( $providers as $slug => $name ) : ?>
                    <label class="hup-seo-provider-option <?php echo ( $provider === $slug ) ? 'active' : ''; ?>">
                        <input type="radio" name="hcsai_provider" value="<?php echo esc_attr( $slug ); ?>"
                               <?php checked( $provider, $slug ); ?>>
                        <span class="hup-seo-provider-name"><?php echo esc_html( $name ); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <?php foreach ( $providers as $slug => $name ) :
            $has_key     = \HUP\CamposSeoIA\Settings::has_api_key( $slug );
            $conn        = \HUP\CamposSeoIA\Settings::connection_status( $slug );
            $unreadable  = \HUP\CamposSeoIA\Settings::api_key_unreadable( $slug );
            $model_for   = get_option( HCSAI_PREFIX . 'model_' . $slug, \HUP\CamposSeoIA\Settings::default_model( $slug ) );
            $model_list  = $models[ $slug ] ?? [];
        ?>
        <div class="hup-seo-card hup-seo-provider-block" data-provider="<?php echo esc_attr( $slug ); ?>"
             style="<?php echo ( $provider !== $slug ) ? 'display:none;' : ''; ?>">
            <div class="hup-seo-card-header"><h3><?php echo esc_html( $name ); ?> — API Key y Modelo</h3></div>
            <div class="hup-seo-card-body">
                <div class="hup-seo-form-row">
                    <label class="hup-seo-form-label">API Key</label>
                    <div class="hup-seo-form-field">
                        <div class="hup-seo-api-key-inline">
                            <input type="password" name="hcsai_api_key_<?php echo esc_attr( $slug ); ?>"
                                   class="hup-seo-input hup-seo-api-key-input"
                                   value=""
                                   placeholder="<?php echo $unreadable ? 'Vuelve a pegar tu API key' : ( $has_key ? 'API key guardada' : 'Ingresa tu API key' ); ?>"
                                   autocomplete="off">
                            <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-outline hup-seo-toggle-pw" title="Mostrar/ocultar">👁</button>
                            <?php if ( $unreadable ) : ?>
                            <span class="hup-seo-badge hup-seo-badge-error">⚠ No se puede leer</span>
                            <?php elseif ( $has_key ) : ?>
                            <span class="hup-seo-badge hup-seo-badge-<?php echo esc_attr( 'ok' === $conn ? 'ok' : ( 'error' === $conn ? 'error' : 'unknown' ) ); ?>">
                                <?php echo esc_html( 'ok' === $conn ? '✓ Verificada' : ( 'error' === $conn ? '✗ Error' : '? Sin verificar' ) ); ?>
                            </span>
                            <?php endif; ?>
                            <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-primary hup-seo-test-api"
                                    data-provider="<?php echo esc_attr( $slug ); ?>">🔌 Verificar</button>
                            <span class="hup-seo-conn-result"></span>
                        </div>
                        <?php if ( $unreadable ) : ?>
                        <p class="hup-seo-help" style="color:var(--hup-seo-danger);">
                            Hay una API key guardada pero no se puede descifrar. Suele ocurrir al cambiar las claves de seguridad (<em>salts</em>) de <code>wp-config.php</code>, de las que se deriva el cifrado. Vuelve a pegar tu API key aquí y guarda para restablecerla.
                        </p>
                        <?php else : ?>
                        <p class="hup-seo-help"><?php echo $has_key ? 'Tu API key está guardada de forma segura (encriptada).' : 'La API key se almacena encriptada con AES-256-CBC.'; ?></p>
                        <?php endif; ?>

                        <?php if ( 'google' === $slug ) : ?>
                        <details class="hup-seo-api-instructions">
                            <summary>📖 ¿Cómo obtener una API key de Google Gemini?</summary>
                            <ol>
                                <li>Ve a <a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener">Google AI Studio → API Keys</a></li>
                                <li>Haz clic en <strong>"Crear clave de API"</strong></li>
                                <li>Selecciona un proyecto existente o crea uno nuevo</li>
                                <li>Copia la clave generada y pégala en el campo de arriba</li>
                                <li>Haz clic en <strong>"Verificar conexión"</strong> para confirmar que funciona</li>
                            </ol>
                            <p><strong>Nota:</strong> La key puede tardar 1-2 minutos en activarse después de crearla. Si ves error "API key expired" o "not valid", espera un momento y vuelve a intentar.</p>
                            <p>Plan gratuito: 15 solicitudes por minuto, suficiente para uso normal del plugin.</p>
                        </details>
                        <?php elseif ( 'openai' === $slug ) : ?>
                        <details class="hup-seo-api-instructions">
                            <summary>📖 ¿Cómo obtener una API key de OpenAI?</summary>
                            <ol>
                                <li>Ve a <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform → API Keys</a></li>
                                <li>Inicia sesión o crea una cuenta en OpenAI</li>
                                <li>Haz clic en <strong>"Create new secret key"</strong></li>
                                <li>Dale un nombre descriptivo (ej: "HUP SEO Plugin")</li>
                                <li>Copia la clave <strong>inmediatamente</strong> (no se vuelve a mostrar)</li>
                                <li>Pégala en el campo de arriba y verifica la conexión</li>
                            </ol>
                            <p><strong>Importante:</strong> OpenAI requiere créditos prepagados. Ve a <a href="https://platform.openai.com/settings/organization/billing" target="_blank" rel="noopener">Billing</a> para agregar fondos (mínimo $5 USD).</p>
                            <p>El modelo GPT-4o Mini es el más económico (~$0.60 USD por millón de tokens de salida).</p>
                        </details>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hup-seo-form-row">
                    <label class="hup-seo-form-label">Modelo</label>
                    <div class="hup-seo-form-field">
                        <select name="hcsai_model_<?php echo esc_attr( $slug ); ?>" class="hup-seo-select hup-seo-model-select"
                                data-provider="<?php echo esc_attr( $slug ); ?>">
                            <?php foreach ( $model_list as $m_slug => $m_name ) : ?>
                            <option value="<?php echo esc_attr( $m_slug ); ?>" <?php selected( $model_for, $m_slug ); ?>>
                                <?php echo esc_html( $m_name ); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- Declaración de servicios externos (obligatoria: el plugin envía contenido a APIs de IA) -->
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Datos enviados a servicios externos</h3></div>
            <div class="hup-seo-card-body">
                <p>Este plugin necesita un servicio externo de inteligencia artificial para generar contenido. Sin una API key configurada, las funciones de generación no operan.</p>

                <p><strong>Cuándo se envían datos:</strong> solo cuando ejecutas manualmente una acción del plugin (completar los campos de un contenido, crear un post, mejorar un post o verificar la conexión). No se envía nada de forma automática, programada ni desde el frontend del sitio.</p>

                <p><strong>Qué se envía:</strong> del contenido seleccionado, su título, contenido o descripción (recortada a 2.000 caracteres), categoría y —en productos WooCommerce— SKU, precio y atributos. Además, la descripción del negocio, el país y el idioma que defines en la pestaña "Sitio y Prompts". No se envían datos personales de usuarios, credenciales de WordPress ni datos de clientes o pedidos.</p>

                <p><strong>A qué servicios:</strong></p>
                <ul class="hup-seo-services-list">
                    <li>
                        <strong>OpenAI</strong> — <code>api.openai.com</code> ·
                        <a href="<?php echo esc_url( 'https://openai.com/policies/terms-of-use' ); ?>" target="_blank" rel="noopener">Términos de uso</a> ·
                        <a href="<?php echo esc_url( 'https://openai.com/policies/privacy-policy' ); ?>" target="_blank" rel="noopener">Política de privacidad</a>
                    </li>
                    <li>
                        <strong>Google Gemini</strong> — <code>generativelanguage.googleapis.com</code> ·
                        <a href="<?php echo esc_url( 'https://ai.google.dev/gemini-api/terms' ); ?>" target="_blank" rel="noopener">Términos de la API</a> ·
                        <a href="<?php echo esc_url( 'https://policies.google.com/privacy' ); ?>" target="_blank" rel="noopener">Política de privacidad</a>
                    </li>
                </ul>

                <p class="hup-seo-help">Las API keys las proporcionas tú y se registran en tu propia cuenta con cada proveedor: no vienen embebidas en el plugin. Se guardan encriptadas con AES-256-CBC en tu base de datos. El plugin no envía datos a servidores propios ni a terceros distintos del proveedor que selecciones, y el costo del consumo de la API corre por tu cuenta.</p>
            </div>
        </div>

        <!-- Retención del log de generaciones -->
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Log de generaciones</h3></div>
            <div class="hup-seo-card-body">
                <p>Cada generación deja una fila en el log con sus tokens y su costo. Un evento diario borra las entradas más antiguas para que la tabla no crezca sin límite.</p>
                <div class="hup-seo-form-row">
                    <label class="hup-seo-form-label">Conservar durante</label>
                    <div class="hup-seo-form-field hup-seo-inline">
                        <input type="number" name="hcsai_log_retention_days" class="hup-seo-input"
                               value="<?php echo esc_attr( $log_retention ); ?>" min="0" step="1" style="max-width:120px;">
                        <span>días</span>
                    </div>
                    <p class="hup-seo-help">0 = conservar el historial completo. Al purgar, las estadísticas acumuladas del dashboard (generaciones, tokens y costo totales) se conservan: solo se descarta el detalle fila a fila.</p>
                </div>
            </div>
        </div>

        <!-- Desinstalación: consentimiento explícito antes de borrar las API keys -->
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Desinstalación</h3></div>
            <div class="hup-seo-card-body">
                <p>Al desinstalar el plugin se eliminan las opciones de configuración, la tabla de log, la caché temporal y los metadatos generados. Las API keys se conservan por defecto, para que una reinstalación no te obligue a volver a pegarlas.</p>
                <label class="hup-seo-switch-label">
                    <input type="checkbox" name="hcsai_delete_api_keys" value="1" <?php checked( $delete_keys ); ?>>
                    <span>Eliminar también las API keys al desinstalar</span>
                </label>
                <p class="hup-seo-help">Esta acción es irreversible: si marcas la casilla, al desinstalar tendrás que volver a generar o recuperar tus API keys desde el panel de cada proveedor.</p>
            </div>
        </div>
    </div>

    <!-- Tab: Sitio y Prompts -->
    <div class="hup-seo-tab-content" id="tab-prompts">
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Contexto del sitio</h3></div>
            <div class="hup-seo-card-body">
                <div class="hup-seo-form-row">
                    <label class="hup-seo-form-label">Descripción del negocio</label>
                    <div class="hup-seo-form-field">
                        <textarea name="hcsai_site_context" class="hup-seo-textarea" rows="3"
                                  placeholder="Ej: Tienda online de ropa deportiva en Chile..."><?php echo esc_textarea( $site_context ); ?></textarea>
                    </div>
                </div>
                <div class="hup-seo-form-row hup-seo-form-row-3col">
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">País</label>
                        <select name="hcsai_country" class="hup-seo-select">
                            <?php foreach ( $countries as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">Idioma</label>
                        <select name="hcsai_language" class="hup-seo-select">
                            <?php foreach ( $languages as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $language, $code ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">Tasa USD a CLP</label>
                        <input type="number" name="hcsai_usd_to_clp" class="hup-seo-input" value="<?php echo esc_attr( $usd_to_clp ); ?>" min="1" step="1">
                    </div>
                </div>
                <p class="hup-seo-help">Valor del dólar para calcular costos en pesos chilenos.</p>
            </div>
        </div>

        <?php foreach ( $prompt_types as $type => $info ) : ?>
        <div class="hup-seo-card">
            <div class="hup-seo-card-header">
                <h3>Prompt: <?php echo esc_html( $info['label'] ); ?></h3>
                <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-outline hup-seo-reset-prompt"
                        data-type="<?php echo esc_attr( $type ); ?>">Restaurar</button>
            </div>
            <div class="hup-seo-card-body">
                <p class="hup-seo-help" style="margin-bottom:8px;"><?php echo esc_html( $info['desc'] ); ?></p>

                <?php if ( isset( $prompt_sync[ $type ] ) ) : ?>
                <div class="hup-seo-notice hup-seo-notice-warning hup-seo-prompt-sync">
                    <strong>Este prompt pide campos que no se rellenan aquí</strong>
                    <p>
                        Sobran: <code><?php echo esc_html( implode( ', ', $prompt_sync[ $type ]['extra'] ) ); ?></code>.
                        La IA los va a devolver y se descartarán al guardar, así que se pagan tokens
                        por texto que no se usa, en cada generación.
                    </p>
                    <details>
                        <summary>Ver cómo quedaría el prompt</summary>
                        <textarea class="hup-seo-textarea hup-seo-prompt-sync__preview" rows="8" readonly><?php echo esc_textarea( $prompt_sync[ $type ]['limpio'] ); ?></textarea>
                    </details>
                    <button type="button" class="hup-seo-btn hup-seo-btn-sm hup-seo-btn-primary hup-seo-prompt-sync__apply"
                            data-type="<?php echo esc_attr( $type ); ?>">Quitar esos campos</button>
                </div>
                <?php endif; ?>
                <textarea name="hcsai_prompt_<?php echo esc_attr( $type ); ?>" class="hup-seo-textarea hup-seo-prompt-textarea"
                          rows="8" id="hup-seo-prompt-<?php echo esc_attr( $type ); ?>"><?php echo esc_textarea( $info['prompt'] ); ?></textarea>
                <p class="hup-seo-help">
                    Variables disponibles en este prompt:
                    <?php foreach ( $info['vars'] as $i => $var ) : ?>
                        <?php echo ( $i > 0 ) ? ', ' : ''; ?><code><?php echo esc_html( '{{' . $var . '}}' ); ?></code>
                    <?php endforeach; ?>
                    — cualquier otra variable se enviará a la IA sin reemplazar.
                </p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Tab: Calculadora -->
    <div class="hup-seo-tab-content" id="tab-calc">
        <div class="hup-seo-card">
            <div class="hup-seo-card-header"><h3>Calculadora de costos</h3></div>
            <div class="hup-seo-card-body">
                <div class="hup-seo-form-row hup-seo-form-row-3col">
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">Modelo</label>
                        <select id="calc-model" class="hup-seo-select">
                            <?php foreach ( $models as $prov_slug => $prov_models ) : ?>
                            <optgroup label="<?php echo esc_attr( $providers[ $prov_slug ] ?? $prov_slug ); ?>">
                                <?php foreach ( $prov_models as $m_slug => $m_name ) : ?>
                                <option value="<?php echo esc_attr( $m_slug ); ?>"><?php echo esc_html( $m_name ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">Nº de tareas</label>
                        <input type="number" id="calc-tasks" class="hup-seo-input" value="100" min="1">
                    </div>
                    <div class="hup-seo-form-col">
                        <label class="hup-seo-form-label">Tasa USD a CLP</label>
                        <input type="number" id="calc-clp" class="hup-seo-input" value="<?php echo esc_attr( $usd_to_clp ); ?>" min="1" step="1">
                    </div>
                </div>
                <div class="hup-seo-calc-result" style="margin:20px 0; padding:16px; background:#f8f9fa; border-radius:8px;">
                    <div style="display:flex; gap:40px; font-size:16px;">
                        <span>Costo estimado (USD): <strong id="calc-result-usd" style="color:var(--hup-seo-primary);">$0.00</strong></span>
                        <span>Costo estimado (CLP): <strong id="calc-result-clp" style="color:var(--hup-seo-success);">$0</strong></span>
                    </div>
                </div>

                <!-- Tabla de costos referenciales -->
                <div class="hup-seo-calc-table-wrap" style="margin-top:24px;">
                    <h4 style="margin:0 0 12px 0; font-size:14px; color:var(--hup-seo-text);">Costos referenciales por volumen (CLP)</h4>
                    <table class="hup-seo-table hup-seo-calc-ref-table">
                        <thead>
                            <tr>
                                <th>Tareas</th>
                                <th>100</th>
                                <th>250</th>
                                <th>500</th>
                                <th>750</th>
                                <th>1000</th>
                            </tr>
                        </thead>
                        <tbody id="calc-ref-body">
                            <!-- Se llena con JS -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <?php
    /**
     * Contenido de las pestañas añadidas por otros plugins. Van dentro del
     * mismo formulario, así que sus campos viajan con el resto de la
     * configuración y se guardan enganchándose a `admin_post_hcsai_save_settings`.
     */
    foreach ( $extra_tabs as $tab_id => $tab_label ) :
        $tab_id = sanitize_key( $tab_id );
        ?>
        <div class="hup-seo-tab-content" id="<?php echo esc_attr( 'tab-' . $tab_id ); ?>">
            <?php do_action( 'hcsai_settings_tab_' . $tab_id ); ?>
        </div>
    <?php endforeach; ?>

    <div class="hup-seo-form-actions">
        <button type="submit" class="hup-seo-btn hup-seo-btn-primary hup-seo-btn-lg">Guardar configuración</button>
    </div>
</form>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
