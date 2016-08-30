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

   public function __construct($config) {
      $this->twilio = $config['twilio'];
      $this->checks = $config['checks'];
      $this->groups = $config['groups'];
      $this->alertees = $config['alertees'];
   }

   /**
    * Run through every check, alerting the appropriate alertees on check
    * failure.
    *
    * :raises Exception: Rethrows any non-expected Exceptions thrown in the
    *                    checks.
    */
   public function check() {
      foreach ($this->checks as $check => $alerteeGroups) {
         try {
            call_user_func($check);
         } catch (AlertinatorException $e) {
            $this->alertGroups($e, $alerteeGroups);
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

