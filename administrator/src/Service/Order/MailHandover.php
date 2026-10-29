<?php

/**
 * @package    Alfa Commerce
 * @author     Agamemnon Fakas <info@easylogic.gr>
 * @copyright  (C) 2024-2026 Easylogic CO LP / Agamemnon Fakas. All rights reserved.
 * @license    GNU General Public License version 3 or later; see LICENSE
 */

namespace Alfa\Component\Alfa\Administrator\Service\Order;

defined('_JEXEC') or die;

use Joomla\CMS\Mail\Mail;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

/**
 * Whether an e-mail was handed over to the SMTP server, and what the server answered, so a failed send is known
 * (refused before the message was handed over, or refused in the server's answer to it) or unknown (handed over with
 * no answer: the message may have been delivered).
 *
 * It reads the client's SMTP dialogue as PHPMailer reports it, line by line, and keeps nothing of it: the message
 * has been handed over once its last line (a lone ".") went out, and the next reply is the server's answer to it.
 * Joomla's mailer sends a second time, without opportunistic TLS, after any failed attempt; once a message was handed
 * over that second attempt could deliver it twice, so it is stopped before it starts.
 *
 * @since  2.1.4
 */
final class MailHandover
{
    /**
     * Whether the message's last line went out in an attempt.
     *
     * @since  2.1.4
     */
    private bool $handedOver = false;

    /**
     * Whether the server's answer to the handed-over message is still awaited.
     *
     * @since  2.1.4
     */
    private bool $awaiting = false;

    /**
     * The server's reply code to the handed-over message, '' while none came.
     *
     * @since  2.1.4
     */
    private string $answer = '';

    /**
     * Watch a mailer's SMTP dialogue; a mailer that does not speak SMTP has nothing to watch.
     *
     * @param Mail $mailer The mailer, before it sends.
     *
     * @since  2.1.4
     */
    public static function watch(Mail $mailer): self
    {
        $watch = new self();

        if ($mailer->Mailer === 'smtp') {
            $mailer->SMTPDebug = SMTP::DEBUG_SERVER;
            $mailer->Debugoutput = $watch;
        }

        return $watch;
    }

    /**
     * One line of the SMTP dialogue (PHPMailer's debug output, never stored or shown).
     *
     * @param string $line The line.
     * @param int $level Its debug level.
     *
     * @throws RuntimeException When a new attempt starts after the message was handed over.
     *
     * @since  2.1.4
     */
    public function __invoke(string $line, int $level): void
    {
        if (str_starts_with($line, 'CLIENT -> SERVER: ')) {
            $command = rtrim(substr($line, 18), "\r\n");

            if ($this->handedOver && (str_starts_with($command, 'EHLO ') || str_starts_with($command, 'HELO '))) {
                throw new RuntimeException('The message was handed over to the mail server, which gave no answer; it is not sent a second time.');
            }

            if ($command === '.') {
                $this->handedOver = true;
                $this->awaiting = true;
                $this->answer = '';
            }

            return;
        }

        if ($this->awaiting && str_starts_with($line, 'SERVER -> CLIENT: ')) {
            $this->awaiting = false;
            $this->answer = substr(trim(substr($line, 18)), 0, 3);
        }
    }

    /**
     * The outcome of the send.
     *
     * @param bool $sent Whether the mailer reported success.
     *
     * @return string EmailService::OUTCOME_SENT, OUTCOME_FAILED or OUTCOME_UNKNOWN.
     *
     * @since  2.1.4
     */
    public function outcome(bool $sent): string
    {
        if ($sent || ($this->handedOver && str_starts_with($this->answer, '2'))) {
            return EmailService::OUTCOME_SENT;
        }

        if ($this->handedOver && ($this->answer === '' || !\in_array($this->answer[0], ['4', '5'], true))) {
            return EmailService::OUTCOME_UNKNOWN;
        }

        return EmailService::OUTCOME_FAILED;
    }
}
