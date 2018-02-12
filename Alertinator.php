<?php

require 'twilio-php/Services/Twilio.php';

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
   public $emailSubject = 'Alert';

   protected $_twilio;

   public function __construct($config, alertLogger $logger = NULL) {
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
         $remindEvery   = $properties['remindEvery'] ?? $alertAfter;
         $remindEvery   = $remindEvery ?: 1;
         $alerteeGroups = $properties['groups'] ?? $properties;
         
         try {
            call_user_func($check);
            if ($clearAfter && $this->logger->isInAlert($check)) {
               $this->logger->writeAlert($check, 1, time());
               $this->notifyClear($check, $alertAfter, $clearAfter, $alerteeGroups);
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
   private function notifyClear($check, $alertAfter, $clearAfter, $alerteeGroups) {
      $log = $this->logger->readAlerts($check);
      krsort($log);
      
      // We only get here if there was at least 1 failure, but 1 failure may not
      // exceed the alertAfter threshold. If the check succeeds without
      // reaching the alert threshold, reset the log silently.
      // TODO: this will break terribly for bouncing errors, e.g. pass-> fail->
      //       pass-> fail-> pass-> fail. Account for this.
      if (count($log) < $alertAfter) {
         $this->logger->resetAlerts($check);
         return;
      }
      
      if (count($log) >= $clearAfter) {
         $clears = 0;
         // Only notify a clear if the check passes > $clearAfter in a row.
         foreach($log as $alert) {
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
   private function notifyFailure($check, $alertAfter, $alerteeGroups, $e, $remindEvery) {
      $log = $this->logger->readAlerts($check);
      $fails = 0;

      foreach ($log as $alert) {
         if (!$alert['status']) {
            $fails++;
         }
      }

      $reminderTriggered = $fails > $alertAfter
       && ($fails - $alertAfter) % $remindEvery == 0;

      if ($fails === $alertAfter || $reminderTriggered) {
         $last = end($log);
         $newMsg = "Threshold of $alertAfter reached at "
          . date(DATE_RFC2822, $last['ts'])
          . ($reminderTriggered ? " (reminding every {$remindEvery} fails)" : '')
          . ": ";
         $e = $this->prependExceptionMessage($e, $newMsg);
         $this->alertGroups($e, $alerteeGroups);
      }
   }
   
   protected function alertGroups($exception, $alerteeGroups) {
      $alertees = $this->extractAlertees($alerteeGroups);
      foreach ($alertees as $alertee) {
         $this->alert($exception, $this->alertees[$alertee]);
      }
   }

   /**
    * :param iterable $alerteeGroups: An iterable of strings corresponding to
    *                                 group names in ``$this->groups``.
    * :returns: An iterable of strings corresponding to alertee names in
    *           ``$this->alertees``.
    */
   protected function extractAlertees($alerteeGroups) {
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
    */
   protected function alert($exception, $alertee) {
      foreach (array_keys($alertee) as $contactMethod) {
         list($destination, $alertingLevel) = $alertee[$contactMethod];
         if ($exception::bitmask & $alertingLevel) {
            $this->$contactMethod($destination, $exception->getMessage());
         }
      }
   }
   
   /**
    * We sometimes need to modify the Exception's message for threshold alerts.
   */
   private function prependExceptionMessage($e, $newMessage) {
      $oldMsg = $e->getMessage();
      $newMsg = $newMessage . $oldMsg;
      $eClass = get_class($e);
      return new $eClass($newMsg);
   }

   /**
    * Send an email to ``$address`` with ``$message`` as the body.
    */
   protected function email($address, $message) {
      if (!mail($address, $this->emailSubject, $message)) {
         throw new Exception("Sending email to $address failed.");
      }
   }

   /**
    * Send an SMS of ``$message`` through Twilio to ``$number``.
    */
   protected function sms($number, $message) {
      // For reasons unknown, SMS doesn't seem to need the '+1' prepended like
      // phone calls do.  I probably just don't understand telephones.
      //$number = '+1' . $number;
      $this->getTwilioSms()->sendMessage(
       $this->twilio['fromNumber'], $number, $message);
   }

   /**
    * Make a phone call through Twilio to ``$number``, with text-to-speech of
    * ``$message``.
    */
   protected function call($number, $message) {
      $twiml = new Services_Twilio_Twiml();
      $twiml->say($message);
      $messageUrl = 'http://twimlets.com/echo?Twiml=' . urlencode($twiml);

      $number = '+1' . $number;
      $this->getTwilioCall()->create(
       $this->twilio['fromNumber'], $number, $messageUrl);
   }

   /**
    * Return an object capable of sending Twilio SMS messages.
    *
    * This function exists partly to ease mocking, and partly to abstract away
    * Twilio's deep object inheritance.
    */
   protected function getTwilioSms() {
      return $this->getTwilio()->account->messages;
   }

   /**
    * Return an object capable of making Twilio calls.
    *
    * This function exists partly to ease mocking, and partly to abstract away
    * Twilio's deep object inheritance.
    */
   protected function getTwilioCall() {
      return $this->getTwilio()->account->calls;
   }

   /**
    * Return a configured :class:`Services_Twilio` object.
    */
   protected function getTwilio() {
      if (!$this->_twilio) {
         $this->_twilio = new Services_Twilio(
            $this->twilio['accountSid'],
            $this->twilio['authToken']);
      }
      return $this->_twilio;
   }
}

interface alertLogger {
   public function writeAlert($name, $status, $ts);
   public function readAlerts($name);
   public function resetAlerts($name);
   public function isInAlert($name);
}

class fileLogger implements alertLogger {
   
   /**
    * Write an alert to the logger.
    *
    * @param $name string  The key of the alert. Usually the name of the check fn.
    * @param $status bool  0 = fail, 1 = success
    * @param $ts int  Unix Timestamp of the event.
   */
   public function writeAlert($name, $status, $ts = FALSE) {
      $ts = $ts ?: time();
      $log = $this->readAlerts($name);
      
      $alert = [
                  'ts' => $ts,
                  'status' => $status,
                  'check' => $name,
               ];
      
      $log[] = $alert;
      if (!$fp = fopen($this->getLogFileName($name), 'w')) {
         throw new Exception("Could not open log file " . $this->getLogFileName($name) . " for writing.");
      }
      fwrite($fp, json_encode($log));
      fclose($fp); 
   }
   
   /**
    * Return all alerts for a given key.
   */
   public function readAlerts($name) {
      $log = array();
      if (file_exists($this->getLogFileName($name))) {
         $prevLog = file_get_contents($this->getLogFileName($name));
         $log = json_decode($prevLog, true);  
      }
      return $log;
   }
   
   /**
    * Safely resets all alerts for a given key.
   */
   public function safelyResetAlerts($name) {
      try {!unlink($this->getLogFileName($name)); }
      catch (Exception $e) { }
   }

   /**
    * Reset all alerts for a given key.
   */
   public function resetAlerts($name) {
      if(!unlink($this->getLogFileName($name))) {
         throw new Exception("Could not reset log file " . $this->getLogFileName($name) . "!");
      }
   }
   
   /**
    * Determine if there's at least one failure recorded.
   */
   public function isInAlert($name) {
      return count($this->readAlerts($name));
   }
   
   private function getLogFileName($name) {
      $file = strtolower(mb_ereg_replace("([^\w\s\d\-_~,;:\[\]\(\).])", '', $name));
      return $this->getTmpDir() . '/' . $file . '.log';
   }
   
   private function getTmpDir() {
      return ini_get('upload_tmp_dir') ?: sys_get_temp_dir();
   }
}
