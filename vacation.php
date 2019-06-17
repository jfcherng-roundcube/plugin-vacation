<?php
/*
 * Vacation plugin that adds a new tab to the settings section
 * to enable forward / out of office replies.
 *
 * @package    plugins
 * @uses       rcube_plugin
 * @author     Jasper Slits <jaspersl@gmail.com>
 * @revise     Jack Cherng <jfcherng@gmail.com>
 * @version    1.9
 * @license    GPL
 * @link       https://sourceforge.net/projects/rcubevacation/
 * @todo       See README.TXT
 */

// load required dependencies
require 'lib/vacationdriver.class.php';
require 'lib/dotforward.class.php';
require 'lib/vacationfactory.class.php';
require 'lib/VacationConfig.class.php';

class vacation extends rcube_plugin
{
    public $task = 'settings';
    private $v = '';
    private $inicfg = '';
    private $enableVacationTab = true;
    private $vcObject;

    public function init()
    {
        $this->add_texts('localization/', true);
        $this->load_config();

        $this->inicfg = $this->readIniConfig();

        // don't proceed if the current host does not support vacation
        if (!$this->enableVacationTab) {
            return false;
        }

        $this->v = VacationDriverFactory::Create($this->inicfg['driver']);
        $this->v->setIniConfig($this->inicfg);

        $this->add_hook('settings_actions', [$this, 'settingsActions']);
        // the vacation_aliases method is defined in vacationdriver.class.php so use $this->v here
        $this->register_action('plugin.vacation_aliases', [$this->v, 'vacation_aliases']);
        $this->register_action('plugin.' . __CLASS__, [$this, 'vacation_init']);
        $this->register_action('plugin.vacation-save', [$this, 'vacation_save']);
        $this->include_script('vacation.js');

        $this->rcmail = rcmail::get_instance();
        $this->user = $this->rcmail->user;
        $this->identity = $this->user->get_identity();

        // forward settings are shared by ftp,sshftp and setuid driver
        $this->v->setDotForwardConfig($this->inicfg['driver'], $this->vcObject->getDotForwardCfg());
    }

    public function settingsActions(array $args)
    {
        $args['actions'][] = [
            'action' => 'plugin.' . __CLASS__,
            'class' => 'vacation',
            'label' => 'vacation',
            'domain' => 'vacation',
        ];

        return $args;
    }

    public function vacation_init()
    {
        $this->register_handler('plugin.body', [$this, 'vacation_form']);

        $this->rcmail->output->set_pagetitle($this->gettext('vacation'));
        $this->rcmail->output->send('plugin');
    }

    public function vacation_save()
    {
        // initialize the driver
        $this->v->init();

        if ($this->v->save()) {
            $this->rcmail->output->show_message($this->gettext('save_settings_success'), 'confirmation');
        } else {
            $this->rcmail->output->show_message($this->gettext('save_settings_fail'), 'error');
        }
        $this->vacation_init();
    }

    public function vacation_form()
    {
        // initialize the driver
        $this->v->init();
        $settings = $this->v->_get();

        // load default body & subject if present.
        if (empty($settings['subject']) && ($defaults = $this->v->loadDefaults())) {
            $settings['subject'] = $defaults['subject'];
            $settings['body'] = $defaults['body'];
        }

        $this->rcmail->output->set_env('product_name', $this->rcmail->config->get('product_name'));
        // return the complete edit form as table
        $out = '<fieldset><legend>' . $this->gettext('vacation') . ' ::: ' . $this->rcmail->user->data['username'] . '</legend>';
        // show autoresponder properties

        if ($this->v->useVacationAutoReply()) {
            // auto-reply enabled
            $field_id = 'vacation_enabled';
            $input_autoresponderactive = new html_checkbox([
                'name' => '_vacation_enabled',
                'id' => $field_id,
                'value' => 1,
            ]);
            $out .= \sprintf(
                '<p><label for="%s">%s</label>&nbsp;%s</p>',
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreply_enable')),
                $input_autoresponderactive->show($settings['enabled'])
            );

            // subject
            $field_id = 'vacation_subject';
            $input_autorespondersubject = new html_inputfield([
                'name' => '_vacation_subject',
                'id' => $field_id,
                'size' => 90,
            ]);
            $out .= \sprintf(
                '<p><label for="%s">%s</label><br/>%s</p>',
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreply_subject')),
                $input_autorespondersubject->show($settings['subject'])
            );

            // out of office body
            $field_id = 'vacation_body';
            $input_autoresponderbody = new html_textarea([
                'name' => '_vacation_body',
                'id' => $field_id,
                'cols' => 60,
                'rows' => 10,
            ]);
            $out .= \sprintf(
                '<p><label for="%s">%s</label><br/>%s</p>',
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('autoreply_message')),
                $input_autoresponderbody->show($settings['body'])
            );
        }

        // we only use aliases for .forward and only if it's enabled in the config
        if ($this->v->useAliases()) {
            $size = 0;

            // if there are no multiple identities, hide the button and add increase the size of the textfield
            $hasMultipleIdentities = $this->v->vacation_aliases('buttoncheck');
            if ($hasMultipleIdentities == '') {
                $size = 15;
            }

            $field_id = 'vacation_aliases';
            $input_autoresponderalias = new html_inputfield([
                'name' => '_vacation_aliases',
                'id' => $field_id,
                'size' => 75 + $size,
            ]);
            $out .= '<p>' . $this->gettext('separate_alias') . '</p>';

            // inputfield with button
            $out .= \sprintf(
                '<p><label for="%s">%s</label>&nbsp;%s',
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('aliases')),
                $input_autoresponderalias->show($settings['aliases'])
            );
            if ($hasMultipleIdentities != '') {
                $out .= \sprintf(
                    '<input type="button" id="aliaslink" class="button" value="%s"/>',
                    rcube_utils::rep_specialchars_output($this->gettext('aliasesbutton'))
                );
            }
            $out .= '</p>';
        }
        $out .= '</fieldset><fieldset><legend>' . $this->gettext('forward') . '</legend>';

        // keep a local copy of the mail
        $field_id = 'vacation_keepcopy';
        $input_localcopy = new html_checkbox([
            'name' => '_vacation_keepcopy',
            'id' => $field_id,
            'value' => 1,
        ]);
        $out .= \sprintf(
            '<p><label for="%s">%s</label>&nbsp;%s</p>',
            $field_id,
            rcube_utils::rep_specialchars_output($this->gettext('keep_copy')),
            $input_localcopy->show($settings['keepcopy'])
        );

        // information on the forward in a seperate fieldset
        if (!isset($this->inicfg['disable_forward']) || (isset($this->inicfg['disable_forward']) && $this->inicfg['disable_forward'] == false)) {
            // forward mail to another account
            $field_id = 'vacation_forward';
            $input_autoresponderforward = new html_textarea([
                'name' => '_vacation_forward',
                'id' => $field_id,
                'cols' => 60,
                'rows' => 8,
            ]);
            $out .= \sprintf(
                '<p><label for="%s">%s</label><br/>%s</p>',
                $field_id,
                rcube_utils::rep_specialchars_output($this->gettext('forward_addresses')),
                $input_autoresponderforward->show($settings['forward'])
            );
        }
        $out .= '</fieldset>';

        // the submit button
        $out .= html::p(
            null,
            $this->rcmail->output->button([
                'command' => 'plugin.vacation-save',
                'type' => 'input',
                'class' => 'button mainaction',
                'label' => 'save',
            ])
        );

        $this->rcmail->output->add_gui_object('vacationform', 'vacationform');
        $out = $this->rcmail->output->form_tag([
            'id' => 'vacationform',
            'name' => 'vacationform',
            'method' => 'post',
            'class' => 'propform',
            'action' => './?_task=settings&_action=plugin.vacation-save',
        ], $out);

        return html::div([
            'class' => 'boxcontent formcontent',
            'style' => 'overflow: auto;',
        ], $out);
    }

    // parse config.ini and get configuration for current host
    private function readIniConfig()
    {
        $this->vcObject = new VacationConfig();
        $this->vcObject->setCurrentHost($_SESSION['imap_host']);
        $config = $this->vcObject->getCurrentConfig();

        if (($errorStr = $this->vcObject->hasError()) !== false) {
            rcube::raise_error(
                [
                    'code' => 601,
                    'type' => 'php',
                    'file' => __FILE__,
                    'message' => \sprintf(
                        'Vacation plugin: %s',
                        $errorStr
                    ),
                ],
                true,
                true
            );
        }
        $this->enableVacationTab = $this->vcObject->hasVacationEnabled();

        return $config;
    }
}
