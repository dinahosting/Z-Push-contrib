<?php
/***********************************************
* File      :   imap.php
* Project   :   Z-Push
* Descr     :   This backend is based on
*               'BackendDiff' and implements an
*               IMAP interface
*
* Created   :   10.10.2007
*
* Copyright 2007 - 2013 Zarafa Deutschland GmbH
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU Affero General Public License, version 3,
* as published by the Free Software Foundation with the following additional
* term according to sec. 7:
*
* According to sec. 7 of the GNU Affero General Public License, version 3,
* the terms of the AGPL are supplemented with the following terms:
*
* "Zarafa" is a registered trademark of Zarafa B.V.
* "Z-Push" is a registered trademark of Zarafa Deutschland GmbH
* The licensing of the Program under the AGPL does not imply a trademark license.
* Therefore any rights, title and interest in our trademarks remain entirely with us.
*
* However, if you propagate an unmodified version of the Program you are
* allowed to use the term "Z-Push" to indicate that you distribute the Program.
* Furthermore you may use our trademarks where it is necessary to indicate
* the intended purpose of a product or service provided you use it in accordance
* with honest practices in industrial or commercial matters.
* If you want to propagate modified versions of the Program under the name "Z-Push",
* you may only do so if you have a written permission by Zarafa Deutschland GmbH
* (to acquire a permission please contact Zarafa at trademark@zarafa.com).
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
* GNU Affero General Public License for more details.
*
* You should have received a copy of the GNU Affero General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
*
* Consult LICENSE file for details
************************************************/

// config file
require_once("backend/imap/config.php");

include_once('lib/default/diffbackend/diffbackend.php');
include_once('include/Mail.php');
include_once('include/mimeDecode.php');
include_once('include/mimePart.php');
include_once('include/RFC822.php');


class BackendIMAP extends BackendDiff implements ISearchProvider {
    private $wasteID;
    private $sentID;
    private $server;
    private $mbox;
    private $mboxFolder;
    private $username;
    private $password;
    private $domain;
    private $serverdelimiter;
    private $sinkfolders = array();
    private $sinkstates = array();
    private $changessinkinit = false;
    private $excludedFolders;


    public function BackendIMAP() {
        $this->wasteID = false;
        $this->sentID = false;
        $this->mboxFolder = "";

        if (!function_exists("imap_open"))
            throw new FatalException("BackendIMAP(): php-imap module is not installed", 0, null, LOGLEVEL_FATAL);

        if (defined('IMAP_MBCONVERT') && IMAP_MBCONVERT !== false) {
            if (!function_exists("mb_convert_encoding"))
                throw new FatalException("BackendIMAP(): php-mbstring module is not installed", 0, null, LOGLEVEL_FATAL);
        }
    }

    /**----------------------------------------------------------------------------------------------------------
     * default backend methods
     */

    /**
     * Authenticates the user
     *
     * @param string        $username
     * @param string        $domain
     * @param string        $password
     *
     * @access public
     * @return boolean
     * @throws FatalException   if php-imap module can not be found
     */
    public function Logon($username, $domain, $password) {
        $this->wasteID = false;
        $this->sentID = false;
        $this->server = "{" . IMAP_SERVER . ":" . IMAP_PORT . "/imap" . IMAP_OPTIONS . "}";

        if (!function_exists("imap_open"))
            throw new FatalException("BackendIMAP(): php-imap module is not installed", 0, null, LOGLEVEL_FATAL);

        if (defined('IMAP_MBCONVERT') && IMAP_MBCONVERT !== false) {
            if (!function_exists("mb_convert_encoding"))
                throw new FatalException("BackendIMAP(): php-mbstring module is not installed", 0, null, LOGLEVEL_FATAL);
        }

        /* BEGIN fmbiete's contribution r1527, ZP-319 */
        $this->excludedFolders = array();
        if (defined('IMAP_EXCLUDED_FOLDERS') && strlen(IMAP_EXCLUDED_FOLDERS) > 0) {
            $this->excludedFolders = explode("|", IMAP_EXCLUDED_FOLDERS);
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->Logon(): Excluding Folders (%s)", IMAP_EXCLUDED_FOLDERS));
        }
        /* END fmbiete's contribution r1527, ZP-319 */

        // open the IMAP-mailbox
        $this->mbox = @imap_open($this->server , $username, $password, OP_HALFOPEN);
        $this->mboxFolder = "";

        if ($this->mbox) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->Logon(): User '%s' is authenticated on '%s'", $username, $this->server));
            $this->username = $username;
            $this->password = $password;
            $this->domain = $domain;
            // set serverdelimiter
            $this->serverdelimiter = $this->getServerDelimiter();
            return true;
        }
        else {
            ZLog::Write(LOGLEVEL_ERROR, sprintf("BackendIMAP->Logon(): can't connect as user '%s' on '%s': %s", $username, $this->server, imap_last_error()));
            return false;
        }
    }

    /**
     * Logs off
     * Called before shutting down the request to close the IMAP connection
     * writes errors to the log
     *
     * @access public
     * @return boolean
     */
    public function Logoff() {
        if ($this->mbox) {
            // list all errors
            $errors = imap_errors();
            if (is_array($errors)) {
                foreach ($errors as $e) {
                    if (stripos($e, "fail") !== false) {
                        $level = LOGLEVEL_WARN;
                    }
                    else {
                        $level = LOGLEVEL_DEBUG;
                    }
                    ZLog::Write($level, "BackendIMAP->Logoff(): IMAP said: " . $e);
                }
            }
            @imap_close($this->mbox);
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->Logoff(): IMAP connection closed");
        }
        $this->SaveStorages();
    }

    /**
     * Sends an e-mail
     * This messages needs to be saved into the 'sent items' folder
     *
     * @param SyncSendMail  $sm     SyncSendMail object
     *
     * @access public
     * @return boolean
     * @throws StatusException
     */
    public function SendMail($sm) {
        global $imap_smtp_params;

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): RFC822: %d bytes  forward-id: '%s' reply-id: '%s' parent-id: '%s' SaveInSent: '%s' ReplaceMIME: '%s'",
                                            strlen($sm->mime), Utils::PrintAsString($sm->forwardflag), Utils::PrintAsString($sm->replyflag),
                                            Utils::PrintAsString((isset($sm->source->folderid) ? $sm->source->folderid : false)),
                                            Utils::PrintAsString(($sm->saveinsent)), Utils::PrintAsString(isset($sm->replacemime))));

        // by splitting the message in several lines we can easily grep later
        foreach(preg_split("/((\r)?\n)/", $sm->mime) as $rfc822line)
            ZLog::Write(LOGLEVEL_WBXML, "RFC822: ". $rfc822line);

        $sourceMessage = $sourceMail = false;
        // If we have a reference to a source message and we are not replacing mime (since we wouldn't use it)
        if (isset($sm->source->folderid) && isset($sm->source->itemid) && (!isset($sm->replacemime) || $sm->replacemime === false)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): We have a source message and we try to fetch it"));
            $parent = $this->getImapIdFromFolderId($sm->source->folderid);
            if ($parent === false) {
                throw new StatusException(sprintf("BackendIMAP->SendMail(): Could not get imapid from source folderid '%'", $sm->source->folderid), SYNC_COMMONSTATUS_ITEMNOTFOUND);
            }
            else {
                $this->imap_reopenFolder($parent);
                $sourceMail = @imap_fetchheader($this->mbox, $sm->source->itemid, FT_UID) . @imap_body($this->mbox, $sm->source->itemid, FT_PEEK | FT_UID);
                $mobj = new Mail_mimeDecode($sourceMail);
                $sourceMessage = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
                unset($mobj);
                //We will need $sourceMail if the message is forwarded and not inlined

                // If it's a reply, we mark the original message as answered
                if ($sm->replyflag) {
                    if (!@imap_setflag_full($this->mbox, $sm->source->itemid, "\\Answered", ST_UID)) {
                        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->SendMail(): Unable to mark the message as Answered"));
                    }
                }

                // If it's a forward, we mark the original message as forwarded
                if ($sm->forwardflag) {
                    if (!@imap_setflag_full($this->mbox, $sm->source->itemid, "\\Forwarded", ST_UID)) {
                        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->SendMail(): Unable to mark the message as Forwarded"));
                    }
                }
            }
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): We get the new message"));
        $mobj = new Mail_mimeDecode($sm->mime);
        $message = $mobj->decode(array('decode_headers' => false, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));
        unset($mobj);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): We get the From and To"));
        $Mail_RFC822 = new Mail_RFC822();
        $fromaddr = $toaddr = "";
        // We get the vanilla from address
        if (isset($message->headers["from"])) {
            $fromaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["from"]));
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): From defined: %s", $fromaddr));
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): No From address defined, we try for a default one"));
            $fromaddr = $this->getDefaultFromValue();
            $message->headers["from"] = $fromaddr;
        }
        if (isset($message->headers["to"])) {
            $toaddr = $this->parseAddr($Mail_RFC822->parseAddressList($message->headers["to"]));
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): To defined: %s", $toaddr));
        }
        unset($Mail_RFC822);

        // We set the return-path
        if (!isset($message->headers["return-path"])) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): No Return-Path address defined, we use From"));
            $message->headers["return-path"] = $fromaddr;
        }

        //http://pear.php.net/manual/en/package.mail.mail-mime.example.php
        //http://pear.php.net/manual/en/package.mail.mail-mimedecode.decode.php
        //http://pear.php.net/manual/en/package.mail.mail-mimepart.addsubpart.php

        // I don't mind if the new message is multipart or not, I always will create a multipart. It's simpler
        $finalEmail = new Mail_mimePart('', array('content_type' => 'multipart/mixed'));

        if ($sm->replyflag && (!isset($sm->replacemime) || $sm->replacemime === false)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Replying message"));
            $this->addTextParts($finalEmail, $message, $sourceMessage, true);

            if (isset($message->parts)) {
                // We add extra parts from the replying message
                $this->addExtraSubParts($finalEmail, $message->parts);
            }
            // A replied message doesn't include the original attachments
        }
        else if ($sm->forwardflag && (!isset($sm->replacemime) || $sm->replacemime === false)) {
            if (!defined('IMAP_INLINE_FORWARD') || IMAP_INLINE_FORWARD === false) {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Forwarding message as attached file - eml");
                $finalEmail->addSubPart($sourceMail, array('content_type' => 'message/rfc822', 'encoding' => 'base64', 'disposition' => 'attachment', 'dfilename' => 'forwarded_message.eml'));
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Forwarding inlined message");
                $this->addTextParts($finalEmail, $message, $sourceMessage, false);

                if (isset($message->parts)) {
                    // We add extra parts from the forwarding message
                    $this->addExtraSubParts($finalEmail, $message->parts);
                }
                if (isset($sourceMessage->parts)) {
                    // We add extra parts from the forwarded message
                    $this->addExtraSubParts($finalEmail, $sourceMessage->parts);
                }
            }
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): is a new message or we are replacing mime"));
            $this->addTextPartsMessage($finalEmail, $message);
            if (isset($message->parts)) {
                // We add extra parts from the new message
                $this->addExtraSubParts($finalEmail, $message->parts);
            }
        }

        // We encode the final message
        $boundary = '=_' . md5(rand() . microtime());
        $finalEmail = $finalEmail->encode($boundary);

        $finalHeaders = array('Mime-Version' => '1.0');
        // We copy all the headers, minus content_type
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Copying new headers"));
        foreach ($message->headers as $k => $v) {
            if (strcasecmp($k, 'content-type') != 0 && strcasecmp($k, 'content-transfer-encoding') != 0 && strcasecmp($k, 'mime-version') != 0) {
                $finalHeaders[ucwords($k)] = $v;
            }
        }
        foreach ($finalEmail['headers'] as $k => $v) {
            $finalHeaders[$k] = $v;
        }

        $finalBody = "This is a multi-part message in MIME format.\n" . $finalEmail['body'];

        unset($sourceMail);
        unset($message);
        unset($sourceMessage);
        unset($finalEmail);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Final mail to send:"));
        foreach ($finalHeaders as $k => $v)
            ZLog::Write(LOGLEVEL_WBXML, sprintf("%s: %s", $k, $v));
        foreach (preg_split("/((\r)?\n)/", $finalBody) as $bodyline)
            ZLog::Write(LOGLEVEL_WBXML, sprintf("Body: %s", $bodyline));

        //http://pear.php.net/manual/en/package.mail.mail.factory.php
        $sendingMethod = 'mail';
        if (defined('IMAP_SMTP_METHOD')) {
            $sendingMethod = IMAP_SMTP_METHOD;
            if ($sendingMethod == 'smtp') {
                if (isset($imap_smtp_params['username']) && $imap_smtp_params['username'] == 'imap_username') {
                    $imap_smtp_params['username'] = $this->username;
                }
                if (isset($imap_smtp_params['password']) && $imap_smtp_params['password'] == 'imap_password') {
                    $imap_smtp_params['password'] = $this->password;
                }
            }
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): SendingMail with %s", $sendingMethod));
        $mail =& Mail::factory($sendingMethod, $sendingMethod == 'mail' ? '-f '.$fromaddr : $imap_smtp_params);
        $send = $mail->send($toaddr, $finalHeaders, $finalBody);

        if ($send !== true) {
            throw new StatusException(sprintf("BackendIMAP->SendMail(): The email could not be sent"), SYNC_COMMONSTATUS_MAILSUBMISSIONFAILED);
        }

        if (isset($sm->saveinsent)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): saving message in Sent Items folder"));

            $headers = "";
            foreach ($finalHeaders as $k => $v) {
                if (strlen($headers) > 0) {
                    $headers .= "\n";
                }
                $headers .= "$k: $v";
            }

            $saved = false;
            if ($this->sentID) {
                $saved = $this->addSentMessage($this->sentID, $headers, $finalBody);
            }
            else if (IMAP_SENTFOLDER) {
                // try to open the sentfolder
                if (!$this->imap_reopenFolder(IMAP_SENTFOLDER, false)) {
                    // if we cannot open it, it mustn't exist, we try to create it.
                    $this->imap_createFolder($this->server . IMAP_SENTFOLDER);
                }
                $saved = $this->addSentMessage(IMAP_SENTFOLDER, $headers, $finalBody);
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SendMail(): Outgoing mail saved in configured 'Sent' folder '%s'", IMAP_SENTFOLDER));
            }
            // No Sent folder set, try defaults
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): No Sent mailbox set");
                if($this->addSentMessage("INBOX.Sent", $headers, $finalBody)) {
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'INBOX.Sent'");
                    $saved = true;
                }
                else if ($this->addSentMessage("Sent", $headers, $finalBody)) {
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Outgoing mail saved in 'Sent'");
                    $saved = true;
                }
                else if ($this->addSentMessage("Sent Items", $headers, $finalBody)) {
                    ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail():IMAP-SendMail: Outgoing mail saved in 'Sent Items'");
                    $saved = true;
                }
            }

            unset($headers);

            if (!$saved) {
                ZLog::Write(LOGLEVEL_ERROR, "BackendIMAP->SendMail(): The email could not be saved to Sent Items folder. Check your configuration.");
            }
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->SendMail(): Not saving in SentFolder");
        }

        unset($finalHeaders);
        unset($finalBody);

        return $send;
    }

    /**
     * Add text parts to a mimepart object, with reply or forward tags
     *
     * @param Mail_mimePart $email reference to the object
     * @param Mail_mimeDecode $message reference to the message
     * @param Mail_mimeDecode $sourceMessage reference to the original message
     * @param boolean $isReply true if it's a reply, false if it's a forward
     *
     * @access private
     * @return void
     */
    private function addTextParts(&$email, &$message, &$sourceMessage, $isReply = true) {
        $htmlBody = $plainBody = '';
        $this->getBodyRecursive($message, "html", $htmlBody);
        $this->getBodyRecursive($message, "plain", $plainBody);
        $htmlSource = $plainSource = '';
        $this->getBodyRecursive($sourceMessage, "html", $htmlSource);
        $this->getBodyRecursive($sourceMessage, "plain", $plainSource);

        $separator = '';
        if ($isReply) {
            $separator = ">\r\n";
            $separatorHtml = "<blockquote>";
            $separatorHtmlEnd = "</blockquote></body></html>";
        }
        else {
            $separator = "";
            $separatorHtml = "<div>";
            $separatorHtmlEnd = "</div>";
        }

        $altEmail = new Mail_mimePart('', array('content_type' => 'multipart/alternative'));

        if (strlen($htmlBody) > 0) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The message has HTML body"));
            if (strlen($htmlSource) > 0) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The original message had HTML body"));
                $altEmail->addSubPart($htmlBody . $separatorHtml . $htmlSource . $separatorHtmlEnd, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The original message had not HTML body, we use original PLAIN body to create HTML"));
                $altEmail->addSubPart($htmlBody . $separatorHtml . "<p>" . $plainSource . "</p>" . $separatorHtmlEnd, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
            }
        }
        if (strlen($plainBody) > 0) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The message has PLAIN body"));
            if (strlen($htmlSource) > 0) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The original message had HTML body, we cast new PLAIN to HTML"));
                $altEmail->addSubPart('<html><body><p>' . str_replace("\n", "<br/>", str_replace("\r\n", "\n", $plainBody)) . "</p>" . $separatorHtml . $htmlSource . $separatorHtmlEnd, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
            }
            if (strlen($plainSource) > 0) {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextParts(): The original message had PLAIN body"));
                $altEmail->addSubPart($plainBody . $separator . str_replace("\n", "\n> ", "> ".$plainSource), array('content_type' => 'text/plain; charset=utf-8', 'encoding' => 'base64'));
            }
        }

        $boundary = '=_' . md5(rand() . microtime());
        $altEmail = $altEmail->encode($boundary);

        $email->addSubPart($altEmail['body'], array('content_type' => 'multipart/alternative;'."\n".' boundary="'.$boundary.'"'));

        unset($altEmail);

        unset($htmlBody);
        unset($htmlSource);
        unset($plainBody);
        unset($plainSource);
    }

    /**
     * Add text parts to a mimepart object
     *
     * @param Mail_mimePart $email reference to the object
     * @param Mail_mimeDecode $message reference to the message
     *
     * @access private
     * @return void
     */
    private function addTextPartsMessage(&$email, &$message) {
        $htmlBody = $plainBody = '';
        $this->getBodyRecursive($message, "html", $htmlBody);
        $this->getBodyRecursive($message, "plain", $plainBody);

        $altEmail = new Mail_mimePart('', array('content_type' => 'multipart/alternative'));

        if (strlen($htmlBody) > 0) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextPartsMessage(): The message has HTML body"));
            $altEmail->addSubPart($htmlBody, array('content_type' => 'text/html; charset=utf-8', 'encoding' => 'base64'));
        }
        if (strlen($plainBody) > 0) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->addTextPartsMessage(): The message has PLAIN body"));
            $altEmail->addSubPart($plainBody, array('content_type' => 'text/plain; charset=utf-8', 'encoding' => 'base64'));
        }

        $boundary = '=_' . md5(rand() . microtime());
        $altEmail = $altEmail->encode($boundary);

        $email->addSubPart($altEmail['body'], array('content_type' => 'multipart/alternative;'."\n".' boundary="'.$boundary.'"'));

        unset($altEmail);

        unset($htmlBody);
        unset($plainBody);
    }

    /**
     * Add extra parts (not text; inlined or attached parts) to a mimepart object.
     *
     * @param Mail_mimePart $email reference to the object
     * @param array $parts array of parts
     *
     * @access private
     * @return void
     */
    private function addExtraSubParts(&$email, $parts) {
        if (isset($parts)) {
            foreach ($parts as $part) {
                if ((isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline"))
                        || (isset($part->ctype_primary) && $part->ctype_primary != "text" &&  $part->ctype_primary != "multipart")) {
                    $this->addSubPart($email, $part);
                }
                if (isset($part->parts)) {
                    $this->addExtraSubParts($email, $part->parts);
                }
            }
        }
    }

    /**
     * Add a subpart to a mimepart object.
     *
     * @param Mail_mimePart $email reference to the object
     * @param object $part message part
     *
     * @access private
     * @return void
     */
    private function addSubPart(&$email, $part) {
        //http://tools.ietf.org/html/rfc4021
        $params = array();
        if (isset($part)) {
            if (isset($part->ctype_primary)) {
                $params['content_type'] = $part->ctype_primary;
            }
            if (isset($part->ctype_secondary)) {
                $params['content_type'] .= '/' . $part->ctype_secondary;
            }
            if (isset($part->ctype_parameters)) {
                foreach ($part->ctype_parameters as $k => $v) {
                    $params['content_type'] .= '; ' . $k . '=' . $v;
                }
            }
            if (isset($part->disposition)) {
                $params['disposition'] = $part->disposition;
            }
            //FIXME: dfilename => filename
            if (isset($part->d_parameters)) {
                foreach ($part->d_parameters as $k => $v) {
                    $params[$k] = $v;
                }
            }
            foreach ($part->headers as $k => $v) {
                $params[$k] = $v;
            }
            if (!isset($params['encoding'])) {
                $params['encoding'] = 'base64';
            }
            $email->addSubPart($part->body, $params);
            unset($params);
        }
    }


    /**
     * Add a subpart to a mimepart object.
     *
     * @param Mail_mimePart $email reference to the object
     * @param object $part message part
     *
     * @access private
     * @return void
     */
    private function fixCharsetAndAddSubParts(&$email, $part) {
        if (isset($part)) {
            if (isset($part->ctype_parameters['charset'])) {
                $part->ctype_parameters['charset'] = 'UTF-8';
                $this->addSubPart($email, $part);

                if (isset($part->parts)) {
                    foreach ($part->parts as $subpart) {
                        $this->fixCharsetAndAddSubParts($email, $subpart);
                    }
                }
            }
        }
    }

    /**
     * Returns the waste basket
     *
     * @access public
     * @return string
     */
    public function GetWasteBasket() {
        // TODO this could be retrieved from the DeviceFolderCache
        if ($this->wasteID == false) {
            //try to get the waste basket without doing complete hierarchy sync
            $wastebaskt = @imap_getmailboxes($this->mbox, $this->server, "Trash");
            if (isset($wastebaskt[0])) {
                $this->wasteID = $this->convertImapId(substr($wastebaskt[0]->name, strlen($this->server)));
                return $this->wasteID;
            }
            //try get waste id from hierarchy if it wasn't possible with above for some reason
            $this->GetHierarchy();
        }
        return $this->wasteID;
    }

    /**
     * Returns the content of the named attachment as stream. The passed attachment identifier is
     * the exact string that is returned in the 'AttName' property of an SyncAttachment.
     * Any information necessary to find the attachment must be encoded in that 'attname' property.
     * Data is written directly (with print $data;)
     *
     * @param string        $attname
     *
     * @access public
     * @return SyncItemOperationsAttachment
     * @throws StatusException
     */
    public function GetAttachmentData($attname) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetAttachmentData('%s')", $attname));

        list($folderid, $id, $part) = explode(":", $attname);

        if (!$folderid || !$id || !$part)
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, attachment name key can not be parsed", $attname), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // convert back to work on an imap-id
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);
        $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

        $mobj = new Mail_mimeDecode($mail);
        $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

        /* BEGIN fmbiete's contribution r1528, ZP-320 */
        //trying parts
        $mparts = $message->parts;
        for ($i = 0; $i < count($mparts); $i++) {
            $auxpart = $mparts[$i];
            //recursively add parts
            if($auxpart->ctype_primary == "multipart" && ($auxpart->ctype_secondary == "mixed" || $auxpart->ctype_secondary == "alternative"  || $auxpart->ctype_secondary == "related")) {
                foreach($auxpart->parts as $spart)
                    $mparts[] = $spart;
            }
        }
        /* END fmbiete's contribution r1528, ZP-320 */

        if (!isset($mparts[$part]->body))
            throw new StatusException(sprintf("BackendIMAP->GetAttachmentData('%s'): Error, requested part key can not be found: '%d'", $attname, $part), SYNC_ITEMOPERATIONSSTATUS_INVALIDATT);

        // unset mimedecoder & mail
        unset($mobj);
        unset($mail);

        include_once('include/stringstreamwrapper.php');
        $attachment = new SyncItemOperationsAttachment();
        /* BEGIN fmbiete's contribution r1528, ZP-320 */
        $attachment->data = StringStreamWrapper::Open($mparts[$part]->body);
        if (isset($mparts[$part]->ctype_primary) && isset($mparts[$part]->ctype_secondary))
            $attachment->contenttype = $mparts[$part]->ctype_primary .'/'.$mparts[$part]->ctype_secondary;

        unset($mparts);
        unset($message);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetAttachmentData contenttype %s", $attachment->contenttype));
        /* END fmbiete's contribution r1528, ZP-320 */

        return $attachment;
    }

    /**
     * Indicates if the backend has a ChangesSink.
     * A sink is an active notification mechanism which does not need polling.
     * The IMAP backend simulates a sink by polling status information of the folder
     *
     * @access public
     * @return boolean
     */
    public function HasChangesSink() {
        return true;
    }

    /**
     * The folder should be considered by the sink.
     * Folders which were not initialized should not result in a notification
     * of IBacken->ChangesSink().
     *
     * @param string        $folderid
     *
     * @access public
     * @return boolean      false if found can not be found
     */
    public function ChangesSinkInitialize($folderid) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangesSinkInitialize(): folderid '%s'", $folderid));

        $imapid = $this->getImapIdFromFolderId($folderid);

        if ($imapid !== false) {
            $this->sinkfolders[] = $imapid;
            $this->changessinkinit = true;
        }

        return $this->changessinkinit;
    }

    /**
     * The actual ChangesSink.
     * For max. the $timeout value this method should block and if no changes
     * are available return an empty array.
     * If changes are available a list of folderids is expected.
     *
     * @param int           $timeout        max. amount of seconds to block
     *
     * @access public
     * @return array
     */
    public function ChangesSink($timeout = 30) {
        $notifications = array();
        $stopat = time() + $timeout - 1;

        //We can get here and the ChangesSink not be initialized yet
        if (!$this->changessinkinit) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP>ChangesSink - Not initialized ChangesSink, sleep and exit"));
            // We sleep and do nothing else
            sleep($timeout);
            return $notifications;
        }

        while($stopat > time() && empty($notifications)) {
            foreach ($this->sinkfolders as $i => $imapid) {
                $this->imap_reopenFolder($imapid);

                // courier-imap only cleares the status cache after checking
                @imap_check($this->mbox);

                $status = @imap_status($this->mbox, $this->server . $imapid, SA_ALL);
                if (!$status) {
                    ZLog::Write(LOGLEVEL_WARN, sprintf("ChangesSink: could not stat folder '%s': %s ", $this->getFolderIdFromImapId($imapid), imap_last_error()));
                }
                else {
                    $newstate = "M:". $status->messages ."-R:". $status->recent ."-U:". $status->unseen;

                    if (! isset($this->sinkstates[$imapid]) ) {
                        $this->sinkstates[$imapid] = $newstate;
                    }

                    if ($this->sinkstates[$imapid] != $newstate) {
                        $notifications[] = $this->getFolderIdFromImapId($imapid);
                        $this->sinkstates[$imapid] = $newstate;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangesSink(): ChangesSink detected!!"));
                    }
                }
            }

            if (empty($notifications))
                sleep(5);
        }

        return $notifications;
    }


    /**----------------------------------------------------------------------------------------------------------
     * implemented DiffBackend methods
     */


    /**
     * Returns a list (array) of folders.
     *
     * @access public
     * @return array/boolean        false if the list could not be retrieved
     */
    public function GetFolderList() {
        $folders = array();

        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            // reverse list to obtain folders in right order
            $list = array_reverse($list);

            foreach ($list as $val) {
                /* BEGIN fmbiete's contribution r1527, ZP-319 */
                // don't return the excluded folders
                $notExcluded = true;
                for ($i = 0, $cnt = count($this->excludedFolders); $notExcluded && $i < $cnt; $i++) { // expr1, expr2 modified by mku ZP-329
                    // fix exclude folders with special chars by mku ZP-329
                    if (strpos(strtolower($val->name), strtolower(Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($this->excludedFolders[$i])))) !== false) {
                        $notExcluded = false;
                        ZLog::Write(LOGLEVEL_DEBUG, sprintf("Pattern: <%s> found, excluding folder: '%s'", $this->excludedFolders[$i], $val->name)); // sprintf added by mku ZP-329
                    }
                }

                if ($notExcluded) {
                    $box = array();
                    // cut off serverstring
                    $imapid = substr($val->name, strlen($this->server));
                    $box["id"] = $this->convertImapId($imapid);

                    $fhir = explode($val->delimiter, $imapid);
                    if (count($fhir) > 1) {
                        $this->getModAndParentNames($fhir, $box["mod"], $imapparent);
                        $box["parent"] = $this->convertImapId($imapparent);
                    }
                    else {
                        $box["mod"] = $imapid;
                        $box["parent"] = "0";
                    }
                    $folders[]=$box;
                    /* END fmbiete's contribution r1527, ZP-319 */
                }
            }
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, "BackendIMAP->GetFolderList(): imap_list failed: " . imap_last_error());
            return false;
        }

        return $folders;
    }

    /**
     * Returns an actual SyncFolder object
     *
     * @param string        $id           id of the folder
     *
     * @access public
     * @return object       SyncFolder with information
     */
    public function GetFolder($id) {
        $folder = new SyncFolder();
        $folder->serverid = $id;

        // convert back to work on an imap-id
        $imapid = $this->getImapIdFromFolderId($id);

        // explode hierarchy
        $fhir = explode($this->serverdelimiter, $imapid);

        // compare on lowercase strings
        $lid = strtolower($imapid);
// TODO WasteID or SentID could be saved for later ussage
        if($lid == "inbox") {
            $folder->parentid = "0"; // Root
            $folder->displayname = "Inbox";
            $folder->type = SYNC_FOLDER_TYPE_INBOX;
        }
        // Zarafa IMAP-Gateway outputs
        else if($lid == "drafts") {
            $folder->parentid = "0";
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "trash" || $lid == "deleted messages") {
            $folder->parentid = "0";
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "sent" || $lid == "sent items" || $lid == IMAP_SENTFOLDER) {
            $folder->parentid = "0";
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }
        // courier-imap outputs and cyrus-imapd outputs
        else if($lid == "inbox.drafts" || $lid == "inbox/drafts") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Drafts";
            $folder->type = SYNC_FOLDER_TYPE_DRAFTS;
        }
        else if($lid == "inbox.trash" || $lid == "inbox/trash") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Trash";
            $folder->type = SYNC_FOLDER_TYPE_WASTEBASKET;
            $this->wasteID = $id;
        }
        else if($lid == "inbox.sent" || $lid == "inbox/sent") {
            $folder->parentid = $this->convertImapId($fhir[0]);
            $folder->displayname = "Sent";
            $folder->type = SYNC_FOLDER_TYPE_SENTMAIL;
            $this->sentID = $id;
        }

        // define the rest as other-folders
        else {
            if (count($fhir) > 1) {
                $this->getModAndParentNames($fhir, $folder->displayname, $imapparent);
                $folder->parentid = $this->convertImapId($imapparent);
                $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($folder->displayname));
            }
            else {
                $folder->displayname = Utils::Utf7_to_utf8(Utils::Utf7_iconv_decode($imapid));
                $folder->parentid = "0";
            }
            $folder->type = SYNC_FOLDER_TYPE_USER_MAIL;
        }

        //advanced debugging
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetFolder('%s'): '%s'", $id, $folder));

        return $folder;
    }

    /**
     * Returns folder stats. An associative array with properties is expected.
     *
     * @param string        $id             id of the folder
     *
     * @access public
     * @return array
     */
    public function StatFolder($id) {
        $folder = $this->GetFolder($id);

        $stat = array();
        $stat["id"] = $id;
        $stat["parent"] = $folder->parentid;
        $stat["mod"] = $folder->displayname;

        return $stat;
    }

    /**
     * Creates or modifies a folder
     * The folder type is ignored in IMAP, as all folders are Email folders
     *
     * @param string        $folderid       id of the parent folder
     * @param string        $oldid          if empty -> new folder created, else folder is to be renamed
     * @param string        $displayname    new folder name (to be created, or to be renamed to)
     * @param int           $type           folder type
     *
     * @access public
     * @return boolean                      status
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function ChangeFolder($folderid, $oldid, $displayname, $type){
        ZLog::Write(LOGLEVEL_INFO, sprintf("BackendIMAP->ChangeFolder('%s','%s','%s','%s')", $folderid, $oldid, $displayname, $type));

        // go to parent mailbox
        $this->imap_reopenFolder($folderid);

        // build name for new mailboxBackendMaildir
        $displayname = Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($displayname));
        $newname = $this->server . $this->getImapIdFromFolderId($folderid) . $this->serverdelimiter . $displayname;

        $csts = false;
        // if $id is set => rename mailbox, otherwise create
        if ($oldid) {
            // rename doesn't work properly with IMAP
            // the activesync client doesn't support a 'changing ID'
            // TODO this would be solved by implementing hex ids (Mantis #459)
            //$csts = imap_renamemailbox($this->mbox, $this->server . imap_utf7_encode(str_replace(".", $this->serverdelimiter, $oldid)), $newname);
        }
        else {
            $csts = @imap_createmailbox($this->mbox, $newname);
        }
        if ($csts) {
            return $this->StatFolder($folderid . $this->serverdelimiter . $displayname);
        }
        else
            return false;
    }

    /**
     * Deletes a folder
     *
     * @param string        $id
     * @param string        $parent         is normally false
     *
     * @access public
     * @return boolean                      status - false if e.g. does not exist
     * @throws StatusException              could throw specific SYNC_FSSTATUS_* exceptions
     *
     */
    public function DeleteFolder($id, $parentid){
        // TODO implement
        return false;
    }

    /**
     * Returns a list (array) of messages
     *
     * @param string        $folderid       id of the parent folder
     * @param long          $cutoffdate     timestamp in the past from which on messages should be returned
     *
     * @access public
     * @return array/false  array with messages or false if folder is not available
     */
    public function GetMessageList($folderid, $cutoffdate) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessageList('%s','%s')", $folderid, $cutoffdate));

        $folderid = $this->getImapIdFromFolderId($folderid);

        if ($folderid == false)
            throw new StatusException("Folderid not found in cache", SYNC_STATUS_FOLDERHIERARCHYCHANGED);

        $messages = array();
        $this->imap_reopenFolder($folderid, true);

        $sequence = "1:*";
        if ($cutoffdate > 0) {
            $search = @imap_search($this->mbox, "SINCE ". date("d-M-Y", $cutoffdate));
            if ($search !== false)
                $sequence = implode(",", $search);
        }
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessageList(): searching with sequence '%s'", $sequence));
        $overviews = @imap_fetch_overview($this->mbox, $sequence);

        if (!$overviews || !is_array($overviews)) {
            $error = imap_last_error();
            if (strlen($error) > 0) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->GetMessageList('%s','%s'): Failed to retrieve overview: %s",$folderid, $cutoffdate, imap_last_error()));
            }
            return $messages;
        }

        foreach($overviews as $overview) {
            $date = "";
            $vars = get_object_vars($overview);
            if (array_key_exists( "date", $vars)) {
                // message is out of range for cutoffdate, ignore it
                if ($this->cleanupDate($overview->date) < $cutoffdate) continue;
                $date = $overview->date;
            }

            // cut of deleted messages
            if (array_key_exists("deleted", $vars) && $overview->deleted)
                continue;

            if (array_key_exists("uid", $vars)) {
                $message = array();
                $message["mod"] = $date;
                $message["id"] = $overview->uid;

                // 'seen' aka 'read'
                if(array_key_exists("seen", $vars) && $overview->seen) {
                    $message["flags"] = 1;
                }
                else {
                    $message["flags"] = 0;
                }

                // 'flagged' aka 'FollowUp' aka 'starred'
                if (array_key_exists("flagged", $vars) && $overview->flagged) {
                    $message["star"] = 1;
                }
                else {
                    $message["star"] = 0;
                }

                array_push($messages, $message);
            }
        }
        return $messages;
    }

    /**
     * Returns the actual SyncXXX object type.
     *
     * @param string            $folderid           id of the parent folder
     * @param string            $id                 id of the message
     * @param ContentParameters $contentparameters  parameters of the requested message (truncation, mimesupport etc)
     *
     * @access public
     * @return object/false     false if the message could not be retrieved
     */
    public function GetMessage($folderid, $id, $contentparameters) {
        $truncsize = Utils::GetTruncSize($contentparameters->GetTruncation());
        $mimesupport = $contentparameters->GetMimeSupport();
        $bodypreference = $contentparameters->GetBodyPreference(); /* fmbiete's contribution r1528, ZP-320 */
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessage('%s','%s')", $folderid,  $id));

        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // Get flags, etc
        $stat = $this->StatMessage($folderid, $id);

        if ($stat) {
            $this->imap_reopenFolder($folderImapid);
            $mail = @imap_fetchheader($this->mbox, $id, FT_UID) . @imap_body($this->mbox, $id, FT_PEEK | FT_UID);

            $mobj = new Mail_mimeDecode($mail);
            $message = $mobj->decode(array('decode_headers' => true, 'decode_bodies' => true, 'include_bodies' => true, 'charset' => 'utf-8'));

            /* BEGIN fmbiete's contribution r1528, ZP-320 */
            $output = new SyncMail();

            //Select body type preference
            $bpReturnType = SYNC_BODYPREFERENCE_PLAIN;
            if ($bodypreference !== false) {
                $bpReturnType = Utils::GetBodyPreferenceBestMatch($bodypreference); // changed by mku ZP-330
            }
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMessage - getBodyPreferenceBestMatch: %d", $bpReturnType));

            //Get body data
            $this->getBodyRecursive($message, "plain", $plainBody);
            $this->getBodyRecursive($message, "html", $htmlBody);
            if ($plainBody == "") {
                $plainBody = Utils::ConvertHtmlToText($htmlBody);
            }
            $htmlBody = str_replace("\n","\r\n", str_replace("\r","",$htmlBody));
            $plainBody = str_replace("\n","\r\n", str_replace("\r","",$plainBody));

            if (Request::GetProtocolVersion() >= 12.0) {
                $output->asbody = new SyncBaseBody();

                switch($bpReturnType) {
                    case SYNC_BODYPREFERENCE_PLAIN:
                        $output->asbody->data = $plainBody;
                        break;
                    case SYNC_BODYPREFERENCE_HTML:
                        if ($htmlBody == "") {
                            $output->asbody->data = $plainBody;
                            $bpReturnType = SYNC_BODYPREFERENCE_PLAIN;
                        }
                        else {
                            $output->asbody->data = $htmlBody;
                        }
                        break;
                    case SYNC_BODYPREFERENCE_MIME:
                        if (defined('IMAP_MBCONVERT') && IMAP_MBCONVERT !== false) {
                            $finalEmail = new Mail_mimePart($message->body, array('headers' => $message->headers));
                            if (isset($message->parts)) {
                                foreach ($message->parts as $part) {
                                    $this->fixCharsetAndAddSubParts($finalEmail, $part);
                                }
                            }

                            $mimeHeaders = "";
                            foreach ($message->headers as $key => $value) {
                                if (strcasecmp($key, 'content-type') == 0) {
                                    $mimeHeaders .= $key . ": " . $message->ctype_primary . "/" . $message->ctype_secondary;

                                    foreach ($message->ctype_parameters as $ckey => $cvalue) {
                                        if (strcasecmp($ckey, 'charset') == 0) {
                                            $mimeHeaders .= '; charset="UTF-8"';
                                        }
                                        else if(strcasecmp($ckey, 'boundary') != 0) {
                                            $mimeHeaders .= '; ' . $ckey . '="' . $cvalue . '"';
                                        }
                                    }

                                    $mimeHeaders .= "\n";
                                }
                                else if (strcasecmp($key, 'content-transfer-encoding') == 0) {
                                    $mimeHeaders .= $key . ": 8bit\n";
                                }
                                else if ((strcasecmp($key, 'from') == 0) || (strcasecmp($key, 'cc') == 0) || (strcasecmp($key, 'to') == 0)) {
                                    $mimeHeaders .= $key . ": =?utf-8?B?" . base64_encode(Utils::FixAddressName($value)) . "\n";
                                }
                                else {
                                    $mimeHeaders .= $key . ": " . $value . "\n";
                                }
                            }

                            $boundary = '=_' . md5(rand() . microtime());
                            $finalEmail = $finalEmail->encode($boundary);
                            $output->asbody->data = $mimeHeaders . "\n\n" . "This is a multi-part message in MIME format.\n" . $finalEmail['body'];
                            unset($finalEmail);
                        }
                        else {
                            // WARNING: Message text could be showed as broken, if it's not UTF-8.
                            $output->asbody->data = $mail;
                        }
                        break;
                    case SYNC_BODYPREFERENCE_RTF:
                        ZLog::Write(LOGLEVEL_DEBUG, "BackendIMAP->GetMessage RTF Format NOT CHECKED");
                        $output->asbody->data = base64_encode($plainBody);
                        break;
                }
                // truncate body, if requested
                if(strlen($output->asbody->data) > $truncsize) {
                    $output->asbody->data = Utils::Utf8_truncate($output->asbody->data, $truncsize);
                    $output->asbody->truncated = 1;
                }

                $output->asbody->type = $bpReturnType;
                $output->nativebodytype = $bpReturnType;
                $output->asbody->estimatedDataSize = strlen($output->asbody->data);

                $bpo = $contentparameters->BodyPreference($output->asbody->type);
                if (Request::GetProtocolVersion() >= 14.0 && $bpo->GetPreview()) {
                    $output->asbody->preview = Utils::Utf8_truncate(Utils::ConvertHtmlToText($plainBody), $bpo->GetPreview());
                }
                else {
                    $output->asbody->truncated = 0;
                }
            }
            /* END fmbiete's contribution r1528, ZP-320 */
            else { // ASV_2.5
                $output->bodytruncated = 0;
                /* BEGIN fmbiete's contribution r1528, ZP-320 */
                if ($bpReturnType == SYNC_BODYPREFERENCE_MIME) {
                    if (strlen($mail) > $truncsize) {
                        $output->mimedata = Utils::Utf8_truncate($mail, $truncsize);
                        $output->mimetruncated = 1;
                    }
                    else {
                        $output->mimetruncated = 0;
                        $output->mimedata = $mail;
                    }
                    $output->mimesize = strlen($output->mimedata);
                }
                else {
                    // truncate body, if requested
                    if (strlen($plainBody) > $truncsize) {
                        $output->body = Utils::Utf8_truncate($plainBody, $truncsize);
                        $output->bodytruncated = 1;
                    }
                    else {
                        $output->body = $plainBody;
                        $output->bodytruncated = 0;
                    }
                    $output->bodysize = strlen($output->body);
                }
                /* END fmbiete's contribution r1528, ZP-320 */
            }

            $output->datereceived = isset($message->headers["date"]) ? $this->cleanupDate($message->headers["date"]) : null;
            $output->messageclass = "IPM.Note";
            $output->subject = isset($message->headers["subject"]) ? $message->headers["subject"] : "";
            $output->read = $stat["flags"];
            $output->from = isset($message->headers["from"]) ? Utils::FixAddressName($message->headers["from"]) : null;

            /* BEGIN fmbiete's contribution r1528, ZP-320 */
            if (isset($message->headers["thread-topic"])) {
                $output->threadtopic = $message->headers["thread-topic"];
                /*
                //FIXME: Conversation support, get conversationid and conversationindex good values
                if (Request::GetProtocolVersion() >= 14.0) {
                    // since the conversationid must be unique for a thread we could use the threadtopic in base64 minus the ==
                    $output->conversationid = strtoupper(str_replace("=", "", base64_encode($output->threadtopic)));
                    if (isset($message->headers["thread-index"]))
                        $output->conversationindex = strtoupper($message->headers["thread-index"]);
                }
                */
            }

            // Language Code Page ID: http://msdn.microsoft.com/en-us/library/windows/desktop/dd317756%28v=vs.85%29.aspx
            $output->internetcpid = INTERNET_CPID_UTF8;
            if (Request::GetProtocolVersion() >= 12.0) {
                $output->contentclass = "urn:content-classes:message";

                $output->flag = new SyncMailFlags();
                if (isset($stat["star"]) && $stat["star"]) {
                    //flagstatus 0: clear, 1: complete, 2: active
                    $output->flag->flagstatus = SYNC_FLAGSTATUS_ACTIVE;
                    //flagtype: for follow up
                    $output->flag->flagtype = "FollowUp";
                }
                else {
                    $output->flag->flagstatus = SYNC_FLAGSTATUS_CLEAR;
                }
            }
            /* END fmbiete's contribution r1528, ZP-320 */

            $Mail_RFC822 = new Mail_RFC822();
            $toaddr = $ccaddr = $replytoaddr = array();
            if(isset($message->headers["to"]))
                $toaddr = $Mail_RFC822->parseAddressList($message->headers["to"]);
            if(isset($message->headers["cc"]))
                $ccaddr = $Mail_RFC822->parseAddressList($message->headers["cc"]);
            if(isset($message->headers["reply-to"]))
                $replytoaddr = $Mail_RFC822->parseAddressList($message->headers["reply-to"]);

            $output->to = array();
            $output->cc = array();
            $output->reply_to = array();
            foreach(array("to" => $toaddr, "cc" => $ccaddr, "reply_to" => $replytoaddr) as $type => $addrlist) {
                if ($addrlist === false) {
                    //If we couldn't parse the addresslist we put the raw header (decoded)
                    if ($type == "reply_to") {
                        array_push($output->$type, $message->headers["reply-to"]);
                    }
                    else {
                        array_push($output->$type, $message->headers[$type]);
                    }
                }
                else {
                    foreach($addrlist as $addr) {
                        $address = $addr->mailbox . "@" . $addr->host;
                        $name = Utils::FixAddressName($addr->personal);

                        if (!isset($output->displayto) && $name != "")
                            $output->displayto = $name;

                        if($name == "" || $name == $address)
                            $fulladdr = $address;
                        else {
                            if (substr($name, 0, 1) != '"' && substr($name, -1) != '"') {
                                $fulladdr = "\"" . $name ."\" <" . $address . ">";
                            }
                            else {
                                $fulladdr = $name ." <" . $address . ">";
                            }
                        }

                        array_push($output->$type, $fulladdr);
                    }
                }
            }

            // convert mime-importance to AS-importance
            if (isset($message->headers["x-priority"])) {
                $mimeImportance =  preg_replace("/\D+/", "", $message->headers["x-priority"]);
                //MAIL 1 - most important, 3 - normal, 5 - lowest
                //AS 0 - low, 1 - normal, 2 - important
                if ($mimeImportance > 3)
                    $output->importance = 0;
                if ($mimeImportance == 3)
                    $output->importance = 1;
                if ($mimeImportance < 3)
                    $output->importance = 2;
            } else { /* fmbiete's contribution r1528, ZP-320 */
                $output->importance = 1;
            }

            // Attachments are not needed for MIME messages
            if($bpReturnType != SYNC_BODYPREFERENCE_MIME && isset($message->parts)) {
                $mparts = $message->parts;
                for ($i=0; $i<count($mparts); $i++) {
                    $part = $mparts[$i];
                    //recursively add parts
                    if($part->ctype_primary == "multipart" && ($part->ctype_secondary == "mixed" || $part->ctype_secondary == "alternative"  || $part->ctype_secondary == "related")) {
                        foreach($part->parts as $spart)
                            $mparts[] = $spart;
                        continue;
                    }
                    //add part as attachment if it's disposition indicates so or if it is not a text part
                    if ((isset($part->disposition) && ($part->disposition == "attachment" || $part->disposition == "inline")) ||
                        (isset($part->ctype_primary) && $part->ctype_primary != "text")) {

                        if(isset($part->d_parameters['filename']))
                            $attname = $part->d_parameters['filename'];
                        else if(isset($part->ctype_parameters['name']))
                            $attname = $part->ctype_parameters['name'];
                        else if(isset($part->headers['content-description']))
                            $attname = $part->headers['content-description'];
                        else $attname = "unknown attachment";

                        /* BEGIN fmbiete's contribution r1528, ZP-320 */
                        if (Request::GetProtocolVersion() >= 12.0) {
                            if (!isset($output->asattachments) || !is_array($output->asattachments))
                                $output->asattachments = array();

                            $attachment = new SyncBaseAttachment();

                            $attachment->estimatedDataSize = isset($part->d_parameters['size']) ? $part->d_parameters['size'] : isset($part->body) ? strlen($part->body) : 0;

                            $attachment->displayname = $attname;
                            $attachment->filereference = $folderid . ":" . $id . ":" . $i;
                            $attachment->method = 1; //Normal attachment
                            $attachment->contentid = isset($part->headers['content-id']) ? str_replace("<", "", str_replace(">", "", $part->headers['content-id'])) : "";
                            if (isset($part->disposition) && $part->disposition == "inline") {
                                $attachment->isinline = 1;
                            }
                            else {
                                $attachment->isinline = 0;
                            }

                            array_push($output->asattachments, $attachment);
                        }
                        else { //ASV_2.5
                            if (!isset($output->attachments) || !is_array($output->attachments))
                                $output->attachments = array();

                            $attachment = new SyncAttachment();

                            $attachment->attsize = isset($part->d_parameters['size']) ? $part->d_parameters['size'] : isset($part->body) ? strlen($part->body) : 0;

                            $attachment->displayname = $attname;
                            $attachment->attname = $folderid . ":" . $id . ":" . $i;
                            $attachment->attmethod = 1;
                            $attachment->attoid = isset($part->headers['content-id']) ? str_replace("<", "", str_replace(">", "", $part->headers['content-id'])) : "";

                            array_push($output->attachments, $attachment);
                        }
                        /* END fmbiete's contribution r1528, ZP-320 */
                    }
                }
            }
            // unset mimedecoder & mail
            unset($mobj);
            unset($mail);
            return $output;
        }

        return false;
    }

    /**
     * Returns message stats, analogous to the folder stats from StatFolder().
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     *
     * @access public
     * @return array/boolean
     */
    public function StatMessage($folderid, $id) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->StatMessage('%s','%s')", $folderid,  $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);
        $overview = @imap_fetch_overview( $this->mbox , $id , FT_UID);

        if (!$overview) {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->StatMessage('%s','%s'): Failed to retrieve overview: %s", $folderid,  $id, imap_last_error()));
            return false;
        }

        // check if variables for this overview object are available
        $vars = get_object_vars($overview[0]);

        // without uid it's not a valid message
        if (! array_key_exists( "uid", $vars)) return false;

        $entry = array();
        $entry["mod"] = (array_key_exists( "date", $vars)) ? $overview[0]->date : "";
        $entry["id"] = $overview[0]->uid;

        // 'seen' aka 'read'
        if (array_key_exists("seen", $vars) && $overview[0]->seen) {
            $entry["flags"] = 1;
        }
        else {
            $entry["flags"] = 0;
        }

        // 'flagged' aka 'FollowUp' aka 'starred'
        if (array_key_exists("flagged", $vars) && $overview[0]->flagged) {
            $entry["star"] = 1;
        }
        else {
            $entry["star"] = 0;
        }

        return $entry;
    }

    /**
     * Called when a message has been changed on the mobile.
     * Added support for FollowUp flag
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param SyncXXX             $message             the SyncObject containing a message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return array                        same return value as StatMessage()
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function ChangeMessage($folderid, $id, $message, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangeMessage('%s','%s','%s')", $folderid, $id, get_class($message)));
        // TODO this could throw several StatusExceptions like e.g. SYNC_STATUS_OBJECTNOTFOUND, SYNC_STATUS_SYNCCANNOTBECOMPLETED

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before changing the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_SYNCCANNOTBECOMPLETED should be thrown

        /* BEGIN fmbiete's contribution r1529, ZP-321 */
        if (isset($message->flag)) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->ChangeMessage('Setting flag')"));

            $folderImapid = $this->getImapIdFromFolderId($folderid);

            $this->imap_reopenFolder($folderImapid);

            if (isset($message->flag->flagstatus) && $message->flag->flagstatus == 2) {
                ZLog::Write(LOGLEVEL_DEBUG, "Set On FollowUp -> IMAP Flagged");
                $status = @imap_setflag_full($this->mbox, $id, "\\Flagged",ST_UID);
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "Clearing Flagged");
                $status = @imap_clearflag_full ( $this->mbox, $id, "\\Flagged", ST_UID);
            }

            if ($status) {
                ZLog::Write(LOGLEVEL_DEBUG, "Flagged changed");
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, "Flagged failed");
            }
        }

        return $this->StatMessage($folderid, $id);
        /* END fmbiete's contribution r1529, ZP-321 */
    }

    /**
     * Changes the 'read' flag of a message on disk
     *
     * @param string              $folderid            id of the folder
     * @param string              $id                  id of the message
     * @param int                 $flags               read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetReadFlag($folderid, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SetReadFlag('%s','%s','%s')", $folderid, $id, $flags));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before setting the read flag, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown

        $this->imap_reopenFolder($folderImapid);

        if ($flags == 0) {
            // set as "Unseen" (unread)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Seen", ST_UID);
        } else {
            // set as "Seen" (read)
            $status = @imap_setflag_full($this->mbox, $id, "\\Seen", ST_UID);
        }

        return $status;
    }

    /**
     * Changes the 'star' flag of a message on disk
     *
     * @param string        $folderid       id of the folder
     * @param string        $id             id of the message
     * @param int           $flags          read flag of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function SetStarFlag($folderid, $id, $flags, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->SetStarFlag('%s','%s','%s')", $folderid, $id, $flags));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        $this->imap_reopenFolder($folderImapid);

        if ($flags == 0) {
            // set as "UnFlagged" (unstarred)
            $status = @imap_clearflag_full ( $this->mbox, $id, "\\Flagged", ST_UID);
        } else {
            // set as "Flagged" (starred)
            $status = @imap_setflag_full($this->mbox, $id, "\\Flagged",ST_UID);
        }

        return $status;
    }

    /**
     * Called when the user has requested to delete (really delete) a message
     *
     * @param string              $folderid             id of the folder
     * @param string              $id                   id of the message
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_STATUS_* exceptions
     */
    public function DeleteMessage($folderid, $id, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->DeleteMessage('%s','%s')", $folderid, $id));
        $folderImapid = $this->getImapIdFromFolderId($folderid);

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before deleting the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_STATUS_OBJECTNOTFOUND should be thrown

        $this->imap_reopenFolder($folderImapid);
        $s1 = @imap_delete ($this->mbox, $id, FT_UID);
        $s11 = @imap_setflag_full($this->mbox, $id, "\\Deleted", FT_UID);
        $s2 = @imap_expunge($this->mbox);

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->DeleteMessage('%s','%s'): result: s-delete: '%s' s-expunge: '%s' setflag: '%s'", $folderid, $id, $s1, $s2, $s11));

        return ($s1 && $s2 && $s11);
    }

    /**
     * Called when the user moves an item on the PDA from one folder to another
     *
     * @param string              $folderid            id of the source folder
     * @param string              $id                  id of the message
     * @param string              $newfolderid         id of the destination folder
     * @param ContentParameters   $contentParameters
     *
     * @access public
     * @return boolean                      status of the operation
     * @throws StatusException              could throw specific SYNC_MOVEITEMSSTATUS_* exceptions
     */
    public function MoveMessage($folderid, $id, $newfolderid, $contentParameters) {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s')", $folderid, $id, $newfolderid));
        $folderImapid = $this->getImapIdFromFolderId($folderid);
        $newfolderImapid = $this->getImapIdFromFolderId($newfolderid);

        // TODO SyncInterval check + ContentParameters
        // see https://jira.zarafa.com/browse/ZP-258 for details
        // before moving the message, it should be checked if the message is in the SyncInterval
        // to determine the cutoffdate use Utils::GetCutOffDate($contentparameters->GetFilterType());
        // if the message is not in the interval an StatusException with code SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID should be thrown

        if ($folderImapid == $newfolderImapid) {
            throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, destination folder is source folder. Canceling the move.", $folderid, $id, $newfolderid), SYNC_MOVEITEMSSTATUS_SAMESOURCEANDDEST);
        }

        $this->imap_reopenFolder($folderImapid);

        // read message flags
        $overview = @imap_fetch_overview ( $this->mbox , $id, FT_UID);

        if (!$overview)
            throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to retrieve overview of source message: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDSOURCEID);
        else {
            // get next UID for destination folder
            // when moving a message we have to announce through ActiveSync the new messageID in the
            // destination folder. This is a "guessing" mechanism as IMAP does not inform that value.
            // when lots of simultaneous operations happen in the destination folder this could fail.
            // in the worst case the moved message is displayed twice on the mobile.
            $destStatus = imap_status($this->mbox, $this->server . $newfolderImapid, SA_ALL);
            if (!$destStatus)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, unable to open destination folder: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_INVALIDDESTID);

            $newid = $destStatus->uidnext;

            // move message
            $s1 = imap_mail_move($this->mbox, $id, $newfolderImapid, CP_UID);
            if (! $s1)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, copy to destination folder failed: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);


            // delete message in from-folder
            $s2 = imap_expunge($this->mbox);

            // open new folder
            $stat = $this->imap_reopenFolder($newfolderImapid);
            if (! $s1)
                throw new StatusException(sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): Error, opening the destination folder: %s", $folderid, $id, $newfolderid, imap_last_error()), SYNC_MOVEITEMSSTATUS_CANNOTMOVE);


            // remove all flags
            $s3 = @imap_clearflag_full ($this->mbox, $newid, "\\Seen \\Answered \\Flagged \\Deleted \\Draft", FT_UID);
            $newflags = "";
            if ($overview[0]->seen) $newflags .= "\\Seen";
            if ($overview[0]->flagged) $newflags .= " \\Flagged";
            if ($overview[0]->answered) $newflags .= " \\Answered";
            $s4 = @imap_setflag_full ($this->mbox, $newid, $newflags, FT_UID);

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->MoveMessage('%s','%s','%s'): result s-move: '%s' s-expunge: '%s' unset-Flags: '%s' set-Flags: '%s'", $folderid, $id, $newfolderid, Utils::PrintAsString($s1), Utils::PrintAsString($s2), Utils::PrintAsString($s3), Utils::PrintAsString($s4)));

            // return the new id "as string""
            return $newid . "";
        }
    }


    /**
     * Returns the BackendIMAP as it implements the ISearchProvider interface
     * This could be overwritten by the global configuration
     *
     * @access public
     * @return object       Implementation of ISearchProvider
     */
    public function GetSearchProvider() {
        return $this;
    }


    /**----------------------------------------------------------------------------------------------------------
     * public ISearchProvider methods
     */

    /**
     * Indicates if a search type is supported by this SearchProvider
     *
     * @param string        $searchtype
     *
     * @access public
     * @return boolean
     */
    public function SupportsType($searchtype) {
        return ($searchtype == ISearchProvider::SEARCH_MAILBOX);
    }


    /**
     * Queries the IMAP backend
     *
     * @param string        $searchquery        string to be searched for
     * @param string        $searchrange        specified searchrange
     *
     * @access public
     * @return array        search results
     */
    public function GetGALSearchResults($searchquery, $searchrange) {
        return false;
    }

    /**
     * Searches for the emails on the server
     *
     * @param ContentParameter $cpo
     * @param string $prefix If used with the combined backend here will come the backend id and delimiter
     *
     * @return array
     */
    public function GetMailboxSearchResults($cpo, $prefix = '') {
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults()"));

        $items = false;
        $searchFolderId = $cpo->GetSearchFolderid();
        $searchRange = explode('-', $cpo->GetSearchRange());
        $filter = $this->getSearchRestriction($cpo);

        // Open the folder to search
        $search = true;

        if (empty($searchFolderId)) {
            $searchFolderId = $this->getFolderIdFromImapId('INBOX');
        }

        // Convert searchFolderId to IMAP id
        $imapId = $this->getImapIdFromFolderId($searchFolderId);

        $listMessages = array();
        $numMessages = 0;
        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: Filter <%s>", $filter));

        if ($cpo->GetSearchDeepTraversal()) { // Recursive search
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: Recursive search %s", $imapId));
            $listFolders = @imap_list($this->mbox, $this->server, "*");
            if ($listFolders === false) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->GetMailboxSearchResults: Error recursive list %s", imap_last_error()));
            }
            else {
                foreach ($listFolders as $subFolder) {
                    if (@imap_reopen($this->mbox, $subFolder)) {
                        $imapSubFolder = str_replace($this->server, "", $subFolder);
                        $subFolderId = $this->getFolderIdFromImapId($imapSubFolder);
                        if ($subFolderId !== false) { // only search found folders
                            $subList = @imap_search($this->mbox, $filter, SE_UID, "UTF-8");
                            if ($subList !== false) {
                                $numMessages += count($subList);
                                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: SubSearch in %s : %s ocurrences", $imapSubFolder, count($subList)));
                                $listMessages[] = array($subFolderId => $subList);
                            }
                        }
                    }
                }
            }
        }
        else { // Search in folder
            if (@imap_reopen($this->mbox, $this->server . $imapId)) {
                $subList = @imap_search($this->mbox, $filter, SE_UID, "UTF-8");
                if ($subList !== false) {
                    $numMessages += count($subList);
                    ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: Search in %s : %s ocurrences", $imapId, count($subList)));
                    $listMessages[] = array($searchFolderId => $subList);
                }
            }
        }


        if ($numMessages > 0) {
            // range for the search results
            $rangestart = 0;
            $rangeend = SEARCH_MAXRESULTS - 1;

            if (is_array($searchRange) && isset($searchRange[0]) && isset($searchRange[1])) {
                $rangestart = $searchRange[0];
                $rangeend = $searchRange[1];
            }

            $querycnt = $numMessages;
            $items = array();
            $querylimit = (($rangeend + 1) < $querycnt) ? ($rangeend + 1) : $querycnt + 1;
            $items['range'] = $rangestart.'-'.($querylimit - 1);
            $items['searchtotal'] = $querycnt;

            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: %s entries found, returning %s", $items['searchtotal'], $items['range']));

            $p = 0;
            $pc = 0;
            for ($i = $rangestart, $j = 0; $i <= $rangeend && $i < $querycnt; $i++, $j++) {
                $keys = array_keys($listMessages[$p]);
                $cntFolder = count($listMessages[$p][$keys[0]]);
                if ($pc >= $cntFolder) {
                    $p++;
                    $pc = 0;
                    $keys = array_keys($listMessages[$p]);
                }
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: %s %s %s %s", $p, $pc, $keys[0], $listMessages[$p][$keys[0]][$pc]));
                $foundFolderId = $keys[0];
                $items[$j]['class'] = 'Email';
                $items[$j]['longid'] = $prefix . $foundFolderId . ":" . $listMessages[$p][$foundFolderId][$pc];
                $items[$j]['folderid'] = $prefix . $foundFolderId;
                $pc++;
            }
        }
        else {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->GetMailboxSearchResults: No messages found!"));
        }

        return $items;
    }

    /**
    * Terminates a search for a given PID
    *
    * @param int $pid
    *
    * @return boolean
    */
    public function TerminateSearch($pid) {
        return true;
    }

    /**
     * Disconnects from IMAP
     *
     * @access public
     * @return boolean
     */
    public function Disconnect() {
        // Don't close the mailbox, we will need it open in the Backend methods
        return true;
    }


    /**
     * Creates a search restriction
     *
     * @param ContentParameter $cpo
     * @return string
     */
    private function getSearchRestriction($cpo) {
        $searchText = $cpo->GetSearchFreeText();
        $searchGreater = $cpo->GetSearchValueGreater();
        $searchLess = $cpo->GetSearchValueLess();

        $filter = '';
        if ($searchGreater != '') {
            $filter .= ' SINCE "' . $searchGreater . '"';
        } else {
            // Only search in sync messages
            $limitdate = new DateTime();
            switch (SYNC_FILTERTIME_MAX) {
                case SYNC_FILTERTYPE_1DAY:
                    $limitdate = $limitdate->sub(new DateInterval("P1D"));
                    break;
                case SYNC_FILTERTYPE_3DAYS:
                    $limitdate = $limitdate->sub(new DateInterval("P3D"));
                    break;
                case SYNC_FILTERTYPE_1WEEK:
                    $limitdate = $limitdate->sub(new DateInterval("P1W"));
                    break;
                case SYNC_FILTERTYPE_2WEEKS:
                    $limitdate = $limitdate->sub(new DateInterval("P2W"));
                    break;
                case SYNC_FILTERTYPE_1MONTH:
                    $limitdate = $limitdate->sub(new DateInterval("P1M"));
                    break;
                case SYNC_FILTERTYPE_3MONTHS:
                    $limitdate = $limitdate->sub(new DateInterval("P3M"));
                    break;
                case SYNC_FILTERTYPE_6MONTHS:
                    $limitdate = $limitdate->sub(new DateInterval("P6M"));
                    break;
                default:
                    $limitdate = false;
                    break;
            }

            if ($limitdate !== false) {
                // date format : 7 Jan 2012
                $filter .= ' SINCE "' . ($limitdate->format("d M Y")) . '"';
            }
        }
        if ($searchLess != '') {
            $filter .= ' BEFORE "' . $searchLess . '"';
        }

        $filter .= ' BODY "' . $searchText . '"';

        return $filter;
    }


    /**----------------------------------------------------------------------------------------------------------
     * protected IMAP methods
     */

    /**
     * Unmasks a hex folderid and returns the imap folder id
     *
     * @param string        $folderid       hex folderid generated by convertImapId()
     *
     * @access protected
     * @return string       imap folder id
     */
    protected function getImapIdFromFolderId($folderid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFidFimap)) {
            if (isset($this->permanentStorage->fmFidFimap[$folderid])) {
                $imapId = $this->permanentStorage->fmFidFimap[$folderid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, $imapId));
                return $imapId;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getImapIdFromFolderId('%s') = %s", $folderid, 'not initialized!'));
        return false;
    }

    /**
     * Retrieves a hex folderid previousily masked imap
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function getFolderIdFromImapId($imapid) {
        $this->InitializePermanentStorage();

        if (isset($this->permanentStorage->fmFimapFid)) {
            if (isset($this->permanentStorage->fmFimapFid[$imapid])) {
                $folderid = $this->permanentStorage->fmFimapFid[$imapid];
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, $folderid));
                return $folderid;
            }
            else {
                ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not found'));
                return false;
            }
        }
        ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->getFolderIdFromImapId('%s') = %s", $imapid, 'not initialized!'));
        return false;
    }

    /**
     * Masks a imap folder id into a generated hex folderid
     * The method getFolderIdFromImapId() is consulted so that an
     * imapid always returns the same hex folder id
     *
     * @param string        $imapid         Imap folder id
     *
     * @access protected
     * @return string       hex folder id
     */
    protected function convertImapId($imapid) {
        $this->InitializePermanentStorage();

        // check if this imap id was converted before
        $folderid = $this->getFolderIdFromImapId($imapid);

        // nothing found, so generate a new id and put it in the cache
        if (!$folderid) {
            // generate folderid and add it to the mapping
            $folderid = sprintf('%04x%04x', mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ));

            // folderId to folderImap mapping
            if (!isset($this->permanentStorage->fmFidFimap))
                $this->permanentStorage->fmFidFimap = array();

            $a = $this->permanentStorage->fmFidFimap;
            $a[$folderid] = $imapid;
            $this->permanentStorage->fmFidFimap = $a;

            // folderImap to folderid mapping
            if (!isset($this->permanentStorage->fmFimapFid))
                $this->permanentStorage->fmFimapFid = array();

            $b = $this->permanentStorage->fmFimapFid;
            $b[$imapid] = $folderid;
            $this->permanentStorage->fmFimapFid = $b;
        }

        ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->convertImapId('%s') = %s", $imapid, $folderid));

        return $folderid;
    }

    /**
     * Get all parts in the message with specified type and concatenate them together, unless the
     * Content-Disposition is 'attachment', in which case the text is apparently an attachment
     *
     * @param string        $message        mimedecode message(part)
     * @param string        $message        message subtype
     * @param string        &$body          body reference
     *
     * @access protected
     * @return
     */
    protected function getBodyRecursive($message, $subtype, &$body) {
        if(!isset($message->ctype_primary)) return;
        if(strcasecmp($message->ctype_primary,"text")==0 && strcasecmp($message->ctype_secondary,$subtype)==0 && isset($message->body))
            $body .= $message->body;

        if(strcasecmp($message->ctype_primary,"multipart")==0 && isset($message->parts) && is_array($message->parts)) {
            foreach($message->parts as $part) {
                if(!isset($part->disposition) || strcasecmp($part->disposition,"attachment"))  {
                    $this->getBodyRecursive($part, $subtype, $body);
                }
            }
        }
    }

    /**
     * Returns the serverdelimiter for folder parsing
     *
     * @access protected
     * @return string       delimiter
     */
    protected function getServerDelimiter() {
        $list = @imap_getmailboxes($this->mbox, $this->server, "*");
        if (is_array($list)) {
            $val = $list[0];

            return $val->delimiter;
        }
        return "."; // default "."
    }

    /**
     * Helper to re-initialize the folder to speed things up
     * Remember what folder is currently open and only change if necessary
     *
     * @param string        $folderid       id of the folder
     * @param boolean       $force          re-open the folder even if currently opened
     *
     * @access protected
     * @return boolean      if folder is opened
     */
    protected function imap_reopenFolder($folderid, $force = false) {
        // if the stream is not alive, we open it again
        if (!@imap_ping($this->mbox)) {
            $this->mbox = @imap_open($this->server , $this->username, $this->password, OP_HALFOPEN);
            $this->mboxFolder = "";
        }

        // to see changes, the folder has to be reopened!
        if ($this->mboxFolder != $folderid || $force) {
            $s = @imap_reopen($this->mbox, $this->server . $folderid);
            // TODO throw status exception
            if (!$s) {
                ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->imap_reopenFolder('%s'): failed to change folder: %s",$folderid, implode(", ", imap_errors())));
                return false;
            }
            $this->mboxFolder = $folderid;
        }

        return true;
    }


    /**
     * Creates a new IMAP folder.
     *
     * @param string        $foldername     full folder name
     *
     * @access private
     * @return boolean      success
     */
    private function ($foldername) {
        $name = Utils::Utf7_iconv_encode(Utils::Utf8_to_utf7($foldername));

        res = @imap_createmailbox($this->mbox, $name);
        if (res) {
            ZLog::Write(LOGLEVEL_DEBUG, sprintf("BackendIMAP->imap_createFolder('%s'): new folder created", $foldername));
        }
        else {
            ZLog::Write(LOGLEVEL_WARN, sprintf("BackendIMAP->imap_createFolder('%s'): failed to create folder: %s", $foldername, implode(", ", imap_errors())));
        }

        return res;
    }


    /**
     * Adds a message with seen flag to a specified folder (used for saving sent items)
     *
     * @param string        $folderid       id of the folder
     * @param string        $header         header of the message
     * @param long          $body           body of the message
     *
     * @access protected
     * @return boolean      status
     */
    protected function addSentMessage($folderid, $header, $body) {
        $header_body = str_replace("\n", "\r\n", str_replace("\r", "", $header . "\n\n" . $body));

        return @imap_append($this->mbox, $this->server . $folderid, $header_body, "\\Seen");
    }

    /**
     * Parses an mimedecode address array back to a simple "," separated string
     *
     * @param array         $ad             addresses array
     *
     * @access protected
     * @return string       mail address(es) string
     */
    protected function parseAddr($ad) {
        $addr_string = "";
        if (isset($ad) && is_array($ad)) {
            foreach($ad as $addr) {
                if ($addr_string) $addr_string .= ",";
                    $addr_string .= $addr->mailbox . "@" . $addr->host;
            }
        }
        return $addr_string;
    }

    /**
     * Recursive way to get mod and parent - repeat until only one part is left
     * or the folder is identified as an IMAP folder
     *
     * @param string        $fhir           folder hierarchy string
     * @param string        &$displayname   reference of the displayname
     * @param long          &$parent        reference of the parent folder
     *
     * @access protected
     * @return
     */
    protected function getModAndParentNames($fhir, &$displayname, &$parent) {
        // if mod is already set add the previous part to it as it might be a folder which has
        // delimiter in its name
        $displayname = (isset($displayname) && strlen($displayname) > 0) ? $displayname = array_pop($fhir).$this->serverdelimiter.$displayname : array_pop($fhir);
        $parent = implode($this->serverdelimiter, $fhir);

        if (count($fhir) == 1 || $this->checkIfIMAPFolder($parent)) {
            return;
        }
        //recursion magic
        $this->getModAndParentNames($fhir, $displayname, $parent);
    }

    /**
     * Checks if a specified name is a folder in the IMAP store
     *
     * @param string        $foldername     a foldername
     *
     * @access protected
     * @return boolean
     */
    protected function checkIfIMAPFolder($folderName) {
        $parent = imap_list($this->mbox, $this->server, $folderName);
        if ($parent === false) return false;
        return true;
    }

    /**
     * Removes parenthesis (comments) from the date string because
     * strtotime returns false if received date has them
     *
     * @param string        $receiveddate   a date as a string
     *
     * @access protected
     * @return string
     */
    protected function cleanupDate($receiveddate) {
        $receiveddate = strtotime(preg_replace("/\(.*\)/", "", $receiveddate));
        if ($receiveddate == false || $receiveddate == -1) {
            ZLog::Write(LOGLEVEL_DEBUG, "cleanupDate() : Received date is false. Message might be broken.");
            return null;
        }

        return $receiveddate;
    }

    /**
     * Returns the default value for "From"
     *
     * @access private
     * @return string
     */
    private function getDefaultFromValue() {
        $v = "";
        if (IMAP_DEFAULTFROM == 'username') {
            $v = $this->username;
        }
        else if (IMAP_DEFAULTFROM == 'domain') {
            $v = $this->domain;
        }
        else {
            $v = $this->username . IMAP_DEFAULTFROM;
        }

        return $v;
    }

    /* BEGIN fmbiete's contribution r1528, ZP-320 */
    /**
     * Indicates which AS version is supported by the backend.
     *
     * @access public
     * @return string       AS version constant
     */
    public function GetSupportedASVersion() {
        return ZPush::ASV_14;
    }
    /* END fmbiete's contribution r1528, ZP-320 */
};

?>