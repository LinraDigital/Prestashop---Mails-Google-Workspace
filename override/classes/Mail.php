<?php
/**
 * Override généré automatiquement par le module ld_mailfix — ne pas modifier à la main.
 * Pour le régénérer (après une mise à jour de PrestaShop) : réinitialiser le module.
 */
class Mail extends MailCore
{
    public static function send(
        $idLang,
        $template,
        $subject,
        $templateVars,
        $to,
        $toName = null,
        $from = null,
        $fromName = null,
        $fileAttachment = null,
        $mode_smtp = null,
        $templatePath = _PS_MAIL_DIR_,
        $die = false,
        $idShop = null,
        $bcc = null,
        $replyTo = null,
        $replyToName = ''
    ) {
        
        if (!$idShop) {
            $idShop = Context::getContext()->shop->id;
        }

        $hookBeforeEmailResult = Hook::exec(
            'actionEmailSendBefore',
            [
                'idLang' => &$idLang,
                'template' => &$template,
                'subject' => &$subject,
                'templateVars' => &$templateVars,
                'to' => &$to,
                'toName' => &$toName,
                'from' => &$from,
                'fromName' => &$fromName,
                'fileAttachment' => &$fileAttachment,
                'mode_smtp' => &$mode_smtp,
                'templatePath' => &$templatePath,
                'die' => &$die,
                'idShop' => &$idShop,
                'bcc' => &$bcc,
                'replyTo' => &$replyTo,
                'replyToName' => &$replyToName,
            ],
            null,
            true
        );

        if ($hookBeforeEmailResult === null) {
            $keepGoing = false;
        } else {
            $keepGoing = array_reduce(
                $hookBeforeEmailResult,
                function ($carry, $item) {
                    return ($item === false) ? false : $carry;
                },
                true
            );
        }
        if (!$keepGoing) {
            return true;
        }

        if (is_numeric($idShop) && $idShop) {
            $shop = new Shop((int) $idShop);
        }

        if (!isset($shop)) {
            self::dieOrLog($die, 'Error: parameter "idShop" is corrupted');

            return false;
        }

        $configuration = Configuration::getMultiple(
            [
                'PS_SHOP_EMAIL',
                'PS_MAIL_METHOD',
                'PS_MAIL_SERVER',
                'PS_MAIL_USER',
                'PS_MAIL_PASSWD',
                'PS_SHOP_NAME',
                'PS_MAIL_SMTP_ENCRYPTION',
                'PS_MAIL_SMTP_PORT',
                'PS_MAIL_TYPE',
                'PS_MAIL_DKIM_ENABLE',
                'PS_MAIL_DKIM_DOMAIN',
                'PS_MAIL_DKIM_SELECTOR',
                'PS_MAIL_DKIM_KEY',
            ],
            null,
            null,
            $idShop
        );

        if ($configuration['PS_MAIL_METHOD'] == self::METHOD_DISABLE) {
            return true;
        }

        Hook::exec(
            'sendMailAlterTemplateVars',
            [
                'template' => $template,
                'template_vars' => &$templateVars,
            ]
        );

        if (!isset($from) || !Validate::isEmail($from)) {
            $from = $configuration['PS_SHOP_EMAIL'];
        }

        if (!Validate::isEmail($from)) {
            $from = null;
        }

        if (!isset($fromName) || !Validate::isMailName($fromName)) {
            $fromName = $configuration['PS_SHOP_NAME'];
        }

        if (!Validate::isMailName($fromName)) {
            $fromName = null;
        }

        if (!is_array($to) && !Validate::isEmail($to)) {
            self::dieOrLog($die, 'Error: parameter "to" is corrupted');

            return false;
        }

        if (null !== $bcc && !is_array($bcc) && !Validate::isEmail($bcc)) {
            self::dieOrLog($die, 'Error: parameter "bcc" is corrupted');
            $bcc = null;
        }

        if (!is_array($templateVars)) {
            $templateVars = [];
        }

        if (is_string($toName) && !empty($toName) && !Validate::isMailName($toName)) {
            $toName = null;
        }

        if (!Validate::isTplName($template)) {
            self::dieOrLog($die, 'Error: invalid e-mail template');

            return false;
        }

        if (!Validate::isMailSubject($subject)) {
            self::dieOrLog($die, 'Error: invalid e-mail subject');

            return false;
        }

        $email = new \Symfony\Component\Mime\Email();

        if (is_array($to)) {
            foreach ($to as $key => $addr) {
                $addr = trim($addr);
                if (!Validate::isEmail($addr)) {
                    self::dieOrLog($die, 'Error: invalid e-mail address');

                    return false;
                }

                if (is_array($toName) && isset($toName[$key])) {
                    $addrName = $toName[$key];
                } else {
                    $addrName = $toName;
                }

                $addrName = ($addrName == null || $addrName == $addr || !Validate::isGenericName($addrName)) ?
                          '' :
                          self::mimeEncode($addrName);
                $email->addTo(new \Symfony\Component\Mime\Address(self::toPunycode($addr), $addrName));
            }
            $toPlugin = $to[0];
        } else {
            
            $toPlugin = $to;
            $toName = (($toName == null || $toName == $to) ? '' : self::mimeEncode($toName));
            $email->addTo(new \Symfony\Component\Mime\Address(self::toPunycode($to), $toName));
        }

        if (isset($bcc) && is_array($bcc)) {
            foreach ($bcc as $addr) {
                $addr = trim($addr);
                if (!Validate::isEmail($addr)) {
                    self::dieOrLog($die, 'Error: invalid e-mail address');

                    return false;
                }

                $email->addBcc(new \Symfony\Component\Mime\Address(self::toPunycode($addr)));
            }
        } elseif (isset($bcc)) {
            $email->addBcc(new \Symfony\Component\Mime\Address(self::toPunycode($bcc)));
        }

        try {
            
            if ($configuration['PS_MAIL_METHOD'] == self::METHOD_SMTP) {
                
                $useImplicitTls = self::useImplicitTls($configuration['PS_MAIL_SMTP_ENCRYPTION'] ?? 'off');
                $esmtpTransportParameter = $useImplicitTls ? null : false;

                if (!isset($configuration['PS_MAIL_SMTP_PORT'])) {
                    $configuration['PS_MAIL_SMTP_PORT'] = 'default';
                }

                if (empty($configuration['PS_MAIL_SERVER']) || empty($configuration['PS_MAIL_SMTP_PORT'])) {
                    self::dieOrLog($die, 'Error: invalid SMTP server or SMTP port');

                    return false;
                }

                $transport = (new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                    $configuration['PS_MAIL_SERVER'],
                    $configuration['PS_MAIL_SMTP_PORT'],
                    $esmtpTransportParameter
                ))
                    ->setUsername($configuration['PS_MAIL_USER'])
                    ->setPassword($configuration['PS_MAIL_PASSWD'])
                ;
                self::ldMailFixApplyLocalDomain($transport);
            } else {
                $transport = new \Symfony\Component\Mailer\Transport\SendmailTransport();
            }

            $mailer = new \Symfony\Component\Mailer\Mailer($transport);

            $isoArray = [];
            $iso = Language::getIsoById((int) $idLang);
            if ($iso) {
                $isoArray[] = $iso;
            }
            $isoDefault = Language::getIsoById((int) Configuration::get('PS_LANG_DEFAULT'));
            if ($isoDefault && $iso !== $isoDefault) {
                $isoArray[] = $isoDefault;
            }
            if (!in_array('en', $isoArray)) {
                $isoArray[] = 'en';
            }

            $moduleName = false;
            if (preg_match('#' . $shop->physical_uri . 'modules/#', str_replace(DIRECTORY_SEPARATOR, '/', $templatePath))
                && preg_match('#modules/([a-z0-9_-]+)/#ui', str_replace(DIRECTORY_SEPARATOR, '/', $templatePath), $res)
            ) {
                $moduleName = $res[1];
            }

            $isoTemplate = '';
            foreach ($isoArray as $isoCode) {
                $isoTemplate = $isoCode . '/' . $template;
                $templatePath = self::getTemplateBasePath($isoTemplate, $moduleName, $shop->theme);

                if (!file_exists($templatePath . $isoTemplate . '.txt')
                    && (
                        $configuration['PS_MAIL_TYPE'] == Mail::TYPE_BOTH
                        || $configuration['PS_MAIL_TYPE'] == Mail::TYPE_TEXT
                    )
                ) {
                    PrestaShopLogger::addLog(
                        Context::getContext()->getTranslator()->trans(
                            'Error - The following e-mail template is missing: %s',
                            [$templatePath . $isoTemplate . '.txt'],
                            'Admin.Advparameters.Notification'
                        )
                    );
                } elseif (!file_exists($templatePath . $isoTemplate . '.html')
                          && (
                              $configuration['PS_MAIL_TYPE'] == Mail::TYPE_BOTH
                              || $configuration['PS_MAIL_TYPE'] == Mail::TYPE_BOTH_AUTOMATIC_TEXT
                              || $configuration['PS_MAIL_TYPE'] == Mail::TYPE_HTML
                          )
                ) {
                    PrestaShopLogger::addLog(
                        Context::getContext()->getTranslator()->trans(
                            'Error - The following e-mail template is missing: %s',
                            [$templatePath . $isoTemplate . '.html'],
                            'Admin.Advparameters.Notification'
                        )
                    );
                } else {
                    $templatePathExists = true;

                    break;
                }
            }

            if (empty($templatePathExists)) {
                self::dieOrLog($die, 'Error - The following e-mail template is missing: %s', [$template]);

                return false;
            }

            $templateHtml = '';
            $templateTxt = '';

            Hook::exec(
                'actionEmailAddBeforeContent',
                [
                    'template' => $template,
                    'template_html' => &$templateHtml,
                    'template_txt' => &$templateTxt,
                    'id_lang' => (int) $idLang,
                ],
                null,
                true
            );

            $templateHtml .= Tools::file_get_contents($templatePath . $isoTemplate . '.html');
            $templateTxt .= strip_tags(
                html_entity_decode(
                    Tools::file_get_contents($templatePath . $isoTemplate . '.txt'),
                    ENT_COMPAT,
                    'utf-8'
                )
            );

            Hook::exec(
                'actionEmailAddAfterContent',
                [
                    'template' => $template,
                    'template_html' => &$templateHtml,
                    'template_txt' => &$templateTxt,
                    'id_lang' => (int) $idLang,
                ],
                null,
                true
            );

            if (Configuration::get('PS_MAIL_SUBJECT_PREFIX')) {
                $subject = '[' . strip_tags($configuration['PS_SHOP_NAME']) . '] ' . $subject;
            }
            $email->subject($subject);

            $email
                ->getHeaders()
                ->add(new \Symfony\Component\Mime\Header\IdentificationHeader('Message-ID', Mail::generateId()))
            ;

            if (!($replyTo && Validate::isEmail($replyTo))) {
                $replyTo = $from;
            }
            if (!empty($replyTo) && $replyTo != $toPlugin) {
                $email->replyTo(new \Symfony\Component\Mime\Address($replyTo, (string) $replyToName));
            }

            if (false !== Configuration::get('PS_LOGO_MAIL', null, null, $idShop)
                && file_exists(_PS_IMG_DIR_ . Configuration::get('PS_LOGO_MAIL', null, null, $idShop))
            ) {
                $logo = _PS_IMG_DIR_ . Configuration::get('PS_LOGO_MAIL', null, null, $idShop);
            } else {
                if (file_exists(_PS_IMG_DIR_ . Configuration::get('PS_LOGO', null, null, $idShop))) {
                    $logo = _PS_IMG_DIR_ . Configuration::get('PS_LOGO', null, null, $idShop);
                } else {
                    $templateVars['{shop_logo}'] = '';
                }
            }
            ShopUrl::cacheMainDomainForShop((int) $idShop);
            if (isset($logo) && $configuration['PS_MAIL_TYPE'] != Mail::TYPE_TEXT) {
                $templateVars['{shop_logo}'] = 'cid:shop_logo';
                $email->embedFromPath($logo, 'shop_logo');
            }

            if (!(Context::getContext()->link instanceof Link)) {
                Context::getContext()->link = new Link();
            }
            $templateVars['{shop_name}'] = Tools::safeOutput($configuration['PS_SHOP_NAME']);
            $templateVars['{shop_url}'] = Context::getContext()->link->getPageLink('index', null, $idLang, null, false, $idShop);
            $templateVars['{my_account_url}'] = Context::getContext()->link->getPageLink('my-account', null, $idLang, null, false, $idShop);
            $templateVars['{guest_tracking_url}'] = Context::getContext()->link->getPageLink('guest-tracking', null, $idLang, null, false, $idShop);
            $templateVars['{history_url}'] = Context::getContext()->link->getPageLink('history', null, $idLang, null, false, $idShop);
            $templateVars['{order_slip_url}'] = Context::getContext()->link->getPageLink('order-slip', null, $idLang, null, false, $idShop);
            $templateVars['{color}'] = Tools::safeOutput(Configuration::get('PS_MAIL_COLOR', null, null, $idShop));

            $extraTemplateVars = [];

            Hook::exec(
                'actionGetExtraMailTemplateVars',
                [
                    'template' => $template,
                    'template_vars' => $templateVars,
                    'extra_template_vars' => &$extraTemplateVars,
                    'id_lang' => (int) $idLang,
                ],
                null,
                true
            );
            $templateVars = array_merge($templateVars, $extraTemplateVars);

            switch ($configuration['PS_MAIL_TYPE']) {
                case Mail::TYPE_HTML:
                    $templateHtml = strtr($templateHtml, $templateVars);
                    $email->html($templateHtml);
                    break;

                case Mail::TYPE_TEXT:
                    $templateTxt = strtr($templateTxt, $templateVars);
                    $email->text($templateTxt);
                    break;

                case Mail::TYPE_BOTH:
                    $templateHtml = strtr($templateHtml, $templateVars);
                    $email->html($templateHtml);

                    $templateTxt = strtr($templateTxt, $templateVars);
                    $email->text($templateTxt);
                    break;

                case Mail::TYPE_BOTH_AUTOMATIC_TEXT:
                    $templateHtml = strtr($templateHtml, $templateVars);
                    $email->html($templateHtml);

                    $templateTxt = (new PrestaShop\PrestaShop\Core\MailTemplate\Transformation\HTMLToTextTransformation())->apply($templateHtml, []);
                    $email->text($templateTxt);
                    break;
            }

            if (!empty($fileAttachment)) {
                
                if (!is_array(current($fileAttachment))) {
                    $fileAttachment = [$fileAttachment];
                }

                foreach ($fileAttachment as $attachment) {
                    if (isset($attachment['content'], $attachment['name'], $attachment['mime'])) {
                        $email->attach($attachment['content'], $attachment['name'], $attachment['mime']);
                    }
                }
            }

            $email->from(new \Symfony\Component\Mime\Address($from, (string) $fromName));

            Hook::exec('actionMailAlterMessageBeforeSend', [
                'message' => &$email,
                'id_lang' => (int) $idLang,
            ]);

            if ((bool) $configuration['PS_MAIL_DKIM_ENABLE'] === true
                && !empty($configuration['PS_MAIL_DKIM_DOMAIN'])
                && !empty($configuration['PS_MAIL_DKIM_SELECTOR'])
                && !empty($configuration['PS_MAIL_DKIM_KEY'])
            ) {
                $signer = new \Symfony\Component\Mime\Crypto\DkimSigner(
                    $configuration['PS_MAIL_DKIM_KEY'],
                    $configuration['PS_MAIL_DKIM_DOMAIN'],
                    $configuration['PS_MAIL_DKIM_SELECTOR']
                );

                $signedEmail = $signer->sign($email);
            }

            $mailer->send($signedEmail ?? $email);
            ShopUrl::resetMainDomainCache();

            if (Configuration::get('PS_LOG_EMAILS')) {
                $mail = new Mail();
                $mail->template = Tools::substr($template, 0, 62);
                $mail->subject = Tools::substr($email->getSubject(), 0, 255);
                $mail->id_lang = (int) $idLang;

                $recipientsTo = self::convertAdressesToArray($email->getTo());
                $recipientsCc = self::convertAdressesToArray($email->getCc());
                $recipientsBcc = self::convertAdressesToArray($email->getBcc());

                if (!is_array($recipientsTo)) {
                    $recipientsTo = [];
                }
                if (!is_array($recipientsCc)) {
                    $recipientsCc = [];
                }
                if (!is_array($recipientsBcc)) {
                    $recipientsBcc = [];
                }
                foreach (array_merge($recipientsTo, $recipientsCc, $recipientsBcc) as $emailAlias => $recipient_name) {
                    $mail->id = null;
                    $mail->recipient = Tools::substr($emailAlias, 0, 255);
                    $mail->add();
                }
            }

            return true;
        } catch (\Symfony\Component\Mailer\Exception\ExceptionInterface $e) {
            PrestaShopLogger::addLog(
                'Mailer Error: ' . $e->getMessage(),
                3,
                null,
                'MailerMessage'
            );

            return false;
        }
    }

    public static function sendMailTest(
        $smtpChecked,
        $smtpServer,
        $content,
        $subject,
        $type,
        $to,
        $from,
        $smtpLogin,
        $smtpPassword,
        $smtpPort,
        $smtpEncryption,
        bool $dkimEnable = false,
        string $dkimKey = '',
        string $dkimDomain = '',
        string $dkimSelector = ''
    ) {
        $result = false;

        try {
            if ($smtpChecked) {
                $useImplicitTls = self::useImplicitTls($smtpEncryption);
                $esmtpTransportParameter = $useImplicitTls ? null : false;
                $transport = (new \Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport(
                    $smtpServer,
                    $smtpPort,
                    $esmtpTransportParameter
                ))
                    ->setUsername($smtpLogin)
                    ->setPassword($smtpPassword)
                ;
                self::ldMailFixApplyLocalDomain($transport);
            } else {
                $transport = new \Symfony\Component\Mailer\Transport\SendmailTransport();
            }

            $mailer = new \Symfony\Component\Mailer\Mailer($transport);

            $email = (new \Symfony\Component\Mime\Email())
                ->from($from)
                ->to($to)
                ->subject($subject)
                ->text($content)
            ;

            if ($dkimEnable === true
                && !empty($dkimKey)
                && !empty($dkimDomain)
                && !empty($dkimSelector)
            ) {
                $signer = new \Symfony\Component\Mime\Crypto\DkimSigner(
                    $dkimKey,
                    $dkimDomain,
                    $dkimSelector
                );

                $signedEmail = $signer->sign($email);
            }

            $mailer->send($signedEmail ?? $email);
            $result = true;
        } catch (\Symfony\Component\Mailer\Exception\ExceptionInterface $e) {
            $result = $e->getMessage();
        }

        return $result;
    }

    private static function convertAdressesToArray(array $addresses): array
    {
        $recipientsTo = [];

        foreach ($addresses as $address) {
            $recipientsTo[$address->getAddress()] = $address->getName();
        }

        return $recipientsTo;
    }

    private static function ldMailFixApplyLocalDomain($transport)
    {
        $domain = trim((string) Configuration::get('LDMAILFIX_DOMAIN'));
        if ($domain !== '' && is_object($transport) && method_exists($transport, 'setLocalDomain')) {
            $transport->setLocalDomain($domain);
        }
    }
}
