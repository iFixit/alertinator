<?php

require 'twilio-php/Services/Twilio.php';

class AlertinatorException extends Exception {}
class AlertinatorNoticeException extends AlertinatorException {}
class AlertinatorWarningException extends AlertinatorException {}
class AlertinatorCriticalException extends AlertinatorException {}

class Alertinator {
   const NOTICE = 1;   // 001
   const WARNING = 2;  // 010
   const CRITICAL = 4; // 100

   public $twilio;
   public $checks;
   public $groups;
   public $alertees;

   public function __construct($config) {
      $this->twilio = $config['twilio'];
      $this->checks = $config['checks'];
      $this->groups = $config['groups'];
      $this->alertees = $config['alertees'];
   }

   public function check() {
      foreach ($this->checks as $check => $alerteeGroups) {
         try {
            call_user_func($check);
         } catch (AlertinatorException $e) {
            $this->alert($e, $alerteeGroups);
         }
      }
   }

   protected function alert($exception, $alerteeGroups) {
      $alertees = $this->extractAlertees($alerteeGroups);
      foreach ($alertees as $alertee) {
         // alert
      }
   }

   /**
    * :param iterable $alerteeGroups: An iterable of strings corresponding to
    *                                 group names in `$this->groups`.
    * :returns: An iterable of strings corresponding to alertee names in
    *           `$this->alertees`.
    */
   protected function extractAlertees($alerteeGroups) {
      $alertees = [];
      foreach ($alerteeGroups as $alerteeGroup) {
         $alertees = array_merge($alertees, $this->groups[$alerteeGroup]);
      }
      return array_unique($alertees);
   }
}

