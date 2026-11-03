<?php
class UVBConnectorWooCommerce_Settings
{

    private $options;

    public function __construct()
    {
        $this->options = get_option( 'uvb_connector_woocommerce_options' );
        if (!is_array($this->options)) {
            $this->options = [];
        }
    }

    public function addPluginPage()
    {

        add_options_page(
            __('ProteCOD', 'uvb-connector-woocommerce'),
            __('ProteCOD', 'uvb-connector-woocommerce'),
            'manage_options',
            'uvb-connector-woocommerce.php',
            array( $this, 'createAdminPage' )
        );
    }

    public function createAdminPage()
    {

        $this->options = get_option( 'uvb_connector_woocommerce_options' );
        if (!is_array($this->options)) {
            $this->options = [];
        }
        ?>
        <div class="wrap">
            <h1><?php _e('ProteCOD Settings', 'uvb-connector-woocommerce'); ?></h1>
            <form method="post" action="options.php">
                <?php

                    settings_fields( 'uvb_connector_woocommerce_group' );
                    do_settings_sections( 'uvb-connector-woocommerce-admin' );
                    submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    public function pageInit()
    {
        register_setting(
            'uvb_connector_woocommerce_group', // Option group
            'uvb_connector_woocommerce_options', // Option name
            array( $this, 'sanitize' ) // Sanitize
        );

        add_settings_section(
            'uvb_connector_woocommerce_section_id', // ID
            __('API settings', 'uvb-connector-woocommerce'), // Title
            array( $this, 'printSectionInfo' ), // Callback
            'uvb-connector-woocommerce-admin' // Page
        );

        add_settings_field(
            'sandbox_mode',
            __('Sandbox mode', 'uvb-connector-woocommerce'),
            array( $this, 'sandboxModeCallback' ),
            'uvb-connector-woocommerce-admin',
            'uvb_connector_woocommerce_section_id'
        );

        add_settings_field(
            'public_api_key', // ID
            __('Public API Key', 'uvb-connector-woocommerce'), // Title
            array( $this, 'publicApiKeyCallback' ), // Callback
            'uvb-connector-woocommerce-admin', // Page
            'uvb_connector_woocommerce_section_id' // Section
        );

        add_settings_field(
            'private_api_key',
            __('Private API Key', 'uvb-connector-woocommerce'),
            array( $this, 'privateApiKeyCallback' ),
            'uvb-connector-woocommerce-admin',
            'uvb_connector_woocommerce_section_id'
        );

        add_settings_field(
            'reputation_threshold',
            __('Reputation threshold', 'uvb-connector-woocommerce'),
            array( $this, 'reputationThresholdCallback' ),
            'uvb-connector-woocommerce-admin',
            'uvb_connector_woocommerce_section_id'
        );

        add_settings_field(
            'payment_methods_to_hide',
            __('Payment methods to hide', 'uvb-connector-woocommerce'),
            array( $this, 'paymentMethodsToHideCallback' ),
            'uvb-connector-woocommerce-admin',
            'uvb_connector_woocommerce_section_id'
        );

        add_settings_field(
            'fallback_payment_methods',
            __('Fallback payment methods', 'uvb-connector-woocommerce'),
            array( $this, 'fallbackPaymentMethodsCallback' ),
            'uvb-connector-woocommerce-admin',
            'uvb_connector_woocommerce_section_id'
        );
    }

    public function sanitize( $input )
    {
        $input_array = array();

        if( isset( $input['sandbox_mode'] ) )
            $input_array['sandbox_mode'] = (bool) $input['sandbox_mode'];

        if( isset( $input['public_api_key'] ) )
            $input_array['public_api_key'] = (string) sanitize_text_field($input['public_api_key']);

        if( isset( $input['private_api_key'] ) )
            $input_array['private_api_key'] = (string) sanitize_text_field($input['private_api_key']);

        if( isset( $input['reputation_threshold'] ) )
            $input_array['reputation_threshold'] = (float) $input['reputation_threshold'];

        if( isset( $input['payment_methods_to_hide'] ) )
            $input_array['payment_methods_to_hide'] = $input['payment_methods_to_hide'] ?? [];

        if( isset( $input['fallback_payment_methods'] ) )
            $input_array['fallback_payment_methods'] = $input['fallback_payment_methods'] ?? [];

        return $input_array;
    }

    public function printSectionInfo()
    {
        _e('Enter your API keys and set your preferences. ', 'uvb-connector-woocommerce');
    }

    public function sandboxModeCallback()
    {
        $sandboxMode = isset($this->options['sandbox_mode']) ? $this->options['sandbox_mode'] : false;

        if ($sandboxMode) {
            printf('<input type="checkbox" id="sandbox_mode" name="uvb_connector_woocommerce_options[sandbox_mode]" checked />');
        } else {
            printf('<input type="checkbox" id="sandbox_mode" name="uvb_connector_woocommerce_options[sandbox_mode]" />');
        }

        _e('<p><em>Depending on this setting the plugin will use the production or sandbox environment of ProteCOD. <strong>Please make sure this is set up correctly.</strong></em></p>', 'uvb-connector-woocommerce');

        if ($sandboxMode) {
            _e('<p><span style="background: linear-gradient(to bottom right, #d20087, #79098f, #0c024a); color: white; font-weight: bold; display: inline-block; padding: 0.25rem 0.75rem; border-radius: 4px;">SANDBOX</span></p>');
        }
    }

    public function publicApiKeyCallback()
    {
        printf(
            '<input type="text" id="public_api_key" name="uvb_connector_woocommerce_options[public_api_key]" value="%s" />',
            isset( $this->options['public_api_key'] ) ? esc_attr( $this->options['public_api_key']) : ''
        );
    }

    public function privateApiKeyCallback()
    {
        printf(
            '<input type="text" id="private_api_key" name="uvb_connector_woocommerce_options[private_api_key]" value="%s" />',
            isset( $this->options['private_api_key'] ) ? esc_attr( $this->options['private_api_key']) : ''
        );
    }

    public function reputationThresholdCallback()
    {
        printf(
            '<input type="number" max="1" step="0.0001" id="reputation_threshold" name="uvb_connector_woocommerce_options[reputation_threshold]" value="%s" />',
            isset( $this->options['reputation_threshold'] ) ? esc_attr( $this->options['reputation_threshold']) : ''
        );

        _e('<p><em>Calculated with the following formula: <code>(good-bad) / all</code>, so a 0.5 reputation can mean 6 successful and 2 rejected deliveries.</em></p>', 'uvb-connector-woocommerce');
    }

    public function paymentMethodsToHideCallback()
    {
        $this->renderPaymentMethods(
            'payment_methods_to_hide',
            __('These payment methods are hidden if the customer has failed the check.', 'uvb-connector-woocommerce')
        );
    }

    public function fallbackPaymentMethodsCallback()
    {
        $this->renderPaymentMethods(
            'fallback_payment_methods',
            __('These payment methods are shown only if the customer has failed the check.', 'uvb-connector-woocommerce')
        );
    }

    private function renderPaymentMethods(string $optionName, string $description): void
    {
        $values = $this->options[$optionName] ?? [];
        $options = WC()->payment_gateways()->payment_gateways() ?? [];

        if (!count($options)) {
            echo 'No payment methods installed yet.';
            return;
        }

        $html = '<fieldset>';

        foreach ($options as $option) {
            $for = $optionName . '_' . $option->id;
            $escapedFor = esc_attr($for);
            $escapedName = esc_attr('uvb_connector_woocommerce_options[' . $optionName . '][]');
            $escapedId = esc_attr($option->id);
            $escapedTitle = esc_html($option->title);
            $html .= "<label for='$escapedFor'>";

            $html .= "<input 
              name='$escapedName'
              id='$escapedFor'
              type='checkbox' 
              value='$escapedId'";

            if (in_array($option->id, $values)) {
                $html .= "checked";
            }

            $html .= "/>";

            $html .= $escapedTitle;
            $html .= "</label><br/>";
        }

        $html .= '<p><em>' . $description . '</em></p>';
        $html .= '</fieldset>';

        echo $html;
    }
}
