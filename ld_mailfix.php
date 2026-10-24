<?php
/**
 * LD - Mail Fix
 *
 * Force le nom de domaine annoncé dans la commande SMTP EHLO.
 * Par défaut, le mailer de PrestaShop s'annonce en "[127.0.0.1]", ce que certains relais
 * (dont smtp-relay.gmail.com) refusent avec l'erreur "421 4.7.0 Try again later (EHLO)".
 *
 * L'override de MailCore est généré à l'installation à partir du classes/Mail.php
 * du PrestaShop installé : après une mise à jour de PrestaShop, il suffit de cliquer
 * sur « Régénérer l'override » dans la configuration du module.
 *
 * @author    Linra Digital
 * @license   Propriétaire
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/LdMailFixGenerator.php';

class Ld_Mailfix extends Module
{
    const CONFIG_DOMAIN = 'LDMAILFIX_DOMAIN';
    const CONFIG_HASH = 'LDMAILFIX_SOURCE_HASH';
    const TRANS_DOMAIN = 'Modules.Ldmailfix.Admin';

    public function __construct()
    {
        $this->name = 'ld_mailfix';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Linra Digital';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('LD - FIX GMAIL WORKSPACE', [], self::TRANS_DOMAIN);
        $this->description = $this->trans(
            'Force le nom de domaine annoncé au serveur SMTP (EHLO). Corrige l\'erreur 421 4.7.0 du relais SMTP Google Workspace.',
            [],
            self::TRANS_DOMAIN
        );
        $this->confirmUninstall = $this->trans(
            'Les e-mails seront de nouveau envoyés avec un EHLO "[127.0.0.1]". Continuer ?',
            [],
            self::TRANS_DOMAIN
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Cycle de vie                                                      */
    /* ------------------------------------------------------------------ */

    public function install()
    {
        // L'override doit exister dans le dossier du module AVANT parent::install(),
        // qui le copie dans /override/classes/Mail.php.
        if (!$this->generateOverride()) {
            return false;
        }

        if (!Configuration::get(self::CONFIG_DOMAIN)) {
            Configuration::updateValue(self::CONFIG_DOMAIN, $this->getDefaultDomain());
        }

        return parent::install();
    }

    public function uninstall()
    {
        // parent::uninstall() retire l'override grâce au fichier généré : on le supprime après.
        if (!parent::uninstall()) {
            return false;
        }

        $file = $this->getGeneratedOverridePath();
        if (is_file($file)) {
            @unlink($file);
        }
        Configuration::deleteByName(self::CONFIG_DOMAIN);
        Configuration::deleteByName(self::CONFIG_HASH);

        return true;
    }

    /**
     * Appelé par « Réinitialiser » (en conservant les données) : régénère l'override.
     */
    public function reset()
    {
        return $this->regenerateOverride();
    }

    /* ------------------------------------------------------------------ */
    /*  Override                                                          */
    /* ------------------------------------------------------------------ */

    private function getGeneratedOverridePath()
    {
        return $this->getLocalPath() . 'override/classes/Mail.php';
    }

    /**
     * Génère override/classes/Mail.php dans le dossier du module à partir du core.
     */
    private function generateOverride()
    {
        $core = _PS_CLASS_DIR_ . 'Mail.php';
        $source = is_readable($core) ? file_get_contents($core) : false;
        if ($source === false) {
            $this->_errors[] = $this->trans('Impossible de lire %file%.', ['%file%' => $core], self::TRANS_DOMAIN);

            return false;
        }

        try {
            $generator = new LdMailFixGenerator();
            $result = $generator->generate($source);
        } catch (Exception $e) {
            $this->_errors[] = $this->trans('Génération de l\'override impossible : %error%', ['%error%' => $e->getMessage()], self::TRANS_DOMAIN);

            return false;
        }

        $dir = dirname($this->getGeneratedOverridePath());
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            $this->_errors[] = $this->trans('Impossible de créer le dossier %dir% (droits d\'écriture ?).', ['%dir%' => $dir], self::TRANS_DOMAIN);

            return false;
        }
        if (@file_put_contents($this->getGeneratedOverridePath(), $result['code']) === false) {
            $this->_errors[] = $this->trans('Impossible d\'écrire %file% (droits d\'écriture ?).', ['%file%' => $this->getGeneratedOverridePath()], self::TRANS_DOMAIN);

            return false;
        }

        Configuration::updateValue(self::CONFIG_HASH, $result['hash']);

        return true;
    }

    /**
     * Retire l'override installé, le régénère depuis le core actuel et le réinstalle.
     */
    private function regenerateOverride()
    {
        $overridesEnabled = !Configuration::get('PS_DISABLE_MODULE_OVERRIDES');

        if ($overridesEnabled && is_file($this->getGeneratedOverridePath())) {
            $this->uninstallOverrides();
        }

        if (!$this->generateOverride()) {
            return false;
        }

        if ($overridesEnabled) {
            try {
                if (!$this->installOverrides()) {
                    $this->_errors[] = $this->trans('L\'installation de l\'override a échoué.', [], self::TRANS_DOMAIN);

                    return false;
                }
            } catch (Exception $e) {
                $this->_errors[] = $e->getMessage();

                return false;
            }
        }

        return true;
    }

    private function isOverrideInstalled()
    {
        $file = _PS_OVERRIDE_DIR_ . 'classes/Mail.php';

        return is_file($file) && strpos((string) file_get_contents($file), LdMailFixGenerator::HELPER_METHOD) !== false;
    }

    /**
     * Le classes/Mail.php du core a-t-il changé depuis la génération (mise à jour de PrestaShop) ?
     *
     * @return bool|null null si indéterminable
     */
    private function isCoreChanged()
    {
        $stored = (string) Configuration::get(self::CONFIG_HASH);
        if ($stored === '') {
            return null;
        }
        try {
            $generator = new LdMailFixGenerator();
            $result = $generator->generate((string) file_get_contents(_PS_CLASS_DIR_ . 'Mail.php'));
        } catch (Exception $e) {
            return true;
        }

        return $result['hash'] !== $stored;
    }

    /* ------------------------------------------------------------------ */
    /*  Domaine                                                           */
    /* ------------------------------------------------------------------ */

    private function isValidDomain($domain)
    {
        return (bool) preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', (string) $domain);
    }

    private function getDefaultDomain()
    {
        $domain = strtolower(trim((string) Configuration::get('PS_SHOP_DOMAIN')));
        $domain = preg_replace('/:\d+$/', '', $domain);

        return $this->isValidDomain($domain) ? $domain : '';
    }

    /* ------------------------------------------------------------------ */
    /*  Configuration                                                     */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitLdMailFix')) {
            $domain = strtolower(trim((string) Tools::getValue(self::CONFIG_DOMAIN)));
            if ($domain !== '' && !$this->isValidDomain($domain)) {
                $output .= $this->displayError($this->trans('Nom de domaine invalide : utilisez un nom complet, par exemple boutique.fr.', [], self::TRANS_DOMAIN));
            } else {
                Configuration::updateValue(self::CONFIG_DOMAIN, $domain);
                $output .= $this->displayConfirmation($this->trans('Domaine enregistré.', [], self::TRANS_DOMAIN));
            }
        }

        if (Tools::isSubmit('submitLdMailFixRegenerate')) {
            $this->_errors = [];
            if ($this->regenerateOverride()) {
                $output .= $this->displayConfirmation($this->trans('Override régénéré à partir du classes/Mail.php actuel.', [], self::TRANS_DOMAIN));
            } else {
                $output .= $this->displayError(implode('<br>', array_map('htmlspecialchars', $this->_errors)));
            }
        }

        if (Tools::isSubmit('submitLdMailFixTest')) {
            $output .= $this->renderEhloTest((string) Configuration::get(self::CONFIG_DOMAIN));
        }

        return $output . $this->renderStatus() . $this->renderForm();
    }

    private function renderStatus()
    {
        $items = [];

        $items[] = $this->isOverrideInstalled()
            ? ['success', $this->trans('Override de Mail installé.', [], self::TRANS_DOMAIN)]
            : ['danger', $this->trans('Override de Mail absent de /override/classes/Mail.php : cliquez sur « Régénérer l\'override ».', [], self::TRANS_DOMAIN)];

        if (Configuration::get('PS_DISABLE_OVERRIDES')) {
            $items[] = ['danger', $this->trans('Les overrides sont désactivés (Paramètres avancés > Performances) : le correctif est inactif.', [], self::TRANS_DOMAIN)];
        }

        $changed = $this->isCoreChanged();
        if ($changed === true) {
            $items[] = ['warning', $this->trans('classes/Mail.php a changé depuis la génération (mise à jour de PrestaShop ?) : cliquez sur « Régénérer l\'override ».', [], self::TRANS_DOMAIN)];
        } elseif ($changed === false) {
            $items[] = ['success', $this->trans('Override à jour par rapport au core.', [], self::TRANS_DOMAIN)];
        }

        if ((int) Configuration::get('PS_MAIL_METHOD') !== 2) {
            $items[] = ['info', $this->trans('La boutique n\'envoie pas ses e-mails via SMTP : le module n\'a pas d\'effet.', [], self::TRANS_DOMAIN)];
        }

        if ((string) Configuration::get(self::CONFIG_DOMAIN) === '') {
            $items[] = ['warning', $this->trans('Aucun domaine défini : PrestaShop s\'annonce en "[127.0.0.1]".', [], self::TRANS_DOMAIN)];
        }

        $html = '<div class="panel"><h3><i class="icon-info-circle"></i> '
            . htmlspecialchars($this->trans('État', [], self::TRANS_DOMAIN)) . '</h3>';
        foreach ($items as $item) {
            $html .= '<div class="alert alert-' . $item[0] . '" style="margin-bottom:8px">' . htmlspecialchars($item[1]) . '</div>';
        }

        return $html . '</div>';
    }

    private function renderForm()
    {
        $fields = [
            'form' => [
                'legend' => [
                    'title' => $this->trans('Nom annoncé au serveur SMTP (EHLO)', [], self::TRANS_DOMAIN),
                    'icon' => 'icon-envelope',
                ],
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('Nom de domaine', [], self::TRANS_DOMAIN),
                        'name' => self::CONFIG_DOMAIN,
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->trans(
                            'Nom complet (FQDN) envoyé dans la commande EHLO, par exemple le domaine de la boutique ou le reverse DNS du serveur. Laisser vide pour revenir au comportement de PrestaShop.',
                            [],
                            self::TRANS_DOMAIN
                        ),
                    ],
                ],
                'submit' => [
                    'title' => $this->trans('Enregistrer', [], self::TRANS_DOMAIN),
                    'name' => 'submitLdMailFix',
                ],
                'buttons' => [
                    [
                        'type' => 'submit',
                        'name' => 'submitLdMailFixTest',
                        'title' => $this->trans('Tester l\'EHLO', [], self::TRANS_DOMAIN),
                        'icon' => 'process-icon-envelope',
                        'class' => 'pull-right',
                    ],
                    [
                        'type' => 'submit',
                        'name' => 'submitLdMailFixRegenerate',
                        'title' => $this->trans('Régénérer l\'override', [], self::TRANS_DOMAIN),
                        'icon' => 'process-icon-refresh',
                        'class' => 'pull-right',
                    ],
                ],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->name;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitLdMailFix';
        $helper->fields_value = [
            self::CONFIG_DOMAIN => (string) Configuration::get(self::CONFIG_DOMAIN),
        ];

        return $helper->generateForm([$fields]);
    }

    /* ------------------------------------------------------------------ */
    /*  Test EHLO                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Ouvre une connexion au serveur SMTP configuré, envoie EHLO puis QUIT, et affiche l'échange.
     */
    private function renderEhloTest($domain)
    {
        $host = trim((string) Configuration::get('PS_MAIL_SERVER'));
        if ($host === '') {
            return $this->displayError($this->trans('Aucun serveur SMTP configuré dans Paramètres avancés > E-mail.', [], self::TRANS_DOMAIN));
        }
        $port = (int) Configuration::get('PS_MAIL_SMTP_PORT');
        if ($port <= 0) {
            $port = 25;
        }
        $encryption = strtolower((string) Configuration::get('PS_MAIL_SMTP_ENCRYPTION'));
        $scheme = ($port === 465 || $encryption === 'ssl') ? 'ssl://' : 'tcp://';
        $ehloName = $domain !== '' ? $domain : '[127.0.0.1]';

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($scheme . $host . ':' . $port, $errno, $errstr, 10);
        if (!$socket) {
            return $this->displayError($this->trans(
                'Connexion à %server% impossible : %error%',
                ['%server%' => $host . ':' . $port, '%error%' => $errstr . ' (' . $errno . ')'],
                self::TRANS_DOMAIN
            ));
        }
        stream_set_timeout($socket, 10);

        $log = $this->readSmtpResponse($socket);
        fwrite($socket, 'EHLO ' . $ehloName . "\r\n");
        $response = $this->readSmtpResponse($socket);
        $log .= '> EHLO ' . $ehloName . "\n" . $response;
        @fwrite($socket, "QUIT\r\n");
        fclose($socket);

        $ok = strpos($response, '250') === 0;
        $title = $ok
            ? $this->trans('Le serveur accepte l\'EHLO « %name% ».', ['%name%' => $ehloName], self::TRANS_DOMAIN)
            : $this->trans('Le serveur refuse l\'EHLO « %name% ».', ['%name%' => $ehloName], self::TRANS_DOMAIN);

        return '<div class="alert alert-' . ($ok ? 'success' : 'danger') . '"><strong>' . htmlspecialchars($title) . '</strong>'
            . '<pre style="margin-top:10px;white-space:pre-wrap">' . htmlspecialchars($host . ':' . $port . "\n" . $log) . '</pre></div>';
    }

    private function readSmtpResponse($socket)
    {
        $out = '';
        while (($line = fgets($socket, 515)) !== false) {
            $out .= $line;
            // Dernière ligne d'une réponse multiligne : "250 ..." (espace en 4e position)
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return $out === '' ? "(aucune réponse)\n" : $out;
    }
}
