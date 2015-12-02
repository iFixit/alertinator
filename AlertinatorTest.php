<?php

// Make sure *any* failure is actually reported as a failure.
error_reporting(E_ALL);

require 'Alertinator.php';

/**
 * This mocker class exists to allow us to test protected and
 * external-service-using in Alertinator.
 */
class AlertinatorMocker extends Alertinator {
   public function extractAlertees($alerteeGroups) {
      return parent::extractAlertees($alerteeGroups);
   }

   public function alert($exception, $alertee) {
      return parent::alert($exception, $alertee);
   }

   public function email($address, $message) {
      echo "Sending message $message to $address via email.\n";
   }

   public function getTwilioSms() {
      return new TwilioMocker();
   }

   public function getTwilioCall() {
      return new TwilioMocker();
   }
}

/**
 * While normally I'd use `stdClass` to fake an object inline, you can't add
 * methods into stdClass on the fly.  You can add an anonymous function, but
 * can't call it like a method, and I'm not going to alter the source to make
 * the tests slightly better.
 */
class TwilioMocker {
   public function sendMessage($fromNumber, $toNumber, $message) {
      echo "Sending message $message to $toNumber via sms.\n";
   }
   public function create($fromNumber, $toNumber, $messageUrl) {
      echo "Sending message from $messageUrl to $toNumber via call.\n";
   }
}

class AlertinatorTest extends PHPUnit_Framework_TestCase {
   protected function setUp() {
      // Create an Alertinator with just enough config to construct.
      $this->alertinator = new AlertinatorMocker(
         [
            'twilio' => [],
            'checks' => [],
            'groups' => [],
            'alertees' => [],
         ]
      );
   }

   public function test_extractAlertees() {
      // The simplest possible case.
      $this->assertEquals(
         [],
         $this->alertinator->extractAlertees([])
      );

      // Just one group with one member.
      $this->alertinator->groups = ['one' => ['foo']];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one'])
      );

      // Multiple groups.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['bar'],
      ];
      $this->assertEquals(
         ['foo', 'bar'],
         $this->alertinator->extractAlertees(['one', 'two'])
      );

      // Unused groups.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['bar'],
      ];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one'])
      );

      // Uniqueness.
      $this->alertinator->groups = [
         'one' => ['foo'],
         'two' => ['foo'],
      ];
      $this->assertEquals(
         ['foo'],
         $this->alertinator->extractAlertees(['one', 'two'])
      );

      // Multiple members.
      $this->alertinator->groups = ['one' => ['foo', 'bar', 'baz']];
      $this->assertEquals(
         ['foo', 'bar', 'baz'],
         $this->alertinator->extractAlertees(['one'])
      );
   }

   public function test_alert() {
      $this->alertinator->twilio['fromNumber'] = '1234567890';

      // One level that's exactly right.
      $alertees = ['email' => ['foo@example.com', Alertinator::WARNING]];
      $this->expectOutputEquals(
         "Sending message foobaz to foo@example.com via email.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException('foobaz'), $alertees]
      );

      // Non-matching levels.
      $this->expectOutputEquals(
         '',
         [$this->alertinator, 'alert'],
         [new AlertinatorCriticalException('foobaz'), $alertees]
      );

      // Multiple levels associated with an alerting method.
      $alertees = ['email' => [
         'foo@example.com', Alertinator::WARNING | Alertinator::CRITICAL
      ]];
      $this->expectOutputEquals(
         "Sending message foobaz to foo@example.com via email.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException('foobaz'), $alertees]
      );

      // Multiple methods.
      $alertees = [
         'email' => ['foo@example.com', Alertinator::WARNING],
         'sms' => ['1234567890', Alertinator::WARNING],
         'call' => ['1234567890', Alertinator::WARNING],
      ];
      // Because Twilio doesn't allow you to just send along a message
      // directly, you have to create a url that returns the message.
      $twiml = new Services_Twilio_Twiml();
      $twiml->say('foobaz');
      $url = 'http://twimlets.com/echo?Twiml=' . urlencode($twiml);

      $this->expectOutputEquals(
         "Sending message foobaz to foo@example.com via email.\n"
         . "Sending message foobaz to 1234567890 via sms.\n"
         . "Sending message from $url to +11234567890 via call.\n",
         [$this->alertinator, 'alert'],
         [new AlertinatorWarningException('foobaz'), $alertees]
      );
   }
   
   public function test_error_thresholds() {
      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => [
            'AlertinatorTest::thresholdChecker' => [
               'groups' => ['default'],
               'alertAfter' => 5,
               'clearAfter' => 2,
               ],
            ],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);
      $this->expectOutputString('');
      $alertinator->check();
      
      //$this->expectOutputString('POOP');
      //$alertinator->check();
      
      
      //$this->expectOutputEquals(
      //   "",
      //   [new AlertinatorWarningException('foobaz')]
      //);
      
      //$this->expectOutputEquals(
      //   "",
      //   [$alertinator, 'check']
      //);
      //
      //$this->expectOutputEquals(
      //   "",
      //   [$alertinator, 'check']
      //);
      //
      //$this->expectOutputEquals(
      //   "",
      //   [$alertinator, 'check']
      //);
      //
      //$this->expectOutputEquals(
      //   "Sending message to alice@example.com via email.\n",
      //   [$alertinator, 'check']
      //);
      //
      //$this->expectOutputEquals(
      //   "Sending message to alice@example.com via email.\n",
      //   [$alertinator, 'check']
      //);
   }

   /**
    * @expectedException         PHPUnit_Framework_Error_Notice
    * @expectedExceptionMessage  Use of undefined constant sdf - assumed 'sdf'
    */
   public function test_check_errors() {
      $alertinator = new AlertinatorMocker([
         'twilio' => ['fromNumber' => '1234567890'],
         'checks' => ['AlertinatorTest::buggyCheck' => ['default']],
         'groups' => ['default' => ['alice']],
         'alertees' => [
            'alice' => ['email' => ['alice@example.com', Alertinator::CRITICAL]],
         ],
      ]);

      $message = "Internal failure in check:\n" .
                 "Use of undefined constant sdf - assumed 'sdf'";
      $this->expectOutputEquals(
         "Sending message $message to alice@example.com via email.\n",
         [$alertinator, 'check']
      );
   }
   
   public static function thresholdChecker() {
      //static $i = 1;
      throw new AlertinatorCriticalException('foobaz');
      
   }

   /**
    * This is an example of an alert-checking function with an error in it.
    */
   public static function buggyCheck() {
      sdf;
   }

   /**
    * Report an error if the output of `$callable` is not `$expected`. This
    * provides more flexibility over PHPUnit's `expectOutputString()`.
    */
   private function expectOutputEquals($expected, $callable, $params=[]) {
      ob_start();
      call_user_func_array($callable, $params);
      $output = ob_get_contents();
      ob_end_clean();

      $this->assertEquals($expected, $output);
   }
}

