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
$profile      = \HUP\CamposSeoIA\Settings::business_profile();
$country      = \HUP\CamposSeoIA\Settings::country();
$language     = \HUP\CamposSeoIA\Settings::language();
$usd_to_clp   = \HUP\CamposSeoIA\Settings::usd_to_clp();
$delete_keys  = '1' === (string) \HUP\CamposSeoIA\Settings::get( 'delete_api_keys', '0' );
$overwrite    = \HUP\CamposSeoIA\Settings::overwrite();
$log_retention = (int) \HUP\CamposSeoIA\Settings::get( 'log_retention_days', \HUP\CamposSeoIA\Logger::DEFAULT_RETENTION_DAYS );

$countries = [ 'CL' => 'Chile', 'MX' => 'México', 'AR' => 'Argentina', 'CO' => 'Colombia', 'PE' => 'Perú', 'ES' => 'España', 'US' => 'Estados Unidos' ];
$languages = [ 'es' => 'Español', 'en' => 'Inglés', 'pt' => 'Portugués' ];

// Cada prompt recibe solo su propio juego de variables: mostrarlas todas en
// cada card llevaba a escribir plantillas con placeholders que nunca se
// reemplazan (era el caso de "Posts", que mezclaba crear y optimizar).
$prompt_types = [
    'product'  => [
        'label' => 'SEO de productos',
        'desc'  => 'Optimiza un producto WooCommerce existente.',
        'vars'  => [ 'nombre', 'descripcion', 'categoria', 'sku', 'precio', 'atributos', 'pais', 'idioma', 'contexto_sitio', 'rubro', 'alcance', 'zona', 'publico' ],
    ],
    'post_seo' => [
        'label' => 'SEO de posts existentes',
        'desc'  => 'Optimiza un post que ya existe. Solo genera campos SEO, no reescribe el contenido.',
        'vars'  => [ 'nombre', 'descripcion', 'categoria', 'pais', 'idioma', 'contexto_sitio', 'rubro', 'alcance', 'zona', 'publico' ],
    ],
    'post'     => [
        'label' => 'Crear post nuevo',
        'desc'  => 'Redacta un post completo desde cero (pestaña Blog → "Crear post con IA").',
        'vars'  => [ 'tema', 'tono', 'largo', 'pais', 'idioma', 'contexto_sitio', 'rubro', 'alcance', 'zona', 'publico' ],
    ],
    'improve'  => [
        'label' => 'Mejora de posts',
        'desc'  => 'Reescribe y mejora un post existente (pestaña Blog → "Mejorar post existente").',
        'vars'  => [ 'titulo', 'contenido', 'instrucciones', 'pais', 'idioma', 'contexto_sitio', 'rubro', 'alcance', 'zona', 'publico' ],
    ],
];

foreach ( $prompt_types as $type => $info ) {
    $prompt_types[ $type ]['prompt'] = \HUP\CamposSeoIA\Settings::prompt( $type );
}

// Prompts guardados que piden campos que esta instalación no rellena. Pasa al
// editarlos a mano, o al desactivar un addon que ampliaba el registro: la IA
// los devuelve y el Writer los descarta, así que se pagan tokens en balde.
$prompt_sync = \HUP\CamposSeoIA\Generator\PromptSync::audit();

if ( ! function_exists( 'hcsai_settings_form_fields' ) ) {
    function hcsai_settings_form_fields( string $section, string $panel ): void {
        wp_nonce_field( 'hcsai_save_settings' );
        echo '<input type="hidden" name="action" value="hcsai_save_settings">';
        echo '<input type="hidden" name="hcsai_section" value="' . esc_attr( $section ) . '">';
        echo '<input type="hidden" name="hcsai_panel" value="' . esc_attr( $panel ) . '">';
    }
}

if ( ! function_exists( 'hcsai_settings_card_foot' ) ) {
    function hcsai_settings_card_foot( string $section, string $label ): void {
        $updated = isset( $_GET['updated'] ) ? sanitize_key( wp_unslash( $_GET['updated'] ) ) : '';
        echo '<div class="hup-card__foot"><button type="submit" class="hup-btn"><span class="dashicons dashicons-yes" aria-hidden="true"></span>' . esc_html( $label ) . '</button>';
        if ( $section === $updated ) {
            echo '<span class="hup-save-status"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>Cambios guardados</span>';
        }
        echo '</div>';
    }
}

require __DIR__ . '/partials/layout-open.php';
?>

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
$hcsai_panels = array_merge( [ 'api' => 'API y Modelo', 'prompts' => 'Sitio y Prompts', 'calc' => 'Calculadora' ], $extra_tabs );
$hcsai_panel = isset( $_GET['panel'] ) ? sanitize_key( wp_unslash( $_GET['panel'] ) ) : 'api';
if ( ! isset( $hcsai_panels[ $hcsai_panel ] ) ) {
    $hcsai_panel = 'api';
}
?>
<div class="hup-tabs" aria-label="Opciones de configuración">
    <?php foreach ( $hcsai_panels as $tab_id => $tab_label ) : ?>
    <a href="<?php echo esc_url( add_query_arg( [ 'page' => 'hcsai', 'tab' => 'configuracion', 'panel' => sanitize_key( $tab_id ) ], admin_url( 'admin.php' ) ) ); ?>" class="<?php echo $hcsai_panel === $tab_id ? 'is-active' : ''; ?>"<?php echo $hcsai_panel === $tab_id ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $tab_label ); ?></a>
    <?php endforeach; ?>
</div>

    <!-- Tab: API y Modelo -->
    <div class="hcsai-panel" id="tab-api"<?php echo 'api' !== $hcsai_panel ? ' hidden' : ''; ?>>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'provider', 'api' ); ?>
            <div class="hup-card__head"><h3 class="hup-card__title">Proveedor de IA</h3></div>
            <div class="hup-card__body">
                <div class="hup-choices">
                    <?php foreach ( $providers as $slug => $name ) : ?>
                    <label class="hup-choice">
                        <input type="radio" name="hcsai_provider" value="<?php echo esc_attr( $slug ); ?>"
                               <?php checked( $provider, $slug ); ?>>
                        <span class="hup-choice__dot"></span><span class="hup-choice__title"><?php echo esc_html( $name ); ?></span>
                    </label>
                    <?php endforeach; ?>
                </div>
            </div>
                    <?php hcsai_settings_card_foot( 'provider', 'Guardar proveedor' ); ?>
</form>

        <?php foreach ( $providers as $slug => $name ) :
            $has_key     = \HUP\CamposSeoIA\Settings::has_api_key( $slug );
            $conn        = \HUP\CamposSeoIA\Settings::connection_status( $slug );
            $unreadable  = \HUP\CamposSeoIA\Settings::api_key_unreadable( $slug );
            $model_for   = get_option( HCSAI_PREFIX . 'model_' . $slug, \HUP\CamposSeoIA\Settings::default_model( $slug ) );
            $model_list  = $models[ $slug ] ?? [];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card hup-seo-provider-block" data-provider="<?php echo esc_attr( $slug ); ?>"
             <?php echo ( $provider !== $slug ) ? 'hidden' : ''; ?>>
            <?php hcsai_settings_form_fields( 'credentials_' . $slug, 'api' ); ?>
            <input type="hidden" name="hcsai_provider" value="<?php echo esc_attr( $slug ); ?>">
            <div class="hup-card__head"><h3 class="hup-card__title"><?php echo esc_html( $name ); ?> — API Key y Modelo</h3></div>
            <div class="hup-card__body">
                <div class="hup-field">
                    <label class="hup-label" for="hcsai-api-key-<?php echo esc_attr( $slug ); ?>">API Key</label>
                    <div class="hup-control">
                        <div class="hup-inline">
                            <input type="password" id="hcsai-api-key-<?php echo esc_attr( $slug ); ?>" name="hcsai_api_key_<?php echo esc_attr( $slug ); ?>"
                                   class="hup-input hup-input--mono hup-seo-api-key-input"
                                   value=""
                                   placeholder="<?php echo $unreadable ? 'Vuelve a pegar tu API key' : ( $has_key ? 'API key guardada' : 'Ingresa tu API key' ); ?>"
                                   autocomplete="off">
                            <button type="button" class="hup-btn hup-btn--icon" data-hup-reveal="#hcsai-api-key-<?php echo esc_attr( $slug ); ?>" aria-label="Mostrar u ocultar clave"><span class="dashicons dashicons-visibility" aria-hidden="true"></span></button>
                            <?php if ( $unreadable ) : ?>
                            <span class="hup-badge hup-badge--danger hup-badge--dot">No se puede leer</span>
                            <?php elseif ( $has_key ) : ?>
                            <span class="hup-badge hup-badge--dot hup-badge--<?php echo esc_attr( 'ok' === $conn ? 'success' : ( 'error' === $conn ? 'danger' : 'warning' ) ); ?>">
                                <?php echo esc_html( 'ok' === $conn ? 'Verificada' : ( 'error' === $conn ? 'Error' : 'Sin verificar' ) ); ?>
                            </span>
                            <?php else : ?>
                            <span class="hup-badge hup-badge--warning hup-badge--dot">Sin configurar</span>
                            <?php endif; ?>
                            <button type="button" class="hup-btn hup-btn--secondary hup-seo-test-api"
                                    data-provider="<?php echo esc_attr( $slug ); ?>"><span class="dashicons dashicons-admin-network" aria-hidden="true"></span>Probar conexión</button>
                            <span class="hup-seo-conn-result"></span>
                        </div>
                        <?php if ( $unreadable ) : ?>
                        <p class="hup-error">
                            Hay una API key guardada pero no se puede descifrar. Suele ocurrir al cambiar las claves de seguridad (<em>salts</em>) de <code>wp-config.php</code>, de las que se deriva el cifrado. Vuelve a pegar tu API key aquí y guarda para restablecerla.
                        </p>
                        <?php else : ?>
                        <p class="hup-hint"><?php echo $has_key ? 'Tu API key está guardada de forma segura (encriptada).' : 'La API key se almacena encriptada con AES-256-CBC.'; ?></p>
                        <?php endif; ?>

                        <?php if ( 'google' === $slug ) : ?>
                        <details class="hup-accordion">
                            <summary>¿Cómo obtener una API key de Google Gemini?</summary>
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
                        <details class="hup-accordion">
                            <summary>¿Cómo obtener una API key de OpenAI?</summary>
                            <ol>
                                <li>Ve a <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener">OpenAI Platform → API Keys</a></li>
                                <li>Inicia sesión o crea una cuenta en OpenAI</li>
                                <li>Haz clic en <strong>"Create new secret key"</strong></li>
                                <li>Dale un nombre descriptivo (ej: "HUP SEO Plugin")</li>
                                <li>Copia la clave <strong>inmediatamente</strong> (no se vuelve a mostrar)</li>
                                <li>Pégala en el campo de arriba y verifica la conexión</li>
                            </ol>
                            <p><strong>Importante:</strong> OpenAI requiere créditos prepagados. Ve a <a href="https://platform.openai.com/settings/organization/billing" target="_blank" rel="noopener">Billing</a> para agregar fondos (mínimo $5 USD).</p>
                            <p>El modelo GPT-6 Luna es el más económico (~$0.50 USD por millón de tokens de salida).</p>
                        </details>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hup-field">
                    <label class="hup-label" for="hcsai-model-<?php echo esc_attr( $slug ); ?>">Modelo</label>
                    <div class="hup-control">
                        <select id="hcsai-model-<?php echo esc_attr( $slug ); ?>" name="hcsai_model_<?php echo esc_attr( $slug ); ?>" class="hup-select hup-seo-model-select"
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
                    <?php hcsai_settings_card_foot( 'credentials_' . $slug, 'Guardar clave y modelo' ); ?>
</form>
        <?php endforeach; ?>

        <!-- Declaración de servicios externos (obligatoria: el plugin envía contenido a APIs de IA) -->
        <div class="hup-card">
            <div class="hup-card__head"><h3 class="hup-card__title">Datos enviados a servicios externos</h3></div>
            <div class="hup-card__body">
                <p>Este plugin necesita un servicio externo de inteligencia artificial para generar contenido. Sin una API key configurada, las funciones de generación no operan.</p>

                <p><strong>Cuándo se envían datos:</strong> solo cuando ejecutas manualmente una acción del plugin (completar los campos de un contenido, crear un post, mejorar un post o verificar la conexión). No se envía nada de forma automática, programada ni desde el frontend del sitio.</p>

                <p><strong>Qué se envía:</strong> del contenido seleccionado, su título, el texto de su contenido o descripción (hasta 2.000 caracteres al completar campos, y hasta 12.000 al mejorar un post), categoría y —en productos WooCommerce— SKU, precio y atributos. Además, el perfil del negocio que defines en la pestaña "Sitio y Prompts" (tipo de sitio, rubro, descripción, público, diferenciadores, alcance, zona y modalidad), el país y el idioma. No se envían datos personales de usuarios, credenciales de WordPress ni datos de clientes o pedidos.</p>

                <p><strong>Almacenamiento en el proveedor:</strong> cada petición se envía pidiendo que no se guarde (<code>store: false</code>), también en Google Gemini, que desde octubre de 2026 guarda por defecto las peticiones de los proyectos de pago nuevos. Lo que cada proveedor conserve por su cuenta (por ejemplo, para vigilar abusos) se rige por sus términos.</p>

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

                <p class="hup-hint">Las API keys las proporcionas tú y se registran en tu propia cuenta con cada proveedor: no vienen embebidas en el plugin. Se guardan encriptadas con AES-256-CBC en tu base de datos. El plugin no envía datos a servidores propios ni a terceros distintos del proveedor que selecciones, y el costo del consumo de la API corre por tu cuenta.</p>
            </div>
        </div>

        <!-- Retención del log de generaciones -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'log', 'api' ); ?>
            <div class="hup-card__head"><h3 class="hup-card__title">Log de generaciones</h3></div>
            <div class="hup-card__body">
                <p>Cada generación deja una fila en el log con sus tokens y su costo. Un evento diario borra las entradas más antiguas para que la tabla no crezca sin límite.</p>
                <div class="hup-field">
                    <label class="hup-label" for="hcsai-log-retention-days">Conservar durante</label>
                    <div class="hup-control">
                        <div class="hup-inline">
                            <input type="number" id="hcsai-log-retention-days" name="hcsai_log_retention_days" class="hup-input hup-input--sm"
                                   value="<?php echo esc_attr( $log_retention ); ?>" min="0" step="1">
                            <span class="hup-unit">días</span>
                        </div>
                        <p class="hup-hint">0 = conservar el historial completo. Al purgar, las estadísticas acumuladas del dashboard (generaciones, tokens y costo totales) se conservan: solo se descarta el detalle fila a fila.</p>
                    </div>
                </div>
            </div>
                    <?php hcsai_settings_card_foot( 'log', 'Guardar duración' ); ?>
</form>

        <!-- Desinstalación: consentimiento explícito antes de borrar las API keys -->
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'uninstall', 'api' ); ?>
            <div class="hup-card__head"><h3 class="hup-card__title">Desinstalación</h3></div>
            <div class="hup-card__body">
                <p>Al desinstalar el plugin se eliminan las opciones de configuración, la tabla de log, la caché temporal y los metadatos generados. Las API keys se conservan por defecto, para que una reinstalación no te obligue a volver a pegarlas.</p>
                <label class="hup-toggle">
                    <input type="checkbox" name="hcsai_delete_api_keys" value="1" <?php checked( $delete_keys ); ?>>
                    <span class="hup-toggle__track"></span>Eliminar también las API keys al desinstalar
                </label>
                <p class="hup-hint">Esta acción es irreversible: si marcas la casilla, al desinstalar tendrás que volver a generar o recuperar tus API keys desde el panel de cada proveedor.</p>
            </div>
                    <?php hcsai_settings_card_foot( 'uninstall', 'Guardar preferencia' ); ?>
</form>
    </div>

    <!-- Tab: Sitio y Prompts -->
    <div class="hcsai-panel" id="tab-prompts"<?php echo 'prompts' !== $hcsai_panel ? ' hidden' : ''; ?>>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'profile', 'prompts' ); ?>
            <div class="hup-card__head"><h3 class="hup-card__title">Perfil del negocio</h3></div>
            <div class="hup-card__body">
                <p class="hup-hint">Todo es opcional. Estos datos orientan el contenido que genera la IA.</p>

                <div class="hup-grid hup-grid--3">
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai_tipo_sitio">Tipo de sitio</label>
                        <select name="hcsai_tipo_sitio" id="hcsai_tipo_sitio" class="hup-select">
                            <option value="">Sin especificar</option>
                            <?php foreach ( \HUP\CamposSeoIA\Settings::SITE_TYPES as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['tipo'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai_rubro">Rubro o temática</label>
                        <input type="text" name="hcsai_rubro" id="hcsai_rubro" class="hup-input"
                               maxlength="<?php echo esc_attr( \HUP\CamposSeoIA\Settings::PROFILE_LIMITS['rubro'] ); ?>"
                               value="<?php echo esc_attr( $profile['rubro'] ); ?>" placeholder="Ej: ropa deportiva, clínica dental, finanzas personales">
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai_modalidad">Cómo se entrega</label>
                        <select name="hcsai_modalidad" id="hcsai_modalidad" class="hup-select">
                            <option value="">Sin especificar</option>
                            <?php foreach ( \HUP\CamposSeoIA\Settings::DELIVERY as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['modalidad'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="hup-field hup-field--stacked">
                    <label class="hup-label" for="hcsai_site_context">A qué se dedica</label>
                    <div class="hup-control">
                        <textarea name="hcsai_site_context" id="hcsai_site_context" class="hup-input" rows="3"
                                  placeholder="Ej: Vendemos ropa deportiva para mujer, con tallas grandes y diseños propios."><?php echo esc_textarea( $site_context ); ?></textarea>
                        <p class="hup-hint">Qué vende u ofrece, o de qué escribe el blog. Mejor sin ubicación: eso va en «Alcance».</p>
                    </div>
                </div>

                <div class="hup-grid hup-grid--3">
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai_publico">Público objetivo</label>
                        <textarea name="hcsai_publico" id="hcsai_publico" class="hup-input" rows="2"
                                  maxlength="<?php echo esc_attr( \HUP\CamposSeoIA\Settings::PROFILE_LIMITS['publico'] ); ?>"
                                  placeholder="Ej: mujeres de 25 a 45 que entrenan en casa"><?php echo esc_textarea( $profile['publico'] ); ?></textarea>
                    </div>
                    <div class="hup-field hup-field--stacked hcsai-grid-span-2">
                        <label class="hup-label" for="hcsai_diferenciadores">Diferenciadores</label>
                        <textarea name="hcsai_diferenciadores" id="hcsai_diferenciadores" class="hup-input" rows="2"
                                  maxlength="<?php echo esc_attr( \HUP\CamposSeoIA\Settings::PROFILE_LIMITS['diferenciadores'] ); ?>"
                                  placeholder="Ej: envío gratis sobre $40.000, cambios sin costo, 10 años en el rubro"><?php echo esc_textarea( $profile['diferenciadores'] ); ?></textarea>
                        <p class="hup-hint">La IA solo menciona envíos, garantías o promociones si están aquí.</p>
                    </div>
                </div>

                <div class="hup-grid hup-grid--3">
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai_alcance">Alcance</label>
                        <select name="hcsai_alcance" id="hcsai_alcance" class="hup-select">
                            <option value="">Sin especificar</option>
                            <?php foreach ( \HUP\CamposSeoIA\Settings::SCOPES as $key => $label ) : ?>
                            <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $profile['alcance'], $key ); ?>><?php echo esc_html( $label ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-field hup-field--stacked hcsai-grid-span-2">
                        <label class="hup-label" for="hcsai_zona">Ciudad, comuna o región</label>
                        <input type="text" name="hcsai_zona" id="hcsai_zona" class="hup-input"
                               maxlength="<?php echo esc_attr( \HUP\CamposSeoIA\Settings::PROFILE_LIMITS['zona'] ); ?>"
                               value="<?php echo esc_attr( $profile['zona'] ); ?>" placeholder="Ej: Concepción">
                        <p class="hup-hint">Local o regional: se usa en títulos y palabras clave. Nacional: es la sede, y se le pide a la IA que NO la nombre. Internacional: no se usa.</p>
                    </div>
                </div>

                <div class="hup-grid hup-grid--3">
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai-country">País</label>
                        <select id="hcsai-country" name="hcsai_country" class="hup-select">
                            <?php foreach ( $countries as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $country, $code ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai-language">Idioma</label>
                        <select id="hcsai-language" name="hcsai_language" class="hup-select">
                            <?php foreach ( $languages as $code => $name ) : ?>
                            <option value="<?php echo esc_attr( $code ); ?>" <?php selected( $language, $code ); ?>><?php echo esc_html( $name ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="hcsai-usd-to-clp">Tasa USD a CLP</label>
                        <input type="number" id="hcsai-usd-to-clp" name="hcsai_usd_to_clp" class="hup-input" value="<?php echo esc_attr( $usd_to_clp ); ?>" min="1" step="1">
                    </div>
                </div>
                <p class="hup-hint">País: el mercado de la empresa. Idioma: el de los textos. Tasa USD a CLP: solo para mostrar costos en pesos chilenos.</p>

                <details class="hup-accordion">
                    <summary>Ver cómo le llega este perfil a la IA</summary>
                    <p>Es el texto que reemplaza a <code>{{contexto_sitio}}</code> en los prompts. Refleja lo guardado: después de cambiar algo, guarda para actualizarlo.</p>
                    <textarea class="hup-input hup-seo-prompt-sync__preview" rows="10" readonly><?php echo esc_textarea( \HUP\CamposSeoIA\Generator\PromptBuilder::business_context() ); ?></textarea>
                </details>
            </div>
                    <?php hcsai_settings_card_foot( 'profile', 'Guardar perfil' ); ?>
</form>

        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'overwrite', 'prompts' ); ?>
            <div class="hup-card__head"><h3 class="hup-card__title">Completar campos</h3></div>
            <div class="hup-card__body">
                <label class="hup-toggle">
                    <input type="checkbox" name="hcsai_overwrite" value="1" <?php checked( $overwrite ); ?>>
                    <span class="hup-toggle__track"></span>Sobrescribir también los campos que ya tienen contenido
                </label>
                <p class="hup-hint">Desactivado, «Completar» solo rellena lo que está vacío y no pide a la IA lo que ya está escrito. Activado, reemplaza también lo existente. Los productos de WooCommerce no guardan revisiones: una descripción reemplazada no se puede recuperar.</p>
            </div>
                    <?php hcsai_settings_card_foot( 'overwrite', 'Guardar preferencia' ); ?>
</form>

        <?php foreach ( $prompt_types as $type => $info ) : ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hup-card">
            <?php hcsai_settings_form_fields( 'prompt_' . $type, 'prompts' ); ?>
            <div class="hup-card__head">
                <h3 class="hup-card__title">Prompt: <?php echo esc_html( $info['label'] ); ?></h3>
            </div>
            <div class="hup-card__body">
                <p class="hup-hint"><?php echo esc_html( $info['desc'] ); ?></p>

                <?php if ( isset( $prompt_sync[ $type ] ) ) : ?>
                <div class="hup-notice hup-notice--warning hup-seo-prompt-sync">
                    <strong>Este prompt pide campos que no se rellenan aquí</strong>
                    <p>
                        Sobran: <code><?php echo esc_html( implode( ', ', $prompt_sync[ $type ]['extra'] ) ); ?></code>.
                        La IA los va a devolver y se descartarán al guardar, así que se pagan tokens
                        por texto que no se usa, en cada generación.
                    </p>
                    <details>
                        <summary>Ver cómo quedaría el prompt</summary>
                        <textarea class="hup-input hup-seo-prompt-sync__preview" rows="8" readonly><?php echo esc_textarea( $prompt_sync[ $type ]['limpio'] ); ?></textarea>
                    </details>
                    <button type="button" class="hup-btn hup-btn--sm  hup-seo-prompt-sync__apply"
                            data-type="<?php echo esc_attr( $type ); ?>">Quitar esos campos</button>
                </div>
                <?php endif; ?>
                <textarea name="hcsai_prompt_<?php echo esc_attr( $type ); ?>" class="hup-input hup-seo-prompt-textarea"
                          rows="8" id="hup-seo-prompt-<?php echo esc_attr( $type ); ?>"><?php echo esc_textarea( $info['prompt'] ); ?></textarea>
                <p class="hup-hint">
                    Variables disponibles en este prompt:
                    <?php foreach ( $info['vars'] as $i => $var ) : ?>
                        <?php echo ( $i > 0 ) ? ', ' : ''; ?><code><?php echo esc_html( '{{' . $var . '}}' ); ?></code>
                    <?php endforeach; ?>
                    — cualquier otra variable se enviará a la IA sin reemplazar.
                </p>
            </div>
                    <?php hcsai_settings_card_foot( 'prompt_' . $type, 'Guardar prompt' ); ?>
</form>
        <?php endforeach; ?>

        <form class="hup-card hup-card--danger" id="hcsai-reset-prompt-form">
            <div class="hup-card__head"><h3 class="hup-card__title">Restaurar un prompt</h3></div>
            <div class="hup-card__body">
                <div class="hup-field">
                    <label class="hup-label" for="hcsai-reset-prompt-type">Prompt</label>
                    <div class="hup-control">
                        <select id="hcsai-reset-prompt-type" class="hup-select">
                            <?php foreach ( $prompt_types as $type => $info ) : ?>
                            <option value="<?php echo esc_attr( $type ); ?>"><?php echo esc_html( $info['label'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="hup-hint">Se elimina la personalización guardada de inmediato y se usa la plantilla actual del plugin.</p>
                    </div>
                </div>
            </div>
            <div class="hup-card__foot"><button type="button" class="hup-btn hup-btn--danger-outline hup-seo-reset-prompt">Restaurar prompt elegido</button></div>
        </form>
    </div>

    <!-- Tab: Calculadora -->
    <div class="hcsai-panel" id="tab-calc"<?php echo 'calc' !== $hcsai_panel ? ' hidden' : ''; ?>>
        <div class="hup-card">
            <div class="hup-card__head"><h3 class="hup-card__title">Calculadora de costos</h3></div>
            <div class="hup-card__body">
                <div class="hup-grid hup-grid--3">
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="calc-model">Modelo</label>
                        <select id="calc-model" class="hup-select">
                            <?php foreach ( $models as $prov_slug => $prov_models ) : ?>
                            <optgroup label="<?php echo esc_attr( $providers[ $prov_slug ] ?? $prov_slug ); ?>">
                                <?php foreach ( $prov_models as $m_slug => $m_name ) : ?>
                                <option value="<?php echo esc_attr( $m_slug ); ?>"><?php echo esc_html( $m_name ); ?></option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="calc-tasks">Nº de tareas</label>
                        <input type="number" id="calc-tasks" class="hup-input" value="100" min="1">
                    </div>
                    <div class="hup-field hup-field--stacked">
                        <label class="hup-label" for="calc-clp">Tasa USD a CLP</label>
                        <input type="number" id="calc-clp" class="hup-input" value="<?php echo esc_attr( $usd_to_clp ); ?>" min="1" step="1">
                    </div>
                </div>
                <div class="hup-status-row">
                    <div><span>Costo estimado (USD)</span><strong id="calc-result-usd">$0,0000</strong></div>
                    <div><span>Costo estimado (CLP)</span><strong id="calc-result-clp">$0</strong></div>
                </div>

                <!-- Tabla de costos referenciales -->
                <div class="hcsai-table-scroll">
                    <h4 class="hup-card__title">Costos referenciales por volumen (CLP)</h4>
                    <table class="hup-table hup-seo-calc-ref-table">
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
    /** Mantiene el contrato de guardado de las pestañas añadidas por addons. */
    foreach ( $extra_tabs as $tab_id => $tab_label ) :
        $tab_id = sanitize_key( $tab_id );
        ?>
        <div class="hcsai-panel" id="<?php echo esc_attr( 'tab-' . $tab_id ); ?>"<?php echo $tab_id !== $hcsai_panel ? ' hidden' : ''; ?>>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php hcsai_settings_form_fields( 'addon_' . $tab_id, $tab_id ); ?>
            <?php do_action( 'hcsai_settings_tab_' . $tab_id ); ?>
                <?php hcsai_settings_card_foot( 'addon_' . $tab_id, 'Guardar cambios' ); ?>
            </form>
        </div>
    <?php endforeach; ?>

<?php require __DIR__ . '/partials/layout-close.php'; ?>
