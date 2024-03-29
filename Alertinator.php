<?php

require 'twilio-php/src/Twilio/autoload.php';

use Twilio\Rest\Client;
use Twilio\TwiML\VoiceResponse;
use Twilio\Rest\Api\V2010\Account\MessageList;
use Twilio\Rest\Api\V2010\Account\CallList;

/**
 * The base exception class Alertinator uses.
 *
 * Derived exceptions are used to trigger an alert.  The only requirement is
 * that they must have a class constant ``bitmask``, which will be ORed
 * together with each alertee's notify levels to determine whether a particular
 * alerting method will be used.
 */
abstract class AlertinatorException extends Exception {}
class AlertinatorNoticeException extends AlertinatorException {
   const bitmask = Alertinator::NOTICE;
}
class AlertinatorWarningException extends AlertinatorException {
   const bitmask = Alertinator::WARNING;
}
class AlertinatorCriticalException extends AlertinatorException {
   const bitmask = Alertinator::CRITICAL;
}

class Alertinator {
   const NOTICE = 1;   // 001
   const WARNING = 2;  // 010
   const CRITICAL = 4; // 100
   const ALL = 7;      // 111

   public $twilio;
   public $checks;
   public $groups;
   public $alertees;
   public $logger;
   public $emailSubject = 'Alert';

   protected $_twilio;

   public function __construct(array $config, alertLogger $logger = null) {
      $this->twilio = $config['twilio'];
      $this->checks = $config['checks'];
      $this->groups = $config['groups'];
      $this->alertees = $config['alertees'];
      $this->logger = $logger ?? new fileLogger();
   }

   /**
    * Run through every check, alerting the appropriate alertees on check
    * failure.
    *
    * :raises Exception: Rethrows any non-expected Exceptions thrown in the
    *                    checks.
    */
   public function check() {
      foreach ($this->checks as $check => $properties) {
         // For compatibility with old-style (short style?) check declaration,
         // determine if *After properties were defined.
         $alertAfter    = $properties['alertAfter'] ?? 0;
         $clearAfter    = $properties['clearAfter'] ?? 0;
         $remindEvery   = $properties['remindEvery'] ?? $alertAfter ?: 1;
         $alerteeGroups = $properties['groups'] ?? $properties;
         $allClearGroup = array_key_exists('allClear', $this->groups) ?
          ['allClear'] : $alerteeGroups;

         try {
            call_user_func($check);
            if ($clearAfter && $this->logger->isInAlert($check)) {
               $this->logger->writeAlert($check, 1, time());
               $this->notifyClear($check, $alertAfter, $clearAfter, $allClearGroup);
            }
         } catch (AlertinatorException $e) {
            $this->logger->writeAlert($check, 0, time());
            $this->notifyFailure($check, $alertAfter, $alerteeGroups, $e, $remindEvery);
         } catch (Exception $e) {
            // If there's an error in your check functions, you damn better
            // know about it.
            $message = "Internal failure in check:\n" . $e->getMessage();
            $this->alertGroups(
             new AlertinatorWarningException($message),
             $alerteeGroups
            );
            // Rethrow so your standard exception handler also gets it.
            throw $e;
         }
      }
   }

   /**
    * Threshold notification: determine if an all-clear alert should be sent,
    * and if so, send it and reset the logger.
   */
   private function notifyClear(string $check, int $alertAfter, int $clearAfter, array $alerteeGroups) {
      $log = $this->logger->readAlerts($check);
      krsort($log);

      // We only get here if there was at least 1 failure, but 1 failure may not
      // exceed the alertAfter threshold. If the check succeeds without
      // reaching the alert threshold, reset the log silently.
      // Note: this will break terribly for bouncing errors, e.g. pass-> fail->
      //       pass-> fail-> pass-> fail. Account for this.
      if (count($log) < $alertAfter) {
         $this->logger->resetAlerts($check);
      } else if (count($log) >= $clearAfter) {
         $clears = 0;
         // Only notify a clear if the check passes > $clearAfter in a row.
         foreach ($log as $alert) {
            if (!$alert['status']) {
               break;
            }
            $clears++;
         }
         if ($clears >= $clearAfter) {
            $last = end($log);
            $message = "The alert '$check' was cleared at " . date(DATE_RFC2822, $last['ts']) . ".";
            // TODO: It is impossible here to know what exception level should
            // be sent. In absence of this information we have to just blast it
            // to everyone. Once https://github.com/iFixit/alertinator/issues/3
            // is implemented, exception level should be available.
            $e = new AlertinatorCriticalException($message);
            $this->alertGroups($e, $alerteeGroups);
            $this->logger->resetAlerts($check);
         }
      }
   }

   /**
    * Threshold notification: determine if a failure alert should be sent, and
    * if so, send it.
   */
   private function notifyFailure(string $check, int $alertAfter,
    iterable $alerteeGroups, Exception $e, int $remindEvery) {
      $log = $this->logger->readAlerts($check);
      $fails = 0;

      foreach ($log as $alert) {
         if ($alert['status']) {
            break;
         }
         $fails++;
      }

      $atAlertThreshold = $fails === $alertAfter;
      $pastAlertThreshold = $fails > $alertAfter;
      $remindThisInterval = (($fails - $alertAfter) % $remindEvery) == 0;
      $reminderNotification = $remindThisInterval && $pastAlertThreshold ?
       " (reminding every {$remindEvery} fails)" : '';

      if ($atAlertThreshold || ($pastAlertThreshold && $remindThisInterval)) {
         $last = end($log);
         $textPrefix = "Threshold of $alertAfter reached at "
          . date(DATE_RFC2822, $last['ts']) . $reminderNotification . ": ";
         $this->alertGroups($e, $alerteeGroups, $textPrefix);
      }
   }

   /**
    * Alert one or more alertee groups.
    *
    * :param AlertinatorException $exception: The exception containing
    *                                         information about the alert.
    * :param iterable $alerteeGroups: An array of group names.
    * :param ?string $textPrefix: A prefix to apply to the exception message
    *      in textual contact methods only. This is meant to allow more detailed
    *      reporting without wasting time during voice calls.
    */
   public function alertGroups(Exception $exception, iterable $alerteeGroups, string $textPrefix = null) {
      $alertees = $this->extractAlertees($alerteeGroups);
      foreach ($alertees as $alertee) {
         $this->alert($exception, $this->alertees[$alertee], $textPrefix);
      }
   }

   /**
    * :param iterable $alerteeGroups: An iterable of strings corresponding to
    *                                 group names in ``$this->groups``.
    * :returns: An iterable of strings corresponding to alertee names in
    *           ``$this->alertees``.
    */
   protected function extractAlertees(iterable $alerteeGroups): iterable {
      $alertees = [];
      foreach ($alerteeGroups as $alerteeGroup) {
         $alertees = array_merge($alertees, $this->groups[$alerteeGroup]);
      }
      return array_unique($alertees);
   }

   /**
    * Alert an alertee.
    *
    * :param AlertinatorException $exception: The exception containing
    *                                         information about the alert.
    * :param array $alertee: An array describing an alertee in the format
    *                        of ``$this->alertees``.
    * :param ?string $textPrefix: A prefix to apply to the exception message
    *      in textual contact methods only. This is meant to allow more detailed
    *      reporting without wasting time during voice calls.
    */
   protected function alert(AlertinatorException $exception, iterable $alertee, string $textPrefix = null): void {
      foreach ($alertee as $contactMethod => [$destination, $alertingLevel]) {
         if ($exception::bitmask & $alertingLevel) {
            $message = $exception->getMessage();

            if ($contactMethod !== 'call') {
               $message = $textPrefix . $message;
            }

            $this->$contactMethod($destination, $message);
         }
      }
   }

   /**
    * This will preceed every voice alert.
    */
   protected function getVoicePrefix(): string {
      return 'Alertinator! ';
   }

   /**
    * Send an email to ``$address`` with ``$message`` as the body.
    */
   protected function email(string $address, string $message): void {
      if (!mail($address, $this->emailSubject, $message)) {
         throw new Exception("Sending email to $address failed.");
      }
   }

   /**
    * Send an SMS of ``$message`` through Twilio to ``$number``.
    */
   protected function sms(string $number, string $message): void {
      // For reasons unknown, SMS doesn't seem to need the '+1' prepended like
      // phone calls do.  I probably just don't understand telephones.
      //$number = '+1' . $number;
      $this->getTwilioSms()->create($number, [
         'from' => $this->twilio['fromNumber'],
         'body' => $message,
      ]);
   }

   /**
    * Make a phone call through Twilio to ``$number``, with text-to-speech of
    * ``$message``.
    */
   protected function call(string $number, string $message): void {
      $twiml = $this->getTwiML($message);
      $number = '+1' . $number;
      $this->getTwilioCall()->create($number, $this->twilio['fromNumber'], [
         'Twiml' => $twiml,
         'machineDetection' => 'Enable',
      ]);
   }

   protected function getTwiML(string $message): VoiceResponse {
      $twiml = new VoiceResponse();
      $twiml->say($this->getVoicePrefix());
      $twiml->say($message);
      $twiml->pause(['length' => 1]);

      // Connect the user to the conference call only if they press a
      // digit on the phone. This seems more reliable than answering machine
      // detection.
      $gather = $twiml->gather([
         'action' => 'https://handler.twilio.com/twiml/EHf60b496ecfab6bd73cc23f13e97c8f66',
         'numDigits' => 1,
      ]);
      $gather->say('Press any digit to connect to conference');
      return $twiml;
   }

   /**
    * Return an object capable of sending Twilio SMS messages.
    *
    * This function exists partly to ease mocking, and partly to abstract away
    * Twilio's deep object inheritance.
    */
   protected function getTwilioSms(): MessageList {
      return $this->getTwilio()->messages;
   }

   /**
    * Return an object capable of making Twilio calls.
    *
    * This function exists partly to ease mocking, and partly to abstract away
    * Twilio's deep object inheritance.
    */
   protected function getTwilioCall(): CallList {
      return $this->getTwilio()->calls;
   }

   /**
    * Return a configured :class:`Client` object.
    */
   protected function getTwilio(): Client {
      if (!$this->_twilio) {
         $this->_twilio = new Client(
            $this->twilio['accountSid'],
            $this->twilio['authToken']);
      }
      return $this->_twilio;
   }
}

interface alertLogger {
   public function writeAlert(string $name, bool $status, ?int $ts);
   public function readAlerts(string $name): array;
   public function resetAlerts(string $name);
   public function isInAlert(string $name): bool;
}

class fileLogger implements alertLogger {

   /**
    * Write an alert to the logger.
    *
    * @param $name string  The key of the alert. Usually the name of the check fn.
    * @param $status bool  0 = fail, 1 = success
    * @param $ts int  Unix Timestamp of the event.
   */
   public function writeAlert(string $name, bool $status, ?int $ts = null) {
      $filename = $this->getLogFileName($name);

      $log = $this->readAlerts($name);
      $log[] = [
       'ts' => $ts ?? time(),
       'status' => $status,
       'check' => $name,
      ];

      if (!file_put_contents($filename, json_encode($log))) {
         throw new Exception("Could not open log file " . $filename . " for writing.");
      }
   }

   /**
    * Return all alerts for a given key.
   */
   public function readAlerts(string $name): array {
      $filename = $this->getLogFileName($name);

      return file_exists($filename) ?
       json_decode(file_get_contents($filename), true) :
       [];
   }

   /**
    * Safely resets all alerts for a given key.
   */
   public function safelyResetAlerts(string $name) {
      $log = $this->getLogFileName($name);

      if (file_exists($log)) {
         unlink($log);
      }
   }

   /**
    * Reset all alerts for a given key.
   */
   public function resetAlerts(string $name) {
      if(!unlink($this->getLogFileName($name))) {
         throw new Exception("Could not reset log file " . $this->getLogFileName($name) . "!");
      }
   }

   /**
    * Determine if there's at least one failure recorded.
   */
   public function isInAlert(string $name): bool {
      return count($this->readAlerts($name));
   }

   private function getLogFileName(string $name): string {
      $file = strtolower(mb_ereg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '', $name));
      return $this->getTmpDir() . '/' . $file . '.log';
   }

   private function getTmpDir(): string {
      return ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
   }
}
