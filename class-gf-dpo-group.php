<?php

/*
 * Copyright (c) 2025 DPO Group
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
add_action('parse_request', array("GF_DPO_Group", "notify_handler"));

GFForms::include_payment_addon_framework();

require_once 'includes/dpo-group-tools.php';
require_once 'includes/DpoGfUtilities.php';
require_once 'dpo-group.php';
require_once 'includes/DpoGfForm.php';
require __DIR__ . '/vendor/autoload.php';

use Dpo\Common\Dpo;

class GF_DPO_Group extends GFPaymentAddOn
{

    const DATE_FORMAT = 'y-m-d H:i:s';
    private static $_instance                 = null;
    protected      $_min_gravityforms_version = '2.2.5';
    protected      $_slug                     = 'gravity-forms-dpo-group-plugin';
    protected      $_path                     = 'gravity-forms-dpo-group-plugin/dpo-group.php';
    protected      $_full_path                = __FILE__;
    protected      $_url                      = 'https://www.gravityforms.com';
    protected      $_title                    = 'Gravity Forms DPO Pay Add-On';
    protected      $_short_title              = 'DPO Pay';
    // Permissions
    protected $_supports_callbacks         = true;
    protected $_capabilities               = array('gravityforms_dpo_group', 'gravityforms_dpo_group_uninstall');
    protected $_capabilities_settings_page = 'gravityforms_dpo_group';
    // Automatic upgrade enabled
    protected $_capabilities_form_settings = 'gravityforms_dpo_group';
    protected $_capabilities_uninstall     = 'gravityforms_dpo_group_uninstall';
    protected $_enable_rg_autoupgrade      = false;

    public static function get_instance()
    {
        if (self::$_instance == null) {
            self::$_instance = new GF_DPO_Group();
        }

        return self::$_instance;
    }

    /**
     * Handle return from DPO Pay portal
     * GET contains DPO Pay returns and GF records in query string
     * @throws Exception
     */
    public static function notify_handler()
    {
        if (isset($_GET["TransactionToken"])) {
            $instance = self::get_instance();

            $returns = $instance->process_get($_GET);

            GW_DPO_Group_Post_Content_Merge_Tags::get_instance()->replace_merge_tags($returns);

            $form = GFAPI::get_form($returns['form_id']);
            $lead = GFAPI::get_entry($returns['lead_id']);
            $feed = GFAPI::get_feeds($returns['feed_id'], $returns['form_id'], null, true);

            //This is the production token stored with feed
            $companyToken = $feed[0]['meta']['DPO_GroupMerchantToken'];
            $testMode     = isset($returns['mode']) && sanitize_text_field($returns['mode']);

            $dpoPay = new Dpo($testMode);
            $data   = [];

            //Actual token depends on mode - test or production
            $data['companyToken'] = $companyToken;
            $data['transToken']   = sanitize_text_field(wp_unslash($_GET['TransactionToken']));

            $verified = false;
            while (!$verified) {
                $verify   = $dpoPay->verifyToken($data);
                $verify   = new SimpleXMLElement($verify);
                $status   = match ($verify->Result->__toString()) {
                    '000' => 1,
                    '901' => 2,
                    default => 4,
                };
                $verified = true;
            }

            //Retrieve data from get fields
            $notify_data                   = [];
            $notify_data['ID']             = $returns['eid'] ?? '0';
            $notify_data['REFERENCE']      = isset($_GET['CompanyRef']) ? sanitize_text_field(
                wp_unslash($_GET['CompanyRef'])
            ) : '';
            $notify_data['TRANSACTION_ID'] = sanitize_text_field(wp_unslash($_GET['TransactionToken']));
            $notify_data['AMOUNT']         = $verify->TransactionAmount->__toString();

            $errors = false;

            $entry = GFAPI::get_entry($notify_data['ID']);
            if (isset($entry->errors)) {
                $instance->log_error("Entry could not be found. Entry ID: {$notify_data['ID']}. Aborting.");
                $status = 0;
                $errors = true;
            }

            $instance->log_debug("Entry has been found." . json_encode($entry));

            // Check status and update order
            if (!$errors) {
                $instance->log_debug('Check status and update order');

                switch ((string)$status) {
                    case '1':
                        $status_desc = 'approved';
                        // Creates transaction
                        GFAPI::update_entry_property($notify_data['ID'], 'payment_status', 'Approved');
                        GFAPI::update_entry_property(
                            $notify_data['ID'],
                            'transaction_id',
                            $notify_data['TRANSACTION_ID']
                        );
                        GFAPI::update_entry_property($notify_data['ID'], 'transaction_type', '1');
                        GFAPI::update_entry_property(
                            $notify_data['ID'],
                            'payment_amount',
                            number_format($notify_data['AMOUNT'], 2, ',', '')
                        );
                        GFAPI::update_entry_property($notify_data['ID'], 'is_fulfilled', '1');
                        GFAPI::update_entry_property($notify_data['ID'], 'payment_method', 'DPO Pay');
                        GFAPI::update_entry_property($notify_data['ID'], 'payment_date', gmdate(self::DATE_FORMAT));

                        self::get_instance()->insert_transaction(
                            $notify_data['ID'],
                            'complete_payment',
                            $notify_data['REFERENCE'],
                            number_format($notify_data['AMOUNT'], 2, ',', '')
                        );
                        GFFormsModel::add_note(
                            $notify_data['ID'],
                            '',
                            'DPO Pay Notify Response',
                            'Transaction approved, DPO Pay TransId: ' . $notify_data['TRANSACTION_ID'] . ' ApprovalCode: ' . sanitize_text_field(
                                $verify->ApprovalNumber->__toString()
                            )
                        );
                        GFAPI::send_notifications($form, $lead, 'complete_payment');

                        $confirmationPageUrl = $feed['0']['meta']['successPageUrl'];
                        $confirmationPageUrl = $instance->dpo_group_add_query_arg(
                            array('eid' => $returns['eidu']),
                            $confirmationPageUrl
                        );
                        break;
                    case '4':
                        $status_desc = 'cancelled';
                        GFAPI::update_entry_property($returns['lead_id'], 'payment_status', 'Cancelled');
                        GFFormsModel::add_note(
                            $returns['lead_id'],
                            '',
                            'DPO Pay Redirect Response',
                            'Transaction Cancelled, Pay Request ID: ' . $notify_data['TRANSACTION_ID']
                        );
                        break;
                    default:
                        $status_desc = 'failed';
                        self::notifyFailed($notify_data);
                        break;
                }

                $instance->log_debug('Send notifications.');
                $instance->log_debug($entry);
                $form = GFFormsModel::get_form_meta($feed[0]['form_id']);

                $confirmation_msg = 'Thanks for contacting us! We will get in touch with you shortly.';
                // Display the correct message depending on transaction status
                $confirmation_msg = self::getConfirmation_msg($form['confirmations'], $status_desc, $confirmation_msg);
                $confirmation_msg = apply_filters('the_content', $confirmation_msg);

                if (!class_exists('GFFormDisplay')) {
                    require_once GFCommon::get_base_path() . '/form_display.php';
                }

                GFFormDisplay::$submission[$returns['form_id']] = array(
                    'is_confirmation'      => true,
                    'confirmation_message' => $confirmation_msg,
                    'form'                 => $form,
                    'lead'                 => $lead,
                );
            }
        }
    }

    public static function get_config_by_entry($entry)
    {
        $dpo_group = GF_DPO_Group::get_instance();

        $feed = $dpo_group->get_payment_feed($entry);

        if (empty($feed)) {
            return false;
        }

        return $feed['addon_slug'] == $dpo_group->_slug ? $feed : false;
    }

    //----- SETTINGS PAGES ----------//

    public static function get_config($form_id): bool
    {
        $dpo_group = GF_DPO_Group::get_instance();
        $feed      = $dpo_group->get_feeds($form_id);

        // Ignore ITN messages from forms that are no longer configured with the DPO Pay add-on
        if (!$feed) {
            return false;
        }

        return $feed[0]; // Only one feed per form is supported (left for backwards compatibility)
    }

    /**
     * @param $confirmations
     * @param string $status_desc
     * @param mixed $confirmation_msg
     *
     * @return array|mixed|string|string[]
     */
    public static function getConfirmation_msg($confirmations, string $status_desc, mixed $confirmation_msg): mixed
    {
        foreach ($confirmations as $row) {
            // This condition does NOT working when using the Custom Confirmation Page setting
            if ($status_desc == strtolower(str_replace(' ', '', $row['name']))) {
                $confirmation_msg = $row['message'];
                $confirmation_msg = apply_filters('the_content', $confirmation_msg);
                $confirmation_msg = str_replace(']]>', ']]&gt;', $confirmation_msg);
            }
        }

        return $confirmation_msg;
    }

    /**
     * @param array $notify_data
     *
     * @return void
     */
    public static function notifyFailed(array $notify_data): void
    {
        if ($notify_data['ID'] != '0') {
            GFFormsModel::add_note(
                $notify_data['REFERENCE'],
                '',
                'DPO Pay Notify Response',
                'Transaction declined, DPO Pay TransId: ' . $notify_data['TRANSACTION_ID']
            );
            GFAPI::update_entry_property($notify_data['REFERENCE'], 'payment_status', 'Declined');
        }
    }

    public function init_frontend()
    {
        parent::init_frontend();

        add_filter('gform_disable_post_creation', array($this, 'delay_post'), 10, 3);
        add_filter('gform_disable_notification', array($this, 'delay_notification'), 10, 4);
    }

    public function plugin_settings_fields()
    {
        $dpoForm = new DpoGfForm();

        return $dpoForm->getDpoConfigInstructions();
    }

    public function feed_list_no_item_message()
    {
        $settings = $this->get_plugin_settings();
        if (!rgar($settings, 'gf_dpo_group_configured')) {
            // translators: %s: DPO settings. %s: closing tag for DPO settings.
            return sprintf(
                __('To get started, configure your %sDPO Pay Settings%s!', 'gravity-forms-dpo-group-plugin'),
                '<a href="' . admin_url('admin.php?page=gf_settings&subview=' . $this->_slug) . '">',
                '</a>'
            );
        } else {
            return parent::feed_list_no_item_message();
        }
    }

    public function feed_settings_fields()
    {
        define("H6_TAG", '<h6>');
        define("H6_TAG_CLOSING", '</h6>');
        $default_settings = parent::feed_settings_fields();
        $dpoForm          = new DpoGfForm();

        //--add DPO Pay fields
        $fields = $dpoForm->getFields();

        $default_settings = parent::add_field_after('feedName', $fields, $default_settings);
        //--------------------------------------------------------------------------------------

        $message          = $dpoForm->getBillingMsg();
        $default_settings = $this->add_field_after('trial', $message, $default_settings);

        $default_settings = $this->remove_field('recurringTimes', $default_settings);
        $default_settings = $this->remove_field('billingCycle', $default_settings);
        $default_settings = $this->remove_field('recurringAmount', $default_settings);
        $default_settings = $this->remove_field('setupFee', $default_settings);
        $default_settings = $this->remove_field('trial', $default_settings);

        // Add donation to transaction type drop down
        $transaction_type = parent::get_field('transactionType', $default_settings);
        $choices          = $transaction_type['choices'];
        $add_donation     = false;
        foreach ($choices as $choice) {
            // Add donation option if it does not already exist
            if ($choice['value'] == 'donation') {
                $add_donation = false;
            }
        }
        if ($add_donation) {
            // Add donation transaction type
            $choices[] = array('label' => __('Donations', 'gravity-forms-dpo-group-plugin'), 'value' => 'donation');
        }
        $transaction_type['choices'] = $choices;
        $default_settings            = $this->replace_field('transactionType', $transaction_type, $default_settings);
        //-------------------------------------------------------------------------------------------------
        $icon_url = plugin_dir_url(__DIR__) . 'gravity-forms-dpo-group-plugin/assets/images/logo.svg';
        $fields   = array(
            array(
                'name'  => 'logo',
                'label' => __(
                    '<img src="' . $icon_url . '" alt="DPO Pay" style="width: auto !important; height: 25px !important; border: none !important;"></br></br>',
                    'gravity-forms-dpo-group-plugin'
                ),
                'type'  => 'custom',
            ),
        );

        $default_settings = $this->add_field_before('feedName', $fields, $default_settings);

        // Add Page Style, Continue Button Label, Cancel URL
        $fields = $dpoForm->getCancelUrl();

        // Add post fields if form has a post
        $form = $this->get_current_form();
        if (GFCommon::has_post_field($form['fields'])) {
            $post_settings = $dpoForm->getPostSettings();

            if ($this->get_setting('transactionType') == 'subscription') {
                $post_settings['choices'][] = array(
                    'label'    => __(
                        'Change post status when subscription is canceled.',
                        'gravity-forms-dpo-group-plugin'
                    ),
                    'name'     => 'change_post_status',
                    'onChange' => 'var action = this.checked ? "draft" : ""; jQuery("#update_post_action").val(action);',
                );
            }

            $fields[] = $post_settings;
        }

        // Adding custom settings for backwards compatibility with hook 'gform_dpo_add_option_group'
        $fields[] = array(
            'name'  => 'custom_options',
            'label' => '',
            'type'  => 'custom',
        );

        $default_settings = $this->add_field_after('billingInformation', $fields, $default_settings);
        //-----------------------------------------------------------------------------------------
        // Get billing info section and add customer first/last name
        $billing_info   = parent::get_field('billingInformation', $default_settings);
        $billing_fields = $billing_info['field_map'];
        $add_first_name = true;
        $add_last_name  = true;
        $add_phone      = true;
        foreach ($billing_fields as $mapping) {
            // Add first/last name if it does not already exist in billing fields
            if ($mapping['name'] == 'firstName') {
                $add_first_name = false;
            } elseif ($mapping['name'] == 'lastName') {
                $add_last_name = false;
            }
        }

        if ($add_last_name) {
            // Add last name
            array_unshift($billing_info['field_map'], array(
                'name'     => 'lastName',
                'label'    => __('Last Name', 'gravity-forms-dpo-group-plugin'),
                'required' => false,
            ));
        }
        if ($add_first_name) {
            array_unshift($billing_info['field_map'], array(
                'name'     => 'firstName',
                'label'    => __('First Name', 'gravity-forms-dpo-group-plugin'),
                'required' => false,
            ));
        }
        if ($add_phone) {
            array_unshift($billing_info['field_map'], array(
                'name'     => 'phone',
                'label'    => __('Telephone', 'gravity-forms-dpo-group-plugin'),
                'required' => false,
            ));
        }
        $default_settings = parent::replace_field('billingInformation', $billing_info, $default_settings);

        return apply_filters('gform_dpo_group_feed_settings_fields', $default_settings, $form);
    }

    public function field_map_title()
    {
        return __('DPO Pay Field', 'gravity-forms-dpo-group-plugin');
    }

    public function settings_trial_period($field, $echo = true)
    {
        // Use the parent billing cycle function to make the dropdown for the number and type
        return parent::settings_billing_cycle($field);
    }

    public function set_trial_onchange($field)
    {
        // Return the javascript for the onchange event
        return "
        if(jQuery(this).prop('checked')){
            jQuery('#{$field['name']}_product').show('slow');
            jQuery('#gaddon-setting-row-trialPeriod').show('slow');
            if (jQuery('#{$field['name']}_product').val() == 'enter_amount'){
                jQuery('#{$field['name']}_amount').show('slow');
            }
            else{
                jQuery('#{$field['name']}_amount').hide();
            }
        }
        else {
            jQuery('#{$field['name']}_product').hide('slow');
            jQuery('#{$field['name']}_amount').hide();
            jQuery('#gaddon-setting-row-trialPeriod').hide('slow');
        }";
    }

    public function settings_options($field, $echo = true)
    {
        $checkboxes = array(
            'name'    => 'options_checkboxes',
            'type'    => 'checkboxes',
            'choices' => array(
                array(
                    'label' => __(
                        'Do not prompt buyer to include a shipping address.',
                        'gravity-forms-dpo-group-plugin'
                    ),
                    'name'  => 'disableShipping',
                ),
                array(
                    'label' => __(
                        'Do not prompt buyer to include a note with payment.',
                        'gravity-forms-dpo-group-plugin'
                    ),
                    'name'  => 'disableNote',
                ),
            ),
        );

        $html = $this->settings_checkbox($checkboxes, false);

        //--------------------------------------------------------
        // For backwards compatibility.
        ob_start();
        do_action('gform_dpo_group_action_fields', $this->get_current_feed(), $this->get_current_form());
        $html .= ob_get_clean();
        //--------------------------------------------------------

        if ($echo) {
            echo wp_kses_post($html);
        }

        return wp_kses_post($html);
    }

    public function settings_custom($field, $echo = true)
    {
        ob_start();
        ?>
        <div id='gf_dpo_group_custom_settings'>
            <?php
            do_action('gform_dpo_group_add_option_group', $this->get_current_feed(), $this->get_current_form());
            ?>
        </div>

        <script type='text/javascript'>
          jQuery(document).ready(function (){
            jQuery('#gf_dpo_group_custom_settings label.left_header').css('margin-left', '-200px')
          })
        </script>

        <?php
        $html = ob_get_clean();

        if ($echo) {
            echo wp_kses_post($html);
        }

        return $html;
    }

    public function checkbox_input_change_post_status($choice, $attributes, $value, $tooltip)
    {
        $markup = $this->checkbox_input($choice, $attributes, $value, $tooltip);

        $dropdown_field = array(
            'name'     => 'update_post_action',
            'choices'  => array(
                array('label' => ''),
                array('label' => __('Mark Post as Draft', 'gravity-forms-dpo-group-plugin'), 'value' => 'draft'),
                array('label' => __('Delete Post', 'gravity-forms-dpo-group-plugin'), 'value' => 'delete'),
            ),
            'onChange' => "var checked = jQuery(this).val() ? 'checked' : false; jQuery('#change_post_status').attr('checked', checked);",
        );
        $markup         .= '&nbsp;&nbsp;' . $this->settings_select($dropdown_field, false);

        return $markup;
    }

    //------ SENDING TO DPO Pay -----------//

    public function option_choices()
    {
        return false;
    }

    public function save_feed_settings($feed_id, $form_id, $settings)
    {
        //--------------------------------------------------------
        // For backwards compatibility
        $feed = $this->get_feed($feed_id);

        // Saving new fields into old field names to maintain backwards compatibility for delayed payments
        $settings['type'] = $settings['transactionType'];

        if (isset($settings['recurringAmount'])) {
            $settings['recurring_amount_field'] = $settings['recurringAmount'];
        }

        $feed['meta'] = $settings;
        $feed         = apply_filters('gform_dpo_group_save_config', $feed);

        // Call hook to validate custom settings/meta added using gform_dpo_group_action_fields or gform_dpo_group_add_option_group action hooks
        $is_validation_error = apply_filters('gform_dpo_group_config_validation', false, $feed);
        if ($is_validation_error) {
            // Fail save
            return false;
        }

        $settings = $feed['meta'];

        //--------------------------------------------------------

        return parent::save_feed_settings($feed_id, $form_id, $settings);
    }

    /**
     * Process order data and redirect to DPO Pay payment portal
     *
     * @param array $feed
     * @param array $submission_data
     * @param array $form
     * @param array $entry
     *
     * @return bool|string|void
     * @throws Exception
     */
    public function redirect_url($feed, $submission_data, $form, $entry)
    {
        // Don't process redirect url if request is a DPO Pay return
        if (!rgempty('gf_dpo_group_return', $_GET)) {
            return false;
        }

        // Unset transaction session on re-submit
        unset($_SESSION['trans_failed']);
        unset($_SESSION['trans_declined']);
        unset($_SESSION['trans_cancelled']);

        // Updating lead's payment_status to Processing
        GFAPI::update_entry_property($entry['id'], 'payment_status', 'Pending');

        //Set return mode
        $return_mode = '2';
        $return_url  = $this->return_url($form['id'], $entry['id'], $entry['created_by'], $feed['id']);
        $return_url  = $this->dpo_group_add_query_arg(array('rm' => $return_mode), $return_url);

        $back_url   = $return_url;
        $eid        = DPO_Group_GF_encryption($entry['id'], 'e');
        $return_url = $this->dpo_group_add_query_arg(array('eid' => $eid), $return_url);

        $testMode     = $feed['meta']['mode'] === 'test';
        $return_url   = $this->dpo_group_add_query_arg(
            array('mode' => $testMode ? 'on' : 'off'),
            $return_url
        );
        $companyToken = $feed['meta']['DPO_GroupMerchantToken'];
        setcookie(
            'DPO_GroupMerchantToken',
            DPO_Group_GF_encryption($companyToken, 'e'),
            time() + 24 * 3600 * 30
        );
        $serviceType = $feed['meta']['DPO_GroupServiceType'];

        $dpoPay    = new Dpo($testMode);
        $utilities = new DpoGfUtilities();

        $amount    = number_format(GFCommon::get_order_total($form, $entry), 2, '.', '');
        $currency  = GFCommon::get_currency();
        $reference = 'DPO_Group_Form_' . gmdate('Y-m-d_H:i:s');

        /**
         * Set up the order info to pass to dpo_grouppay
         */
        $data                      = [];
        $data['companyToken']      = $companyToken;
        $data['serviceType']       = $serviceType;
        $data['paymentAmount']     = $amount;
        $data['paymentCurrency']   = $currency;
        $data['customerFirstName'] = $entry[$feed['meta']['billingInformation_firstName']];
        $data['customerLastName']  = $entry[$feed['meta']['billingInformation_lastName']];
        $data['customerAddress']   = $entry[$feed['meta']['billingInformation_address']];
        $data['customerCity']      = $entry[$feed['meta']['billingInformation_city']];
        $data['customerCountry']   = $utilities->get_country_code($entry[$feed['meta']['billingInformation_country']]);
        $data['customerPhone']     = str_replace([
                                                     '+',
                                                     '-',
                                                     '(',
                                                     ')',
                                                     ' ',
                                                 ], '', $entry[$feed['meta']['billingInformation_phone']]);
        $data['redirectURL']       = $return_url;
        $data['backURL']           = $back_url;
        $data['customerEmail']     = $entry[$feed['meta']['billingInformation_email']];
        $data['companyAccRef']     = $reference;
        $data['companyRef']        = $reference;

        $tokens = $dpoPay->createToken($data);

        if ($tokens['success'] === true) {
            $data['transToken'] = $tokens['transToken'];

            $verified = null;

            while ($verified === null) {
                $verify = $dpoPay->verifyToken(
                    [
                        'companyToken' => $companyToken,
                        'transToken'   => $tokens['transToken']
                    ]
                );

                $verify = new SimpleXMLElement($verify);
                if ($verify->Result->__toString() === '900') {
                    $verified = true;
                    $payUrl   = $dpoPay->getPayUrl() . '?ID=' . $data['transToken'];
                    header('Location: ' . $payUrl);
                    exit;
                }
            }
        } else {
            //Tokens not created
            echo esc_html($tokens["error"] ?? 'There was an error.');
            exit;
        }
    }

    public function get_product_query_string($submission_data, $entry_id)
    {
        if (empty($submission_data)) {
            return false;
        }

        $query_string   = '';
        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $discounts      = rgar($submission_data, 'discounts');

        $product_index = 1;
        $shipping      = '';
        $discount_amt  = 0;
        $cmd           = '_cart';
        $extra_qs      = '&upload=1';

        $utilities = new DpoGfUtilities();

        // Work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name = urlencode($item['name']);
                $quantity     = $item['quantity'];
                $unit_price   = $item['unit_price'];
                $options      = rgar($item, 'options');
                $is_shipping  = rgar($item, 'is_shipping');

                if ($is_shipping) {
                    // Populate shipping info
                    $shipping .= !empty($unit_price) ? "&shipping_1=$unit_price" : '';
                } else {
                    // Add product info to querystring
                    $query_string .= "&item_name_{$product_index}={$product_name}&amount_{$product_index}={$unit_price}&quantity_{$product_index}={$quantity}";
                }
                // Add options
                $query_string = $utilities->addOptions($options, $product_index, $query_string);
                $product_index++;
            }
        }

        // Look for discounts
        $query_string = $utilities->getDiscounts($discounts, $discount_amt, $query_string);

        $query_string .= "{$shipping}&cmd={$cmd}{$extra_qs}";

        // Save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    public function get_donation_query_string($submission_data, $entry_id)
    {
        if (empty($submission_data)) {
            return false;
        }

        $payment_amount = rgar($submission_data, 'payment_amount');
        $line_items     = rgar($submission_data, 'line_items');
        $purpose        = '';
        $cmd            = '_donations';
        $utilities      = new DpoGfUtilities();

        // Work on products
        if (is_array($line_items)) {
            foreach ($line_items as $item) {
                $product_name    = $item['name'];
                $quantity        = $item['quantity'];
                $quantity_label  = $quantity > 1 ? $quantity . ' ' : '';
                $options         = rgar($item, 'options');
                $is_shipping     = rgar($item, 'is_shipping');
                $product_options = '';

                if (!$is_shipping) {
                    // Add options
                    $product_options = $utilities->getOptions($options, $product_options);
                    $purpose         .= $quantity_label . $product_name . $product_options . ', ';
                }
            }
        }

        if (!empty($purpose)) {
            $purpose = substr($purpose, 0, strlen($purpose) - 2);
        }

        $purpose = urlencode($purpose);

        // Truncating to maximum length allowed by DPO Pay
        if (strlen($purpose) > 127) {
            $purpose = substr($purpose, 0, 124) . '...';
        }

        $query_string = "&amount={$payment_amount}&item_name={$purpose}&cmd={$cmd}";

        // Save payment amount to lead meta
        gform_update_meta($entry_id, 'payment_amount', $payment_amount);

        return $payment_amount > 0 ? $query_string : false;
    }

    public function customer_query_string($feed, $lead): string
    {
        $fields = '';
        foreach ($this->get_customer_fields() as $field) {
            $field_id = $feed['meta'][$field['meta_name']];
            $value    = rgar($lead, $field_id);

            if ($field['name'] == 'country') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_country_code(
                    $value
                ) : GFCommon::get_country_code($value);
            } elseif ($field['name'] == 'state') {
                $value = class_exists('GF_Field_Address') ? GF_Fields::get('address')->get_us_state_code(
                    $value
                ) : GFCommon::get_us_state_code($value);
            }

            if (!empty($value)) {
                $fields .= "&{$field['name']}=" . urlencode($value);
            }
        }

        return $fields;
    }

    /**
     * Calculate the URL DPO Pay should return to
     *
     * @param $form_id
     * @param $lead_id
     * @param $user_id
     * @param $feed_id
     *
     * @return string
     */
    public function return_url($form_id, $lead_id, $user_id, $feed_id): string
    {
        $pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

        $server_port = isset($_SERVER['SERVER_PORT']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_PORT'])) : '80';
        $server_port = apply_filters('gform_dpo_group_return_url_port', $server_port);

        $server_name = isset($_SERVER['SERVER_NAME']) ? sanitize_text_field(wp_unslash($_SERVER['SERVER_NAME'])) : '';
        $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash($_SERVER['REQUEST_URI'])) : '';

        if ($server_port !== '80') {
            $pageURL .= $server_name . ':' . $server_port . $request_uri;
        } else {
            $pageURL .= $server_name . $request_uri;
        }


        $ids_query         = "ids={$form_id}|{$lead_id}|{$user_id}|{$feed_id}";
        $ids_query         .= '&hash=' . wp_hash($ids_query);
        $encrypt_ids_query = DPO_Group_GF_encryption($ids_query, 'e');

        return $this->dpo_group_add_query_arg(['gf_dpo_group_return' => $encrypt_ids_query], $pageURL);
    }

    public function get_customer_fields(): array
    {
        return array(
            array('name' => 'first_name', 'label' => 'First Name', 'meta_name' => 'billingInformation_firstName'),
            array('name' => 'last_name', 'label' => 'Last Name', 'meta_name' => 'billingInformation_lastName'),
            array('name' => 'phone', 'label' => 'Telephone', 'meta_name' => 'billingInformation_phone'),
            array('name' => 'email', 'label' => 'Email', 'meta_name' => 'billingInformation_email'),
            array('name' => 'address1', 'label' => 'Address', 'meta_name' => 'billingInformation_address'),
            array('name' => 'address2', 'label' => 'Address 2', 'meta_name' => 'billingInformation_address2'),
            array('name' => 'city', 'label' => 'City', 'meta_name' => 'billingInformation_city'),
            array('name' => 'state', 'label' => 'State', 'meta_name' => 'billingInformation_state'),
            array('name' => 'zip', 'label' => 'Zip', 'meta_name' => 'billingInformation_zip'),
            array('name' => 'country', 'label' => 'Country', 'meta_name' => 'billingInformation_country'),
        );
    }

    public function convert_interval($interval, $to_type): string
    {
        // Convert single character into long text for new feed settings or convert long text into single character for sending to DPO Pay
        // $to_type: text (change character to long text), OR char (change long text to character)
        if (empty($interval)) {
            return '';
        }

        if ($to_type == 'text') {
            // Convert single char to text
            $new_interval = match (strtoupper($interval)) {
                'D' => 'day',
                'W' => 'week',
                'M' => 'month',
                'Y' => 'year',
                default => $interval,
            };
        } else {
            // Convert text to single char
            $new_interval = match (strtolower($interval)) {
                'day' => 'D',
                'week' => 'W',
                'month' => 'M',
                'year' => 'Y',
                default => $interval,
            };
        }

        return $new_interval;
    }

    public function delay_post($is_disabled, $form, $entry): bool
    {
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        return !rgempty('delayPost', $feed['meta']);
    }

    //------- PROCESSING DPO Pay (Callback) -----------//

    public function delay_notification($is_disabled, $notification, $form, $entry): bool
    {
        $this->log_debug('Delay notification ' . json_encode($notification) . ' for ' . $entry['id'] . '.');
        $feed            = $this->get_payment_feed($entry);
        $submission_data = $this->get_submission_data($feed, $form, $entry);
        $this->log_debug(json_encode($submission_data));

        if (!$feed || empty($submission_data['payment_amount'])) {
            return $is_disabled;
        }

        $selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar(
            $feed['meta'],
            'selectedNotifications'
        ) : array();

        return isset($feed['meta']['delayNotification']) && in_array(
            $notification['id'],
            $selected_notifications
        ) ? true : $is_disabled;
    }

    public function get_payment_feed($entry, $form = false)
    {
        $feed = parent::get_payment_feed($entry, $form);

        if (empty($feed) && !empty($entry['id'])) {
            // Looking for feed created by legacy versions
            $feed = $this->get_dpo_group_feed_by_entry($entry['id']);
        }

        return apply_filters('gform_dpo_group_get_payment_feed', $feed, $entry, $form);
    }

    public function get_entry($custom_field): mixed
    {
        $this->checkCustomField($custom_field);

        // Getting entry associated with this ITN message (entry id is sent in the 'custom' field)
        list($entry_id, $hash) = explode('|', $custom_field);
        $hash_matches = wp_hash($entry_id) == $hash;

        // Allow the user to do some other kind of validation of the hash
        $hash_matches = apply_filters('gform_dpo_group_hash_matches', $hash_matches, $entry_id, $hash, $custom_field);

        // Validates that Entry ID wasn't tampered with
        if (!rgpost('test_itn') && !$hash_matches) {
            $this->log_error(
                __METHOD__ . "(): Entry Id verification failed. Hash does not match. Custom field: {$custom_field}. Aborting."
            );

            return false;
        }

        $this->log_debug(__METHOD__ . "(): ITN message has a valid custom field: {$custom_field}");

        $entry = GFAPI::get_entry($entry_id);

        if (is_wp_error($entry)) {
            $this->log_error(__METHOD__ . '(): ' . $entry->get_error_message());

            return false;
        }

        return $entry;
    }

    public function checkCustomField($customField)
    {
        if (empty($customField)) {
            $this->log_error(
                __METHOD__ . '(): ITN request does not have a custom field, so it was not created by Gravity Forms. Aborting.'
            );

            return false;
        }
    }

    public function modify_post($post_id, $action): bool
    {
        if (!$post_id) {
            return false;
        }

        switch ($action) {
            case 'draft':
                $post              = get_post($post_id);
                $post->post_status = 'draft';
                $result            = wp_update_post($post);
                $this->log_debug(__METHOD__ . "(): Set post (#{$post_id}) status to \"draft\".");
                break;
            case 'delete':
                $result = wp_delete_post($post_id);
                $this->log_debug(__METHOD__ . "(): Deleted post (#{$post_id}).");
                break;
            default:
                return false;
        }

        return $result;
    }

    public function is_callback_valid(): bool
    {
        if (rgget('page') != 'gf_dpo_group') {
            return false;
        }

        return true;
    }

    public function init_ajax()
    {
        parent::init_ajax();

        add_action('wp_ajax_gf_dismiss_dpo_group_menu', array($this, 'ajax_dismiss_menu'));
    }

    public function init_admin()
    {
        parent::init_admin();

        // Add actions to allow the payment status to be modified
        add_action('gform_payment_status', array($this, 'admin_edit_payment_status'), 3, 3);

        if (version_compare(GFCommon::$version, '1.8.17.4', '<')) {
            // Using legacy hook
            add_action('gform_entry_info', array($this, 'admin_edit_payment_status_details'), 4, 2);
        } else {
            add_action('gform_payment_date', array($this, 'admin_edit_payment_date'), 3, 3);
            add_action('gform_payment_transaction_id', array($this, 'admin_edit_payment_transaction_id'), 3, 3);
            add_action('gform_payment_amount', array($this, 'admin_edit_payment_amount'), 3, 3);
        }

        add_action('gform_after_update_entry', array($this, 'admin_update_payment'), 4, 2);

        add_filter('gform_addon_navigation', array($this, 'maybe_create_menu'));

        add_filter('gform_notification_events', array($this, 'notification_events_dropdown'));
    }

    public function notification_events_dropdown($notification_events): array
    {
        $payment_events = array(
            'complete_payment' => __('Payment Complete', 'gravity-forms-dpo-group-plugin')
        );

        return array_merge($notification_events, $payment_events);
    }

    //------- AJAX FUNCTIONS ------------------//

    public function maybe_create_menu($menus)
    {
        $current_user           = wp_get_current_user();
        $dismiss_dpo_group_menu = get_metadata('user', $current_user->ID, 'dismiss_dpo_group_menu', true);
        if ($dismiss_dpo_group_menu != '1') {
            $menus[] = array(
                'name'       => $this->_slug,
                'label'      => $this->get_short_title(),
                'callback'   => array($this, 'temporary_plugin_page'),
                'permission' => $this->_capabilities_form_settings,
            );
        }

        return $menus;
    }

    //------- ADMIN FUNCTIONS/HOOKS -----------//

    public function ajax_dismiss_menu()
    {
        $current_user = wp_get_current_user();
        update_metadata('user', $current_user->ID, 'dismiss_dpo_group_menu', '1');
    }

    public function temporary_plugin_page()
    {
        ?>
        <script type="text/javascript">
          function dismissMenu(){
            jQuery('#gf_spinner').show()
            jQuery.post(ajaxurl, {
                action: 'gf_dismiss_dpo_group_menu',
              },
              function (response){
                document.location.href = '?page=gf_edit_forms'
                jQuery('#gf_spinner').hide()
              },
            )

          }
        </script>

        <div class="wrap about-wrap">
            <h1><?php
                esc_html_e('DPO Pay Add-On', 'gravity-forms-dpo-group-plugin') ?></h1>
            <div class="about-text"><?php
                esc_html_e(
                    'Thank you for updating! The new version of the Gravity Forms DPO Pay Add-On makes changes to how you manage your DPO Pay integration.',
                    'gravity-forms-dpo-group-plugin'
                ) ?></div>
            <div class="changelog">
                <hr/>
                <div class="feature-section col two-col">
                    <div class="col-1">
                        <h3><?php
                            esc_html_e('Manage DPO Pay Contextually', 'gravity-forms-dpo-group-plugin') ?></h3>
                        <p><?php
                            esc_html_e(
                                'DPO Pay Feeds are now accessed via the DPO Pay sub-menu within the Form Settings for the Form you would like to integrate DPO Pay with.',
                                'gravity-forms-dpo-group-plugin'
                            ) ?></p>
                    </div>
                </div>

                <hr/>

                <form method="post" id="dismiss_menu_form" style="margin-top: 20px;">
                    <input type="checkbox" name="dismiss_dpo_group_menu" value="1" onclick="dismissMenu();">
                    <label><?php
                        esc_html_e('I understand, dismiss this message!', 'gravity-forms-dpo-group-plugin') ?></label>
                    <img id="gf_spinner" src="<?php
                    echo esc_url(GFCommon::get_base_url() . '/images/spinner.gif') ?>"
                         alt="<?php
                         esc_attr_e('Please wait...', 'gravity-forms-dpo-group-plugin') ?>" style="display:none;"/>
                </form>

            </div>
        </div>
        <?php
    }

    public function admin_edit_payment_status($payment_status, $form, $lead): string
    {
        // Allow the payment status to be edited when for DPO Pay, not set to Approved/Paid, and not a subscription
        if (!$this->is_payment_gateway($lead['id']) || strtolower(
                rgpost('save')
            ) != 'edit' || $payment_status == 'Approved' || $payment_status == 'Paid' || rgar(
                $lead,
                'transaction_type'
            ) == 2) {
            return $payment_status;
        }

        // Create drop down for payment status
        $payment_string = gform_tooltip('dpo_group_edit_payment_status', '', true);
        $payment_string .= '<select id="payment_status" name="payment_status">';
        $payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
        $payment_string .= '<option value="Paid">Paid</option>';
        $payment_string .= '</select>';

        return $payment_string;
    }

    public function admin_edit_payment_date($payment_date, $form, $lead): string
    {
        // Allow the payment date to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $payment_date;
        }

        $payment_date = $lead['payment_date'];
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_FORMAT);
        }

        return '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';
    }

    public function admin_edit_payment_transaction_id($transaction_id, $form, $lead): string
    {
        // Allow the transaction ID to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $transaction_id ?? '';
        }

        return '<input type="text" id="dpo_group_transaction_id" name="dpo_group_transaction_id" value="' . $transaction_id . '">';
    }

    public function admin_edit_payment_amount($payment_amount, $form, $lead): string
    {
        // Allow the payment amount to be edited
        if (!$this->is_payment_gateway($lead['id']) || strtolower(rgpost('save')) != 'edit') {
            return $payment_amount;
        }

        if (empty($payment_amount)) {
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }

        return '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';
    }

    public function admin_edit_payment_status_details($form_id, $lead)
    {
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead['id']) || $form_action != 'edit') {
            return;
        }

        // Get data from entry to pre-populate fields
        $payment_amount = rgar($lead, 'payment_amount');
        if (empty($payment_amount)) {
            $form           = GFFormsModel::get_form_meta($form_id);
            $payment_amount = GFCommon::get_order_total($form, $lead);
        }
        $transaction_id = rgar($lead, 'transaction_id');
        $payment_date   = rgar($lead, 'payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_FORMAT);
        }

        // Display edit fields
        ?>
        <div id="edit_payment_status_details" style="display:block">
            <table>
                <caption>Display edit fields</caption>
                <tr>
                    <th scope="col">Payment Information</th>
                    <th scope="col">Value</th>
                </tr>

                <tr>
                    <td colspan="2"><strong>Payment Information</strong></td>
                </tr>

                <tr>
                    <td>Date:<?php
                        gform_tooltip('dpo_group_edit_payment_date') ?></td>
                    <td>
                        <input type="text" id="payment_date" name="payment_date" value="<?php
                        echo esc_attr($payment_date) ?>">
                    </td>
                </tr>
                <tr>
                    <td>Amount:<?php
                        gform_tooltip('dpo_group_edit_payment_amount') ?></td>
                    <td>
                        <input type="text" id="payment_amount" name="payment_amount" class="gform_currency"
                               value="<?php
                               echo esc_attr($payment_amount) ?>">
                    </td>
                </tr>
                <tr>
                    <td>Transaction ID:<?php
                        gform_tooltip('dpo_group_edit_payment_transaction_id') ?></td>
                    <td>
                        <input type="text" id="dpo_group_transaction_id" name="dpo_group_transaction_id"
                               value="<?php
                               echo  esc_attr($transaction_id) ?>">
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    public function admin_update_payment($form, $lead_id)
    {
        check_admin_referer('gforms_save_entry', 'gforms_save_entry');

        // Update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
        $form_action = strtolower(rgpost('save'));
        if (!$this->is_payment_gateway($lead_id) || $form_action != 'update') {
            return;
        }
        // Get lead
        $lead = GFFormsModel::get_lead($lead_id);

        // Check if current payment status is processing
        if ($lead['payment_status'] != 'Processing') {
            return;
        }

        // Get payment fields to update
        $payment_status = '';

        if (isset($_POST['payment_status'])) {
            $payment_status = sanitize_text_field(wp_unslash($_POST['payment_status']));
        }
        // When updating, payment status may not be editable, if no value in post, set to lead payment status
        if (empty($payment_status)) {
            $payment_status = $lead['payment_status'];
        }

        $payment_amount      = GFCommon::to_number(rgpost('payment_amount'));
        $payment_transaction = rgpost('dpo_group_transaction_id');
        $payment_date        = rgpost('payment_date');
        if (empty($payment_date)) {
            $payment_date = gmdate(self::DATE_FORMAT);
        } else {
            // Format date entered by user
            $payment_date = gmdate(self::DATE_FORMAT, strtotime($payment_date));
        }

        global $current_user;
        $user_id   = 0;
        $user_name = 'System';
        if ($current_user && $user_data = get_userdata($current_user->ID)) {
            $user_id   = $current_user->ID;
            $user_name = $user_data->display_name;
        }

        $lead['payment_status'] = $payment_status;
        $lead['payment_amount'] = $payment_amount;
        $lead['payment_date']   = $payment_date;
        $lead['transaction_id'] = $payment_transaction;

        // If payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
        if (($payment_status == 'Approved' || $payment_status == 'Paid') && !$lead['is_fulfilled']) {
            $action['id']             = $payment_transaction;
            $action['type']           = 'complete_payment';
            $action['transaction_id'] = $payment_transaction;
            $action['amount']         = $payment_amount;
            $action['entry_id']       = $lead['id'];

            $this->complete_payment($lead, $action);
            $this->fulfill_order($lead, $payment_transaction, $payment_amount);
        }
        // Update lead, add a note
        GFAPI::update_entry($lead);
        GFFormsModel::add_note(
            $lead['id'],
            $user_id,
            $user_name,
            sprintf(
                    // translators: %s is the payment status, %2s is the payment amount, %3s is the transaction ID, %4s is the payment date.
                __(
                    'Payment information was manually updated. Status: %s. Amount: %2s. Transaction Id: %3s. Date: %4s',
                    'gravity-forms-dpo-group-plugin'
                ),
                $lead['payment_status'],
                GFCommon::to_money($lead['payment_amount'], $lead['currency']),
                $payment_transaction,
                $lead['payment_date']
            )
        );
    }

    public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
    {
        if (!$feed) {
            $feed = $this->get_payment_feed($entry);
        }

        $form = GFFormsModel::get_form_meta($entry['form_id']);
        if (rgars($feed, 'meta/delayPost')) {
            $this->log_debug(__METHOD__ . '(): Creating post.');
            $entry['post_id'] = GFFormsModel::create_post($form, $entry);
            $this->log_debug(__METHOD__ . '(): Post created.');
        }

        // Sending notifications
        GFAPI::send_notifications($form, $entry, 'form_submission');

        do_action('gform_dpo_group_fulfillment', $entry, $feed, $transaction_id, $amount);
        if (has_filter('gform_dpo_group_fulfillment')) {
            $this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_dpo_group_fulfillment.');
        }
    }

    public function dpo_group_fulfillment($entry, $dpo_group_config, $transaction_id, $amount): bool
    {
        // No need to do anything for DPO_Group when it runs this function, ignore
        return false;
    }

    public function upgrade($previous_version)
    {
        $previous_is_pre_addon_framework = version_compare($previous_version, '1.0', '<');

        if ($previous_is_pre_addon_framework) {
            // Copy plugin settings
            $this->copy_settings();

            // Copy existing feeds to new table
            $this->copy_feeds();

            // Copy existing DPO Pay transactions to new table
            $this->copy_transactions();

            // Updating payment_gateway entry meta to 'gravity-forms-dpo-group' from 'DPO Pay'
            $this->update_payment_gateway();

            // Updating entry status from 'Approved' to 'Paid'
            $this->update_lead();
        }
    }

    public function update_feed_id($old_feed_id, $new_feed_id)
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='dpo_group_feed_id' AND meta_value=%s",
            $new_feed_id,
            $old_feed_id
        );
        $wpdb->query($sql);
    }

    public function add_legacy_meta($new_meta, $old_feed)
    {
        $known_meta_keys = array(
            'email',
            'mode',
            'type',
            'style',
            'continue_text',
            'cancel_url',
            'disable_note',
            'disable_shipping',
            'recurring_amount_field',
            'recurring_times',
            'recurring_retry',
            'billing_cycle_number',
            'billing_cycle_type',
            'trial_period_enabled',
            'trial_amount',
            'trial_period_number',
            'trial_period_type',
            'delay_post',
            'update_post_action',
            'delay_notifications',
            'selected_notifications',
            'dpo_group_conditional_enabled',
            'dpo_group_conditional_field_id',
            'dpo_group_conditional_operator',
            'dpo_group_conditional_value',
            'customer_fields',
        );

        foreach ($old_feed['meta'] as $key => $value) {
            if (!in_array($key, $known_meta_keys)) {
                $new_meta[$key] = $value;
            }
        }

        return $new_meta;
    }

    public function update_payment_gateway()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead_meta SET meta_value=%s WHERE meta_key='payment_gateway' AND meta_value='dpo_group'",
            $this->_slug
        );
        $wpdb->query($sql);
    }

    //------ FOR BACKWARDS COMPATIBILITY ----------------------//
    // Change data when upgrading from legacy DPO_Group

    public function update_lead()
    {
        global $wpdb;
        $sql = $wpdb->prepare(
            "UPDATE {$wpdb->prefix}rg_lead
             SET payment_status='Paid', payment_method='DPO Pay'
             WHERE payment_status='Approved'
                    AND ID IN (
                        SELECT lead_id FROM {$wpdb->prefix}rg_lead_meta WHERE meta_key='payment_gateway' AND meta_value=%s
                    )",
            $this->_slug
        );

        $wpdb->query($sql);
    }

    public function copy_settings()
    {
        // Copy plugin settings
        $old_settings = get_option('gf_dpo_group_configured');
        $new_settings = array('gf_dpo_group_configured' => $old_settings);
        $this->update_plugin_settings($new_settings);
    }

    public function copy_feeds()
    {
        // Get feeds
        $old_feeds = $this->get_old_feeds();

        if ($old_feeds) {
            $counter = 1;
            foreach ($old_feeds as $old_feed) {
                $feed_name       = 'Feed ' . $counter;
                $form_id         = $old_feed['form_id'];
                $is_active       = $old_feed['is_active'];
                $customer_fields = $old_feed['meta']['customer_fields'];

                $new_meta = array(
                    'feedName'                     => $feed_name,
                    'DPO_GroupMerchantId'          => rgar($old_feed['meta'], 'DPO_GroupMerchantId'),
                    'DPO_GroupMerchantKey'         => rgar($old_feed['meta'], 'DPO_GroupMerchantKey'),
                    'useCustomConfirmationPage'    => rgar($old_feed['meta'], 'useCustomConfirmationPage'),
                    'successPageUrl'               => rgar($old_feed['meta'], 'successPageUrl'),
                    'failedPageUrl'                => rgar($old_feed['meta'], 'failedPageUrl'),
                    'mode'                         => rgar($old_feed['meta'], 'mode'),
                    'transactionType'              => rgar($old_feed['meta'], 'type'),
                    'type'                         => rgar($old_feed['meta'], 'type'),
                    // For backwards compatibility of the delayed payment feature
                    'pageStyle'                    => rgar($old_feed['meta'], 'style'),
                    'continueText'                 => rgar($old_feed['meta'], 'continue_text'),
                    'cancelUrl'                    => rgar($old_feed['meta'], 'cancel_url'),
                    'disableNote'                  => rgar($old_feed['meta'], 'disable_note'),
                    'disableShipping'              => rgar($old_feed['meta'], 'disable_shipping'),
                    'recurringAmount'              => rgar(
                        $old_feed['meta'],
                        'recurring_amount_field'
                    ) == 'all' ? 'form_total' : rgar(
                        $old_feed['meta'],
                        'recurring_amount_field'
                    ),
                    'recurring_amount_field'       => rgar($old_feed['meta'], 'recurring_amount_field'),
                    // For backwards compatibility of the delayed payment feature
                    'recurringTimes'               => rgar($old_feed['meta'], 'recurring_times'),
                    'recurringRetry'               => rgar($old_feed['meta'], 'recurring_retry'),
                    'paymentAmount'                => 'form_total',
                    'billingCycle_length'          => rgar($old_feed['meta'], 'billing_cycle_number'),
                    'billingCycle_unit'            => $this->convert_interval(
                        rgar($old_feed['meta'], 'billing_cycle_type'),
                        'text'
                    ),
                    'trial_enabled'                => rgar($old_feed['meta'], 'trial_period_enabled'),
                    'trial_product'                => 'enter_amount',
                    'trial_amount'                 => rgar($old_feed['meta'], 'trial_amount'),
                    'trialPeriod_length'           => rgar($old_feed['meta'], 'trial_period_number'),
                    'trialPeriod_unit'             => $this->convert_interval(
                        rgar($old_feed['meta'], 'trial_period_type'),
                        'text'
                    ),
                    'delayPost'                    => rgar($old_feed['meta'], 'delay_post'),
                    'change_post_status'           => rgar($old_feed['meta'], 'update_post_action') ? '1' : '0',
                    'update_post_action'           => rgar($old_feed['meta'], 'update_post_action'),
                    'delayNotification'            => rgar($old_feed['meta'], 'delay_notifications'),
                    'selectedNotifications'        => rgar($old_feed['meta'], 'selected_notifications'),
                    'billingInformation_firstName' => rgar($customer_fields, 'first_name'),
                    'billingInformation_lastName'  => rgar($customer_fields, 'last_name'),
                    'billingInformation_email'     => rgar($customer_fields, 'email'),
                    'billingInformation_address'   => rgar($customer_fields, 'address1'),
                    'billingInformation_address2'  => rgar($customer_fields, 'address2'),
                    'billingInformation_city'      => rgar($customer_fields, 'city'),
                    'billingInformation_state'     => rgar($customer_fields, 'state'),
                    'billingInformation_zip'       => rgar($customer_fields, 'zip'),
                    'billingInformation_country'   => rgar($customer_fields, 'country'),
                );

                $new_meta = $this->add_legacy_meta($new_meta, $old_feed);

                // Add conditional logic
                $conditional_enabled = rgar($old_feed['meta'], 'dpo_group_conditional_enabled');
                if ($conditional_enabled) {
                    $new_meta['feed_condition_conditional_logic']        = 1;
                    $new_meta['feed_condition_conditional_logic_object'] = array(
                        'conditionalLogic' => array(
                            'actionType' => 'show',
                            'logicType'  => 'all',
                            'rules'      => array(
                                array(
                                    'fieldId'  => rgar($old_feed['meta'], 'dpo_group_conditional_field_id'),
                                    'operator' => rgar($old_feed['meta'], 'dpo_group_conditional_operator'),
                                    'value'    => rgar($old_feed['meta'], 'dpo_group_conditional_value'),
                                ),
                            ),
                        ),
                    );
                } else {
                    $new_meta['feed_condition_conditional_logic'] = 0;
                }

                $new_feed_id = $this->insert_feed($form_id, $is_active, $new_meta);
                $this->update_feed_id($old_feed['id'], $new_feed_id);

                $counter++;
            }
        }
    }

    public function copy_transactions()
    {
        // Copy transactions from the DPO Pay transaction table to the add payment transaction table
        global $wpdb;
        $old_table_name = $this->get_old_transaction_table_name();
        $this->log_debug(__METHOD__ . '(): Copying old DPO Pay transactions into new table structure.');

        $new_table_name = $this->get_new_transaction_table_name();

        $sql = "INSERT INTO {$new_table_name} (lead_id, transaction_type, transaction_id, is_recurring, amount, date_created)
                    SELECT entry_id, transaction_type, transaction_id, is_renewal, amount, date_created FROM {$old_table_name}";

        $wpdb->query($sql);

        $this->log_debug(__METHOD__ . "(): transactions: {$wpdb->rows_affected} rows were added.");
    }

    public function get_old_transaction_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'rg_dpo_group_transaction';
    }

    public function get_new_transaction_table_name(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'gf_addon_payment_transaction';
    }

    public function get_old_feeds(): array
    {
        global $wpdb;
        $table_name = $wpdb->prefix . 'rg_dpo_group';

        $form_table_name = GFFormsModel::get_form_table_name();
        $sql             = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                    FROM {$table_name} s
                    INNER JOIN {$form_table_name} f ON s.form_id = f.id";

        $this->log_debug(__METHOD__ . "(): getting old feeds: {$sql}");

        /** @noinspection PhpUndefinedConstantInspection */
        $results = $wpdb->get_results($sql, ARRAY_A);

        $this->log_debug(__METHOD__ . "(): error?: {$wpdb->last_error}");

        $count = sizeof($results);

        $this->log_debug(__METHOD__ . "(): count: {$count}");

        for ($i = 0; $i < $count; $i++) {
            $results[$i]['meta'] = maybe_unserialize($results[$i]['meta']);
        }

        return $results;
    }


    protected function dpo_group_add_query_arg($query, $url): string
    {
        $myurl = $url;
        foreach ($query as $item => $value) {
            $myurl .= '?' . $item . '=' . $value;
        }

        return $myurl;
    }

    protected function process_get($get)
    {
        $utilities = new DpoGfUtilities();
        $returns   = [];
        if (isset($get['eid'])) {
            $s    = $get['eid'];
            $gets = explode('?', $s);

            foreach ($gets as $item) {
                $parts = explode('=', $item);

                if (count($parts) === 1) {
                    $returns['id'] = DPO_Group_GF_encryption($parts[0], 'd');
                } else {
                    [$key, $value] = $parts;

                    $decryptedValue = DPO_Group_GF_encryption($value, 'd');
                    match ($key) {
                        'mode' => $returns[$key] = $value,
                        'eid' => [
                            $returns[$key] = $decryptedValue,
                            $returns[$key . 'u'] = $value,
                        ],
                        default => $returns[$key] = $decryptedValue,
                    };
                }
            }

            return $utilities->setReturns($returns);
        }


        if (isset($get['gf_dpo_group_return'])) {
            $s    = $get['gf_dpo_group_return'];
            $gets = explode('?', $s);

            foreach ($gets as $item) {
                $parts = explode('=', $item);

                if (count($parts) === 1) {
                    $returns['gf_dpo_group_return'] = DPO_Group_GF_encryption($parts[0], 'd');
                } else {
                    [$key, $value] = $parts;

                    $decryptedValue = DPO_Group_GF_encryption($value, 'd');
                    match ($key) {
                        'mode' => $returns[$key] = $value,
                        'eid' => [
                            $returns[$key] = $decryptedValue,
                            $returns[$key . 'u'] = $value,
                        ],
                        default => $returns[$key] = $decryptedValue,
                    };
                }
            }

            return $utilities->setReturns($returns);
        }
    }

    private function __clone()
    {
        /* Do nothing */
    }

    private function get_dpo_group_feed_by_entry($entry_id): bool
    {
        $feed_id = gform_get_meta($entry_id, 'dpo_group_feed_id');
        $feed    = $this->get_feed($feed_id);

        return !empty($feed) ? $feed : false;
    }

    // This function kept static for backwards compatibility

    private function get_pending_reason($code)
    {
        if (strtolower($code) == 'address') {
            return __(
                'The payment is pending because your customer did not include a confirmed shipping address and your Payment Receiving Preferences is set to allow you to manually accept or deny each of these payments. To change your preference, go to the Preferences section of your Profile.',
                'gravity-forms-dpo-group-plugin'
            );
        }

        return empty($code) ? __(
            'Reason has not been specified. For more information, contact DPO Pay Customer Service.',
            'gravity-forms-dpo-group-plugin'
        ) : $code;
    }

    // This function kept static for backwards compatibility
    // This needs to be here until all add-ons are on the framework, otherwise they look for this function
    private function is_valid_initial_payment_amount($entry_id, $amount_paid): bool
    {
        // Get amount initially sent to DPO Pay
        $amount_sent = gform_get_meta($entry_id, 'payment_amount');
        if (empty($amount_sent)) {
            return true;
        }

        $epsilon    = 0.00001;
        $is_equal   = abs(floatval($amount_paid) - floatval($amount_sent)) < $epsilon;
        $is_greater = floatval($amount_paid) > floatval($amount_sent);

        // Initial payment is valid if it is equal to or greater than product/subscription amount
        if ($is_equal || $is_greater) {
            return true;
        }

        return false;
    }

    //------------------------------------------------------
}
